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

// Get and sanitize inputs
$roll_no = isset($_GET['roll_no']) ? $conn->real_escape_string(trim($_GET['roll_no'])) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50; // Default limit to 50 records

if (empty($roll_no)) {
    http_response_code(400);
    echo json_encode(['error' => 'Roll number is required']);
    exit;
}

// Check if student exists
$sql_check = "SELECT id, name, department, section, year FROM students WHERE roll_no = ?";
$stmt_check = $conn->prepare($sql_check);
if ($stmt_check === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Student check query preparation failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt_check->bind_param('s', $roll_no);
$stmt_check->execute();
$student_result = $stmt_check->get_result();

if ($student_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'No student found with roll number: ' . $roll_no]);
    $stmt_check->close();
    $conn->close();
    exit;
}

$student = $student_result->fetch_assoc();
$student_id = $student['id'];

// Query to fetch student attendance
$sql = "
    SELECT 
        s.name, 
        s.roll_no, 
        DATE(a.attendance_time) AS date,
        a.status,
        a.mac_address
    FROM students s
    LEFT JOIN attendance a ON s.id = a.student_id
    WHERE s.roll_no = ? 
    AND a.attendance_time IS NOT NULL
    ORDER BY a.attendance_time DESC
    LIMIT ?
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed: ' . $conn->error]);
    $stmt_check->close();
    $conn->close();
    exit;
}

$stmt->bind_param('si', $roll_no, $limit);
$stmt->execute();
$result = $stmt->get_result();

$attendance = [];
while ($row = $result->fetch_assoc()) {
    // Check if this date is a holiday
    $date_check = $row['date'];
    $holiday_sql = "SELECT status, description FROM holidays WHERE date = ?";
    $holiday_stmt = $conn->prepare($holiday_sql);
    $holiday_stmt->bind_param('s', $date_check);
    $holiday_stmt->execute();
    $holiday_result = $holiday_stmt->get_result();
    
    $status = $row['status'];
    if ($holiday_result->num_rows > 0) {
        $holiday = $holiday_result->fetch_assoc();
        if ($holiday['status'] === 'Holiday' || $holiday['description'] === 'Sunday') {
            $status = 'Holiday';
        }
    }
    $holiday_stmt->close();
    
    $attendance[] = [
        'name' => $row['name'],
        'roll_no' => $row['roll_no'],
        'date' => $row['date'],
        'status' => $status,
        'mac_address' => $row['mac_address'] ?? 'N/A'
    ];
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