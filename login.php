<?php
// login.php - Enhanced Login System
session_start();
require_once 'config.php';

// Function to log login attempts
function logLoginAttempt($username, $success, $ip) {
    $logFile = 'login_attempts.log';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $logEntry = "[$timestamp] $status - Username: $username, IP: $ip\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$username = $_POST['admin'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['error' => 'Username and password are required']);
    exit;
}

// Sanitize input
$username = $conn->real_escape_string(trim($username));
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Check for rate limiting (basic protection)
$rate_limit_file = 'rate_limit_' . md5($client_ip) . '.txt';
if (file_exists($rate_limit_file)) {
    $last_attempt = file_get_contents($rate_limit_file);
    if (time() - $last_attempt < 30) { // 30 second cooldown
        echo json_encode(['error' => 'Too many login attempts. Please wait 30 seconds.']);
        exit;
    }
}

$sql = "SELECT * FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    
    // For this example, using plain password comparison
    // In production, use password_verify() with hashed passwords
    if ($password === $row['password']) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['login_time'] = time();
        
        // Remove rate limit file on successful login
        if (file_exists($rate_limit_file)) {
            unlink($rate_limit_file);
        }
        
        logLoginAttempt($username, true, $client_ip);
        echo json_encode(['status' => 'success', 'message' => 'Login successful']);
    } else {
        // Set rate limit
        file_put_contents($rate_limit_file, time());
        logLoginAttempt($username, false, $client_ip);
        echo json_encode(['error' => 'Invalid password']);
    }
} else {
    // Set rate limit for invalid username too
    file_put_contents($rate_limit_file, time());
    logLoginAttempt($username, false, $client_ip);
    echo json_encode(['error' => 'User not found']);
}

$stmt->close();
$conn->close();
?>