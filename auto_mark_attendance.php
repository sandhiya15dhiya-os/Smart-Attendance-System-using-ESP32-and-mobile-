<?php
// auto_mark_attendance.php - Automated MAC-based Attendance System
session_start();
require_once 'config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Function to get current period based on time
function getCurrentPeriod($current_time = null) {
    if ($current_time === null) {
        $current_time = date('H:i:s');
    }
    
    $periods = [
        1 => ['08:30:00', '09:15:00'],
        2 => ['09:15:00', '10:00:00'],
        3 => ['10:00:00', '10:45:00'],
        4 => ['11:05:00', '11:50:00'], // Break: 10:45-11:05
        5 => ['11:50:00', '12:35:00'],
        6 => ['13:20:00', '14:05:00'], // Lunch: 12:35-13:20
        7 => ['14:05:00', '14:50:00'],
        8 => ['15:05:00', '15:50:00'], // Break: 14:50-15:05
        9 => ['15:50:00', '16:35:00']
    ];
    
    foreach ($periods as $period => $times) {
        if ($current_time >= $times[0] && $current_time <= $times[1]) {
            return $period;
        }
    }
    
    return null; // No active period
}

// Function to get day of week (1=Monday, 7=Sunday)
function getDayOfWeek($date) {
    return date('N', strtotime($date));
}

// Main auto-marking function
function autoMarkAttendance($date = null, $time = null) {
    global $conn;
    
    if ($date === null) {
        $date = date('Y-m-d');
    }
    if ($time === null) {
        $time = date('H:i:s');
    }
    
    $current_period = getCurrentPeriod($time);
    $day_of_week = getDayOfWeek($date);
    
    if ($current_period === null) {
        return ['status' => 'info', 'message' => 'No active period at this time'];
    }
    
    // Check if it's a holiday
    $holiday_check = $conn->prepare("SELECT COUNT(*) as count FROM holidays WHERE date = ?");
    $holiday_check->bind_param('s', $date);
    $holiday_check->execute();
    $holiday_result = $holiday_check->get_result();
    $is_holiday = $holiday_result->fetch_assoc()['count'] > 0;
    $holiday_check->close();
    
    if ($is_holiday) {
        return ['status' => 'info', 'message' => 'Cannot mark attendance on holidays'];
    }
    
    // Get recent MAC addresses (within last 2 minutes for current period)
    $time_threshold = date('Y-m-d H:i:s', strtotime('-2 minutes'));
    $mac_sql = "SELECT DISTINCT mac_address, MAX(captured_at) as latest_capture 
                FROM captured_macs 
                WHERE captured_at >= ? 
                GROUP BY mac_address";
    
    $mac_stmt = $conn->prepare($mac_sql);
    $mac_stmt->bind_param('s', $time_threshold);
    $mac_stmt->execute();
    $mac_result = $mac_stmt->get_result();
    
    $captured_macs = [];
    while ($row = $mac_result->fetch_assoc()) {
        $captured_macs[] = $row['mac_address'];
    }
    $mac_stmt->close();
    
    if (empty($captured_macs)) {
        return ['status' => 'info', 'message' => 'No MAC addresses captured recently'];
    }
    
    // Get students with matching MAC addresses and their timetable
    $mac_placeholders = str_repeat('?,', count($captured_macs) - 1) . '?';
    $student_sql = "SELECT DISTINCT s.id, s.name, s.roll_no, s.mac_address, s.department, s.section, s.year,
                           t.subject_code, t.faculty_name
                    FROM students s
                    INNER JOIN timetable t ON s.department = t.department 
                                           AND s.year = t.year 
                                           AND s.section = t.section
                    WHERE s.mac_address IN ($mac_placeholders)
                    AND t.day_of_week = ?
                    AND t.period = ?
                    AND s.mac_address IS NOT NULL";
    
    $params = array_merge($captured_macs, [$day_of_week, $current_period]);
    $types = str_repeat('s', count($captured_macs)) . 'ii';
    
    $student_stmt = $conn->prepare($student_sql);
    $student_stmt->bind_param($types, ...$params);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    
    $marked_count = 0;
    $updated_count = 0;
    
    while ($student = $student_result->fetch_assoc()) {
        // Check if attendance already exists for this student, date, and period
        $check_sql = "SELECT id, status FROM period_attendance 
                      WHERE student_id = ? AND date = ? AND period = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('isi', $student['id'], $date, $current_period);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing record if it's currently marked as Absent
            $existing = $check_result->fetch_assoc();
            if ($existing['status'] === 'Absent') {
                $update_sql = "UPDATE period_attendance 
                              SET status = 'Present', 
                                  subject_code = ?,
                                  updated_at = CURRENT_TIMESTAMP 
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('si', $student['subject_code'], $existing['id']);
                $update_stmt->execute();
                $update_stmt->close();
                $updated_count++;
            }
        } else {
            // Insert new attendance record
            $insert_sql = "INSERT INTO period_attendance 
                          (student_id, date, period, status, subject_code) 
                          VALUES (?, ?, ?, 'Present', ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param('isis', $student['id'], $date, $current_period, $student['subject_code']);
            $insert_stmt->execute();
            $insert_stmt->close();
            $marked_count++;
        }
        $check_stmt->close();
    }
    
    $student_stmt->close();
    
    return [
        'status' => 'success',
        'message' => "Period $current_period: $marked_count new, $updated_count updated",
        'marked_count' => $marked_count,
        'updated_count' => $updated_count,
        'period' => $current_period
    ];
}

// Handle different request types
$action = $_GET['action'] ?? 'auto';

switch ($action) {
    case 'auto':
        // Automatic marking based on current time
        $result = autoMarkAttendance();
        echo json_encode($result);
        break;
        
    case 'manual':
        // Manual marking for specific date/time
        $date = $_GET['date'] ?? date('Y-m-d');
        $time = $_GET['time'] ?? date('H:i:s');
        $result = autoMarkAttendance($date, $time);
        echo json_encode($result);
        break;
        
    case 'bulk':
        // Bulk process for entire day
        $date = $_GET['date'] ?? date('Y-m-d');
        $results = [];
        
        // Process all 9 periods
        $period_times = [
            1 => '08:30:00', 2 => '09:15:00', 3 => '10:00:00',
            4 => '11:05:00', 5 => '11:50:00', 6 => '13:20:00',
            7 => '14:05:00', 8 => '15:05:00', 9 => '15:50:00'
        ];
        
        foreach ($period_times as $period => $time) {
            $result = autoMarkAttendance($date, $time);
            $results[] = "Period $period: " . $result['message'];
        }
        
        echo json_encode(['status' => 'success', 'results' => $results]);
        break;
        
    case 'status':
        // Get current period and next period info
        $current_period = getCurrentPeriod();
        echo json_encode([
            'current_period' => $current_period,
            'current_time' => date('H:i:s'),
            'date' => date('Y-m-d')
        ]);
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}

$conn->close();
?>

<?php
// get_subject_summary_improved.php - Enhanced Subject Summary with Timetable Integration
session_start();
require_once 'config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$roll_no = $_GET['roll_no'] ?? '';
$month = $_GET['month'] ?? '';

if (empty($roll_no) || empty($month)) {
    http_response_code(400);
    echo json_encode(['error' => 'Roll number and month are required']);
    exit;
}

$roll_no = $conn->real_escape_string($roll_no);
$month = $conn->real_escape_string($month);

// Get student info
$student_sql = "SELECT id, name, department, section, year FROM students WHERE roll_no = ?";
$student_stmt = $conn->prepare($student_sql);
$student_stmt->bind_param('s', $roll_no);
$student_stmt->execute();
$student_result = $student_stmt->get_result();

if ($student_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Student not found']);
    $student_stmt->close();
    $conn->close();
    exit;
}

$student = $student_result->fetch_assoc();
$student_stmt->close();

// Get subjects from database based on student's timetable
$subjects_sql = "SELECT DISTINCT 
                    s.subject_code,
                    s.subject_name,
                    s.faculty_name,
                    s.credits,
                    GROUP_CONCAT(DISTINCT t.period ORDER BY t.period) as periods,
                    COUNT(DISTINCT CONCAT(t.day_of_week, '-', t.period)) as total_periods_per_week
                 FROM subjects s
                 INNER JOIN timetable t ON s.subject_code = t.subject_code
                 WHERE s.department = ? 
                   AND s.year = ? 
                   AND s.section = ?
                   AND t.department = ?
                   AND t.year = ?
                   AND t.section = ?
                 GROUP BY s.subject_code, s.subject_name, s.faculty_name, s.credits";

$subjects_stmt = $conn->prepare($subjects_sql);
$subjects_stmt->bind_param('sissis', 
    $student['department'], $student['year'], $student['section'],
    $student['department'], $student['year'], $student['section']
);
$subjects_stmt->execute();
$subjects_result = $subjects_stmt->get_result();

$subject_summary = [];
$month_pattern = $month . '%';

while ($subject_row = $subjects_result->fetch_assoc()) {
    $subject_code = $subject_row['subject_code'];
    
    // Get all periods for this subject from timetable
    $periods_sql = "SELECT DISTINCT period FROM timetable 
                    WHERE subject_code = ? 
                    AND department = ? 
                    AND year = ? 
                    AND section = ?";
    $periods_stmt = $conn->prepare($periods_sql);
    $periods_stmt->bind_param('ssis', $subject_code, $student['department'], $student['year'], $student['section']);
    $periods_stmt->execute();
    $periods_result = $periods_stmt->get_result();
    
    $periods = [];
    while ($period_row = $periods_result->fetch_assoc()) {
        $periods[] = $period_row['period'];
    }
    $periods_stmt->close();
    
    if (empty($periods)) {
        continue; // Skip if no periods found
    }
    
    $period_list = implode(',', $periods);
    
    // Get attendance for this subject's periods
    $attendance_sql = "SELECT 
                        COUNT(*) as total_classes,
                        SUM(CASE WHEN pa.status = 'Present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN pa.status = 'Absent' THEN 1 ELSE 0 END) as absent,
                        SUM(CASE WHEN pa.status = 'On Duty' THEN 1 ELSE 0 END) as onduty
                       FROM period_attendance pa
                       WHERE pa.student_id = ? 
                       AND pa.date LIKE ? 
                       AND pa.period IN ($period_list)
                       AND pa.subject_code = ?
                       AND pa.date NOT IN (SELECT date FROM holidays WHERE date LIKE ?)";
    
    $attendance_stmt = $conn->prepare($attendance_sql);
    $attendance_stmt->bind_param('isss', $student['id'], $month_pattern, $subject_code, $month_pattern);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    
    if ($row = $attendance_result->fetch_assoc()) {
        $total = $row['total_classes'];
        $present = $row['present'];
        $absent = $row['absent'];
        $onduty = $row['onduty'];
        
        // Calculate expected classes based on timetable
        $expected_classes_sql = "SELECT COUNT(*) as expected
                                FROM (
                                    SELECT DISTINCT pa.date, pa.period
                                    FROM period_attendance pa
                                    INNER JOIN timetable t ON pa.period = t.period
                                    WHERE pa.date LIKE ?
                                    AND t.subject_code = ?
                                    AND t.department = ?
                                    AND t.year = ?
                                    AND t.section = ?
                                    AND DAYOFWEEK(pa.date) - 1 = t.day_of_week
                                    AND pa.date NOT IN (SELECT date FROM holidays WHERE date LIKE ?)
                                ) as expected_classes";
        
        $expected_stmt = $conn->prepare($expected_classes_sql);
        $expected_stmt->bind_param('sssiss', 
            $month_pattern, $subject_code, 
            $student['department'], $student['year'], $student['section'],
            $month_pattern
        );
        $expected_stmt->execute();
        $expected_result = $expected_stmt->get_result();
        $expected_data = $expected_result->fetch_assoc();
        $expected_classes = $expected_data['expected'];
        $expected_stmt->close();
        
        $effective_present = $present + $onduty;
        $percentage = $total > 0 ? round(($effective_present / $total) * 100, 2) : 0;
        
        $subject_summary[] = [
            'subject_code' => $subject_code,
            'subject_name' => $subject_row['subject_name'],
            'total_classes' => $total,
            'expected_classes' => $expected_classes,
            'present' => $present,
            'absent' => $absent,
            'onduty' => $onduty,
            'percentage' => $percentage,
            'faculty_name' => $subject_row['faculty_name'],
            'credits' => $subject_row['credits'],
            'periods' => explode(',', $subject_row['periods']),
            'shortage' => max(0, ($expected_classes * 0.75) - $effective_present) // 75% attendance requirement
        ];
    }
    $attendance_stmt->close();
}

$subjects_stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($subject_summary);
?>

<?php
// get_period_attendance_improved.php - Enhanced Period Attendance with Auto-marking
session_start();
require_once 'config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$date = $_GET['date'] ?? '';
$department = $_GET['department'] ?? '';
$section = $_GET['section'] ?? '';
$auto_mark = $_GET['auto_mark'] ?? 'false';

if (empty($date) || empty($department) || empty($section)) {
    http_response_code(400);
    echo json_encode(['error' => 'Date, department, and section are required']);
    exit;
}

$date = $conn->real_escape_string($date);
$department = $conn->real_escape_string($department);
$section = $conn->real_escape_string($section);

// Auto-mark attendance if requested
if ($auto_mark === 'true') {
    include_once 'auto_mark_attendance.php';
    autoMarkAttendance($date);
}

// Check if date is holiday
$holiday_sql = "SELECT status FROM holidays WHERE date = ?";
$holiday_stmt = $conn->prepare($holiday_sql);
$holiday_stmt->bind_param('s', $date);
$holiday_stmt->execute();
$holiday_result = $holiday_stmt->get_result();
$is_holiday = $holiday_result->num_rows > 0;
$holiday_stmt->close();

if ($is_holiday) {
    echo json_encode(['error' => 'Cannot take attendance on holidays', 'is_holiday' => true]);
    $conn->close();
    exit;
}

// Get day of week for timetable matching
$day_of_week = date('N', strtotime($date)); // 1=Monday, 7=Sunday

// Get students with their period attendance
$sql = "SELECT s.id, s.name, s.roll_no, s.year
        FROM students s 
        WHERE s.department = ? AND s.section = ? 
        ORDER BY s.roll_no";
        
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $department, $section);
$stmt->execute();
$result = $stmt->get_result();

$attendance_data = [];

while ($row = $result->fetch_assoc()) {
    $student_id = $row['id'];
    $student_year = $row['year'];
    
    // Get period attendance for this student
    $period_sql = "SELECT period, status, subject_code FROM period_attendance 
                   WHERE student_id = ? AND date = ?
                   ORDER BY period";
    $period_stmt = $conn->prepare($period_sql);
    $period_stmt->bind_param('is', $student_id, $date);
    $period_stmt->execute();
    $period_result = $period_stmt->get_result();
    
    $periods = [];
    $subjects = [];
    
    while ($period_row = $period_result->fetch_assoc()) {
        $period_num = $period_row['period'];
        $periods['p' . $period_num] = 
            $period_row['status'] === 'Present' ? 'P' : 
            ($period_row['status'] === 'On Duty' ? 'OD' : 'A');
        $subjects['p' . $period_num] = $period_row['subject_code'] ?? '';
    }
    $period_stmt->close();
    
    // Get timetable subjects for missing periods
    for ($i = 1; $i <= 9; $i++) {
        if (!isset($periods['p' . $i])) {
            // Check if there's a subject scheduled for this period
            $timetable_sql = "SELECT subject_code FROM timetable 
                             WHERE department = ? AND year = ? AND section = ? 
                             AND day_of_week = ? AND period = ?";
            $timetable_stmt = $conn->prepare($timetable_sql);
            $timetable_stmt->bind_param('ssiii', $department, $student_year, $section, $day_of_week, $i);
            $timetable_stmt->execute();
            $timetable_result = $timetable_stmt->get_result();
            
            if ($timetable_result->num_rows > 0) {
                $timetable_row = $timetable_result->fetch_assoc();
                $periods['p' . $i] = 'A'; // Default absent
                $subjects['p' . $i] = $timetable_row['subject_code'];
                
                // Insert default absent record
                $insert_absent_sql = "INSERT IGNORE INTO period_attendance 
                                     (student_id, date, period, status, subject_code) 
                                     VALUES (?, ?, ?, 'Absent', ?)";
                $insert_absent_stmt = $conn->prepare($insert_absent_sql);
                $insert_absent_stmt->bind_param('isis', $student_id, $date, $i, $timetable_row['subject_code']);
                $insert_absent_stmt->execute();
                $insert_absent_stmt->close();
            } else {
                $periods['p' . $i] = '-'; // No class scheduled
                $subjects['p' . $i] = '';
            }
            $timetable_stmt->close();
        }
    }
    
    // Calculate daily percentage
    $total_periods = 0;
    $present_periods = 0;
    
    for ($i = 1; $i <= 9; $i++) {
        if ($periods['p' . $i] !== '-') {
            $total_periods++;
            if ($periods['p' . $i] === 'P' || $periods['p' . $i] === 'OD') {
                $present_periods++;
            }
        }
    }
    
    $daily_percentage = $total_periods > 0 ? round(($present_periods / $total_periods) * 100, 2) : 0;
    
    $attendance_data[] = [
        'student_id' => $student_id,
        'name' => $row['name'],
        'roll_no' => $row['roll_no'],
        'periods' => $periods,
        'subjects' => $subjects,
        'daily_percentage' => $daily_percentage,
        'total_periods' => $total_periods,
        'present_periods' => $present_periods
    ];
}

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($attendance_data);
?>

<?php
// cron_auto_attendance.php - Cron job for automated attendance marking
// Add to crontab: */5 * * * * /usr/bin/php /path/to/cron_auto_attendance.php

require_once 'config.php';
require_once 'auto_mark_attendance.php';

// Set up database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Cron Auto Attendance - DB Connection failed: " . $conn->connect_error);
    exit;
}

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] Auto Attendance Cron: $message");
}

// Check if we're in a valid time range (8:30 AM to 4:35 PM)
$current_time = date('H:i:s');
if ($current_time < '08:30:00' || $current_time > '16:35:00') {
    logMessage("Outside attendance hours ($current_time)");
    exit;
}

// Check if today is a holiday
$today = date('Y-m-d');
$holiday_check = $conn->prepare("SELECT COUNT(*) as count FROM holidays WHERE date = ?");
$holiday_check->bind_param('s', $today);
$holiday_check->execute();
$holiday_result = $holiday_check->get_result();
$is_holiday = $holiday_result->fetch_assoc()['count'] > 0;
$holiday_check->close();

if ($is_holiday) {
    logMessage("Today is a holiday, skipping auto-attendance");
    exit;
}

// Check if it's weekend (Saturday=6, Sunday=7)
$day_of_week = date('N');
if ($day_of_week >= 6) {
    logMessage("Weekend day ($day_of_week), skipping auto-attendance");
    exit;
}

// Run auto attendance marking
try {
    $result = autoMarkAttendance();
    logMessage("Auto attendance result: " . json_encode($result));
    
    if ($result['status'] === 'success') {
        logMessage("Successfully marked attendance - Period: {$result['period']}, New: {$result['marked_count']}, Updated: {$result['updated_count']}");
    }
} catch (Exception $e) {
    logMessage("Error in auto attendance: " . $e->getMessage());
}

$conn->close();
logMessage("Cron job completed");
?>