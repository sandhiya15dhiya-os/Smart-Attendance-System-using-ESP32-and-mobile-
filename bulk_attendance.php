<?php
// bulk_attendance.php - For marking attendance for multiple students
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

$date = $_POST['date'] ?? '';
$department = $_POST['department'] ?? '';
$section = $_POST['section'] ?? '';
$period = $_POST['period'] ?? '';
$action = $_POST['action'] ?? ''; // 'mark_all_present' or 'mark_all_absent'

if (empty($date) || empty($department) || empty($section) || empty($period) || empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'All parameters are required']);
    exit;
}

// Check if date is holiday
$holiday_sql = "SELECT status FROM holidays WHERE date = ?";
$holiday_stmt = $conn->prepare($holiday_sql);
$holiday_stmt->bind_param('s', $date);
$holiday_stmt->execute();
$holiday_result = $holiday_stmt->get_result();

if ($holiday_result->num_rows > 0) {
    echo json_encode(['error' => 'Cannot mark attendance on holidays']);
    $holiday_stmt->close();
    $conn->close();
    exit;
}
$holiday_stmt->close();

$status = ($action === 'mark_all_present') ? 'Present' : 'Absent';

// Get all students in the specified class
$student_sql = "SELECT id FROM students WHERE department = ? AND section = ?";
$student_stmt = $conn->prepare($student_sql);
$student_stmt->bind_param('ss', $department, $section);
$student_stmt->execute();
$student_result = $student_stmt->get_result();

$success_count = 0;
$total_count = 0;

while ($student = $student_result->fetch_assoc()) {
    $total_count++;
    $student_id = $student['id'];
    
    // Insert/update period attendance
    $period_sql = "INSERT INTO period_attendance (student_id, date, period, status) 
                   VALUES (?, ?, ?, ?) 
                   ON DUPLICATE KEY UPDATE status = ?";
    $period_stmt = $conn->prepare($period_sql);
    $period_stmt->bind_param('isiss', $student_id, $date, $period, $status, $status);
    
    if ($period_stmt->execute()) {
        $success_count++;
        // Update daily attendance
        updateDailyFromPeriods($conn, $student_id, $date);
    }
    $period_stmt->close();
}

$student_stmt->close();
$conn->close();

if ($success_count === $total_count) {
    echo json_encode(['status' => 'Success', 'message' => "Marked $success_count students as $status"]);
} else {
    echo json_encode(['status' => 'Partial', 'message' => "Marked $success_count out of $total_count students"]);
}

function updateDailyFromPeriods($conn, $student_id, $date) {
    // Call the stored procedure to update daily attendance
    $proc_sql = "CALL UpdateDailyFromPeriods(?, ?)";
    $proc_stmt = $conn->prepare($proc_sql);
    $proc_stmt->bind_param('is', $student_id, $date);
    $proc_stmt->execute();
    $proc_stmt->close();
}
?>