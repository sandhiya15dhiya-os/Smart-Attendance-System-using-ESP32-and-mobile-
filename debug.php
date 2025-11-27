<?php
// Save as: C:/xampp/htdocs/attendance_system/debug.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$response = [
    'success' => true,
    'message' => 'Debug endpoint working',
    'timestamp' => date('Y-m-d H:i:s'),
    'server_info' => [
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown'
    ],
    'php_info' => [
        'version' => phpversion(),
        'post_data' => $_POST,
        'get_data' => $_GET
    ]
];

// Test database connection
try {
    $servername = "localhost";
    $username = "root";
    $password = "@989412musT";
    $dbname = "attendance_db";
    
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        $response['database'] = ['status' => 'failed', 'error' => $conn->connect_error];
    } else {
        $response['database'] = ['status' => 'connected', 'server_info' => $conn->server_info];
        
        // Check if mac_addresses table exists
        $result = $conn->query("SHOW TABLES LIKE 'mac_addresses'");
        if ($result->num_rows > 0) {
            $response['database']['mac_addresses_table'] = 'exists';
            
            // Get table structure
            $structure = $conn->query("DESCRIBE mac_addresses");
            $columns = [];
            while ($row = $structure->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            $response['database']['table_columns'] = $columns;
        } else {
            $response['database']['mac_addresses_table'] = 'missing';
        }
    }
    
    $conn->close();
} catch (Exception $e) {
    $response['database'] = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>