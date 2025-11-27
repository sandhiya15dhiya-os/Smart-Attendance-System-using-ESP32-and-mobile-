<?php
// update_period_attendance.php
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

$student_id = $_POST['student_id'] ?? '';
$date = $_POST['date'] ?? '';
$period = $_POST['period'] ?? '';
$status = $_POST['status'] ?? '';

if (empty($student_id) || empty($date) || empty($period) || empty($status)) {
    http_response_code(400);
    echo json_encode(['error' => 'All parameters are required']);
    exit;
}

$student_id = $conn->real_escape_string($student_id);
$date = $conn->real_escape_string($date);
$period = $conn->real_escape_string($period);
$status = $conn->real_escape_string($status);

// Update period attendance
$sql = "INSERT INTO period_attendance (student_id, date, period, status) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE status = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('isiss', $student_id, $date, $period, $status, $status);

if ($stmt->execute()) {
    // Update daily attendance based on period attendance
    updateDailyAttendance($conn, $student_id, $date);
    echo json_encode(['status' => 'Success']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update attendance']);
}

$stmt->close();
$conn->close();

function updateDailyAttendance($conn, $student_id, $date) {
    // Get all period attendance for this student on this date
    $sql = "SELECT status FROM period_attendance 
            WHERE student_id = ? AND date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $student_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $present_count = 0;
    $absent_count = 0;
    $onduty_count = 0;
    $total_periods = 0;
    
    while ($row = $result->fetch_assoc()) {
        $total_periods++;
        switch ($row['status']) {
            case 'Present':
                $present_count++;
                break;
            case 'Absent':
                $absent_count++;
                break;
            case 'On Duty':
                $onduty_count++;
                break;
        }
    }
    $stmt->close();
    
    // Determine daily status
    $daily_status = 'Absent';
    if ($onduty_count > 0) {
        $daily_status = 'On Duty';
    } else if ($total_periods > 0) {
        $attendance_percentage = ($present_count / $total_periods) * 100;
        if ($attendance_percentage >= 50) {
            $daily_status = 'Present';
        }
    }
    
    // Update daily attendance
    $update_sql = "INSERT INTO attendance (student_id, date, status) 
                   VALUES (?, ?, ?) 
                   ON DUPLICATE KEY UPDATE status = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param('isss', $student_id, $date, $daily_status, $daily_status);
    $update_stmt->execute();
    $update_stmt->close();
}
?>