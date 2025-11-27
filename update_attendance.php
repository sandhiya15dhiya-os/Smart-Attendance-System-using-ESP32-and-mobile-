<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Establish database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Get and sanitize roll number
$roll_no = $_GET['roll_no'] ?? '';
$roll_no = $conn->real_escape_string(trim($roll_no));

if (empty($roll_no)) {
    http_response_code(400);
    echo json_encode(['error' => 'Roll number is required']);
    exit;
}

// Query to fetch student attendance
$sql = "
    SELECT s.name, s.roll_no, a.date, COALESCE(a.status, 'Not Recorded') AS status
    FROM students s
    LEFT JOIN attendance a ON s.id = a.student_id
    WHERE s.roll_no = ?
    ORDER BY a.date DESC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param('s', $roll_no);
$stmt->execute();
$result = $stmt->get_result();

$attendance = [];
while ($row = $result->fetch_assoc()) {
    $attendance[] = $row;
}

// Check if student exists
$sql_check = "SELECT 1 FROM students WHERE roll_no = ?";
$stmt_check = $conn->prepare($sql_check);
if ($stmt_check === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Student check query preparation failed: ' . $conn->error]);
    $stmt->close();
    $conn->close();
    exit;
}

$stmt_check->bind_param('s', $roll_no);
$stmt_check->execute();
$student_exists = $stmt_check->get_result()->num_rows > 0;

if (!$student_exists) {
    http_response_code(404);
    echo json_encode(['error' => 'No student found with roll number: ' . $roll_no]);
    $stmt->close();
    $stmt_check->close();
    $conn->close();
    exit;
}

// Return data or a message if no records are found
header('Content-Type: application/json');
if (empty($attendance)) {
    echo json_encode(['message' => 'No attendance records found for roll number: ' . $roll_no]);
} else {
    echo json_encode($attendance);
}

$stmt->close();
$stmt_check->close();
$conn->close();
?>