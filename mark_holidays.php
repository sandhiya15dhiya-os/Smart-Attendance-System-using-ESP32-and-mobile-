<?php
require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$date = date('Y-m-d');

// Check if the date is a holiday or Sunday
$sql_holiday = "SELECT status, description FROM holidays WHERE date = ?";
$stmt_holiday = $conn->prepare($sql_holiday);
$stmt_holiday->bind_param('s', $date);
$stmt_holiday->execute();
$result_holiday = $stmt_holiday->get_result();
$is_holiday = false;
if ($result_holiday->num_rows > 0) {
    $holiday = $result_holiday->fetch_assoc();
    if ($holiday['status'] === 'Holiday' || $holiday['description'] === 'Sunday') {
        $is_holiday = true;
    }
}
$stmt_holiday->close();

if ($is_holiday) {
    // Mark all students as Holiday
    $sql = "INSERT INTO attendance (student_id, date, status)
            SELECT id, ?, 'Holiday' FROM students
            ON DUPLICATE KEY UPDATE status = 'Holiday'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->close();
}

$conn->close();
?>