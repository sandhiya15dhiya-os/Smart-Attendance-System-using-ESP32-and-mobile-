<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow requests from ESP32 (optional, for CORS)
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration - Update if needed (assuming default XAMPP setup)
$host = '127.0.0.1';
$username = 'root'; // Default XAMPP MySQL user
$password = '@989412musT'; // Default XAMPP MySQL password (empty)
$database = 'attendance_db';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Note: Your table 'mac_addresses' currently has columns: id (auto-increment), mac, timestamp.
// But your ESP32 code sends 'rssi' too. To store RSSI, run this SQL in phpMyAdmin to add the column:
// ALTER TABLE `mac_addresses` ADD COLUMN `rssi` INT AFTER `mac`;
// If you don't want RSSI, comment out the rssi parts below.

// Get action from POST
$action = $_POST['action'] ?? '';

if ($action === 'store_mac') {
    $mac = $_POST['mac'] ?? '';
    $rssi = intval($_POST['rssi'] ?? 0); // Convert to int, default 0 if missing

    if (empty($mac)) {
        echo json_encode(['success' => false, 'error' => 'MAC address is required']);
        exit();
    }

    // Prepare and execute insert statement (using prepared statement for security)
    $stmt = $conn->prepare("INSERT INTO mac_addresses (mac, rssi, timestamp) VALUES (?, ?, NOW())");
    // If no rssi column, use: INSERT INTO mac_addresses (mac, timestamp) VALUES (?, NOW())
    // And bind only $mac: $stmt->bind_param("s", $mac);

    $stmt->bind_param("si", $mac, $rssi); // s for string (mac), i for int (rssi)
    $result = $stmt->execute();

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'MAC stored successfully', 'inserted_id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Insert failed: ' . $stmt->error]);
    }

    $stmt->close();
} elseif ($action === 'mark_attendance') {
    // Optional: Handle mark_attendance if you want to extend later.
    // This assumes you have a 'students' table with columns: id, roll_no, name, mac_address.
    // And an 'attendance' table with: id, student_id, date, status (present/absent).
    // For now, this is a placeholder - it returns success without doing anything.
    // To implement fully, you'd split detected_macs by comma, match against students, update attendance, and return counts/lists.

    $detected_macs = $_POST['detected_macs'] ?? '';

    // Placeholder response (extend as needed)
    echo json_encode([
        'success' => true,
        'message' => 'Attendance marked (placeholder)',
        'present_count' => 0,
        'absent_count' => 0,
        'present_students' => []
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

$conn->close();
?>