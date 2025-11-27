<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
$department = $_GET['department'] ?? '';
$section = $_GET['section'] ?? '';

$date = $conn->real_escape_string($date);

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

$sql = "SELECT s.name, s.roll_no, s.mac_address, s.department, s.section, s.year, a.status
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id AND DATE(a.attendance_time) = '$date'
        WHERE 1=1";

if ($department) $sql .= " AND s.department = '" . $conn->real_escape_string($department) . "'";
if ($section) $sql .= " AND s.section = '" . $conn->real_escape_string($section) . "'";

$result = $conn->query($sql);
$attendance = [];

while ($row = $result->fetch_assoc()) {
    if ($is_holiday && !$row['status']) {
        $row['status'] = 'Holiday';
    }
    $attendance[] = $row;
}

header('Content-Type: application/json');
echo json_encode($attendance);
$conn->close();
?>