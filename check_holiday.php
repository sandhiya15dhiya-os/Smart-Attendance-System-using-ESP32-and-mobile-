<?php
require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
$date = $conn->real_escape_string($date);

$sql = "SELECT status, description FROM holidays WHERE date = '$date'";
$result = $conn->query($sql);

$response = ['isHoliday' => false];
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $response = [
        'isHoliday' => true,
        'status' => $row['status'],
        'description' => $row['description']
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();
?>