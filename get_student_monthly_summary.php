<?php
session_start();
require_once 'config.php';

// Log to file for debugging
$logFile = 'summary_log.txt';
$currentTime = date('[Y-m-d H:i:s] ') . " (09:46 AM IST, Friday, September 12, 2025)";
file_put_contents($logFile, $currentTime . " Processing request for roll_no: {$_GET['roll_no']}, month: {$_GET['month']}\n", FILE_APPEND);

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    file_put_contents($logFile, $currentTime . " Unauthorized access\n", FILE_APPEND);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Establish database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    file_put_contents($logFile, $currentTime . " Database connection failed: " . $conn->connect_error . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

file_put_contents($logFile, $currentTime . " Database connected successfully\n", FILE_APPEND);

// Get and sanitize inputs
$roll_no = $_GET['roll_no'] ?? '';
$month = $_GET['month'] ?? ''; // Expected format: YYYY-MM (e.g., 2025-09)

if (empty($roll_no) || empty($month)) {
    file_put_contents($logFile, $currentTime . " Missing roll_no or month\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Roll number and month are required']);
    exit;
}

$roll_no = $conn->real_escape_string(trim($roll_no));
$month = $conn->real_escape_string(trim($month));

file_put_contents($logFile, $currentTime . " Sanitized inputs: roll_no=$roll_no, month=$month\n", FILE_APPEND);

// Validate month format (YYYY-MM)
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    file_put_contents($logFile, $currentTime . " Invalid month format\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid month format. Use YYYY-MM']);
    exit;
}

// Check if student exists
$sql_check = "SELECT id, name FROM students WHERE roll_no = ?";
$stmt_check = $conn->prepare($sql_check);
if ($stmt_check === false) {
    file_put_contents($logFile, $currentTime . " Student check query preparation failed: " . $conn->error . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Student check query preparation failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt_check->bind_param('s', $roll_no);
$stmt_check->execute();
$result_check = $stmt_check->get_result();
if ($result_check->num_rows === 0) {
    file_put_contents($logFile, $currentTime . " No student found with roll_no: $roll_no\n", FILE_APPEND);
    http_response_code(404);
    echo json_encode(['error' => 'No student found with roll number: ' . $roll_no]);
    $stmt_check->close();
    $conn->close();
    exit;
}

$student = $result_check->fetch_assoc();
$student_id = $student['id'];
$student_name = $student['name'];
$stmt_check->close();

file_put_contents($logFile, $currentTime . " Student found: id=$student_id, name=$student_name\n", FILE_APPEND);

// Count holidays in the month (if holidays table exists)
$holiday_count = 0;
$table_check = $conn->query("SHOW TABLES LIKE 'holidays'");
if ($table_check->num_rows > 0) {
    $sql_holiday = "SELECT COUNT(*) AS holiday_count FROM holidays WHERE date LIKE ?";
    $month_pattern = $month . '%';
    $stmt_holiday = $conn->prepare($sql_holiday);
    $stmt_holiday->bind_param('s', $month_pattern);
    $stmt_holiday->execute();
    $result_holiday = $stmt_holiday->get_result();
    $holiday_count = $result_holiday->fetch_assoc()['holiday_count'];
    $stmt_holiday->close();
} else {
    $sql_holiday = "SELECT COUNT(DISTINCT DATE(attendance_time)) AS holiday_count 
                   FROM attendance 
                   WHERE student_id = ? AND status = 'Holiday' 
                   AND DATE_FORMAT(attendance_time, '%Y-%m') = ?";
    $stmt_holiday = $conn->prepare($sql_holiday);
    $stmt_holiday->bind_param('is', $student_id, $month);
    $stmt_holiday->execute();
    $result_holiday = $stmt_holiday->get_result();
    $holiday_count = $result_holiday->fetch_assoc()['holiday_count'];
    $stmt_holiday->close();
}

file_put_contents($logFile, $currentTime . " Holiday count: $holiday_count\n", FILE_APPEND);

// Calculate total working days in the month (excluding Sundays and holidays)
$year = intval(substr($month, 0, 4));
$month_num = intval(substr($month, 5, 2));
$total_days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);

// Count Sundays in the month
$sundays = 0;
for ($day = 1; $day <= $total_days_in_month; $day++) {
    $date = mktime(0, 0, 0, $month_num, $day, $year);
    if (date('w', $date) == 0) { // 0 = Sunday
        $sundays++;
    }
}

$working_days = $total_days_in_month - $sundays - $holiday_count;

file_put_contents($logFile, $currentTime . " Total days: $total_days_in_month, Sundays: $sundays, Holidays: $holiday_count, Working days: $working_days\n", FILE_APPEND);

// Auto-mark absent for unmarked working days (only for past dates)
$current_date = date('Y-m-d');
$month_end = $year . '-' . str_pad($month_num, 2, '0', STR_PAD_LEFT) . '-' . str_pad($total_days_in_month, 2, '0', STR_PAD_LEFT);

if ($month_end <= $current_date || $month < date('Y-m')) {
    file_put_contents($logFile, $currentTime . " Auto-marking absent for unmarked days\n", FILE_APPEND);
    
    $working_dates = [];
    for ($day = 1; $day <= $total_days_in_month; $day++) {
        $check_date = $year . '-' . str_pad($month_num, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        $day_of_week = date('w', strtotime($check_date));
        
        if ($day_of_week == 0) continue; // Skip Sundays
        
        $is_holiday = false;
        if ($table_check->num_rows > 0) {
            $holiday_check = $conn->prepare("SELECT COUNT(*) as count FROM holidays WHERE date = ?");
            $holiday_check->bind_param('s', $check_date);
            $holiday_check->execute();
            $holiday_result = $holiday_check->get_result();
            $is_holiday = ($holiday_result->fetch_assoc()['count'] > 0);
            $holiday_check->close();
        }
        
        if (!$is_holiday) {
            $working_dates[] = $check_date;
        }
    }
    
    foreach ($working_dates as $work_date) {
        if ($work_date <= $current_date) {
            $attendance_exists = $conn->prepare("SELECT COUNT(*) as count FROM attendance WHERE student_id = ? AND DATE(attendance_time) = ?");
            $attendance_exists->bind_param('is', $student_id, $work_date);
            $attendance_exists->execute();
            $exists_result = $attendance_exists->get_result();
            $exists = ($exists_result->fetch_assoc()['count'] > 0);
            $attendance_exists->close();
            
            if (!$exists) {
                $mark_absent = $conn->prepare("INSERT INTO attendance (student_id, status, attendance_time) VALUES (?, 'Absent', ?)");
                $absent_timestamp = $work_date . ' 23:59:59';
                $mark_absent->bind_param('is', $student_id, $absent_timestamp);
                
                if ($mark_absent->execute()) {
                    file_put_contents($logFile, $currentTime . " Marked absent for date: $work_date\n", FILE_APPEND);
                } else {
                    file_put_contents($logFile, $currentTime . " Failed to mark absent for date: $work_date - " . $mark_absent->error . "\n", FILE_APPEND);
                }
                $mark_absent->close();
            }
        }
    }
}

// Query to fetch attendance summary for the month
$sql = "
    SELECT 
        s.name,
        s.roll_no,
        COUNT(CASE WHEN a.status = 'Present' THEN 1 END) AS present_count,
        COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) AS absent_count,
        COUNT(CASE WHEN a.status = 'On Duty' THEN 1 END) AS onduty_count,
        COUNT(CASE WHEN a.status = 'Holiday' THEN 1 END) AS attendance_holiday_count
    FROM students s
    LEFT JOIN attendance a ON s.id = a.student_id 
        AND DATE_FORMAT(a.attendance_time, '%Y-%m') = ?
    WHERE s.roll_no = ?
    GROUP BY s.id, s.name, s.roll_no
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    file_put_contents($logFile, $currentTime . " Query preparation failed: " . $conn->error . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param('ss', $month, $roll_no);
$stmt->execute();
$result = $stmt->get_result();

$summary = [];
if ($row = $result->fetch_assoc()) {
    $present_count = intval($row['present_count']);
    $absent_count = intval($row['absent_count']);
    $onduty_count = intval($row['onduty_count']);
    $attendance_holiday_count = intval($row['attendance_holiday_count']);
    
    // Calculate effective present days (Present + On Duty)
    $effective_present = $present_count + $onduty_count;
    
    // Calculate attendance percentage using the new formula: (present + on_duty) / (working_days - holiday_count) * 100
    $denominator = $working_days - $holiday_count;
    $percent = ($denominator > 0) ? round(($effective_present / $denominator) * 100, 2) : 0.00;
    
    $summary = [
        'name' => $row['name'],
        'roll_no' => $row['roll_no'],
        'present_count' => $present_count,
        'absent_count' => $absent_count,
        'onduty_count' => $onduty_count,
        'holiday_count' => $attendance_holiday_count,
        'percent' => $percent
    ];
    
    file_put_contents($logFile, $currentTime . " Summary generated: " . json_encode($summary) . "\n", FILE_APPEND);
} else {
    // No attendance records found for this student in this month
    $summary = [
        'name' => $student_name,
        'roll_no' => $roll_no,
        'present_count' => 0,
        'absent_count' => $working_days,
        'onduty_count' => 0,
        'holiday_count' => $holiday_count,
        'percent' => 0.00
    ];
    
    file_put_contents($logFile, $currentTime . " No attendance data, using default summary: " . json_encode($summary) . "\n", FILE_APPEND);
}

// Optional: Include daily attendance details (not currently used in HTML but kept for extensibility)
$sql_detailed = "
    SELECT 
        DATE(a.attendance_time) as attendance_date,
        a.status,
        a.mac_address,
        TIME(a.attendance_time) as attendance_time
    FROM attendance a
    INNER JOIN students s ON a.student_id = s.id
    WHERE s.roll_no = ? 
    AND DATE_FORMAT(a.attendance_time, '%Y-%m') = ?
    ORDER BY a.attendance_time ASC
";

$stmt_detailed = $conn->prepare($sql_detailed);
$stmt_detailed->bind_param('ss', $roll_no, $month);
$stmt_detailed->execute();
$result_detailed = $stmt_detailed->get_result();

$daily_attendance = [];
while ($row_detailed = $result_detailed->fetch_assoc()) {
    $daily_attendance[] = [
        'date' => $row_detailed['attendance_date'],
        'status' => $row_detailed['status'],
        'mac_address' => $row_detailed['mac_address'],
        'time' => $row_detailed['attendance_time']
    ];
}

$summary['daily_attendance'] = $daily_attendance;

// Return data
header('Content-Type: application/json');
echo json_encode($summary, JSON_PRETTY_PRINT);

$stmt->close();
$stmt_detailed->close();
$conn->close();
file_put_contents($logFile, $currentTime . " Request processed successfully\n\n", FILE_APPEND);
?>