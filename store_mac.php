<?php
// store_mac.php - ESP32 Attendance System Data Handler
// Final corrected version for XAMPP

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, Authorization');

// Handle preflight CORS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enhanced logging function with file locking
function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents('debug.log', $logEntry, FILE_APPEND | LOCK_EX);
}

// Start logging
debugLog("=== NEW REQUEST STARTED ===");
debugLog("Request Method: " . $_SERVER['REQUEST_METHOD']);
debugLog("Request URI: " . $_SERVER['REQUEST_URI']);
debugLog("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Not provided'));
debugLog("Content Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'Not provided'));
debugLog("Remote Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));

// Database configuration for XAMPP
$servername = "localhost";
$username = "root";
$password = "@989412musT";
$dbname = "attendance_db";

// Handle GET requests (for testing and status)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    debugLog("Processing GET request");
    
    try {
        // Test database connection
        $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get statistics
        $totalRecords = $pdo->query("SELECT COUNT(*) FROM captured_macs")->fetchColumn();
        $recentRecords = $pdo->query("SELECT COUNT(*) FROM captured_macs WHERE captured_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        $uniqueDevices = $pdo->query("SELECT COUNT(DISTINCT device_id) FROM captured_macs WHERE device_id IS NOT NULL")->fetchColumn();
        $lastRecord = $pdo->query("SELECT captured_at FROM captured_macs ORDER BY id DESC LIMIT 1")->fetchColumn();
        
        $response = [
            'status' => 'server_running',
            'message' => 'ESP32 Attendance System Server',
            'version' => '2.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'database' => [
                'status' => 'connected',
                'total_records' => (int)$totalRecords,
                'recent_records' => (int)$recentRecords,
                'unique_devices' => (int)$uniqueDevices,
                'last_record' => $lastRecord
            ],
            'server_info' => [
                'php_version' => PHP_VERSION,
                'server_time' => time(),
                'memory_usage' => memory_get_usage(true),
                'max_execution_time' => ini_get('max_execution_time')
            ]
        ];
        
        debugLog("GET request successful - returning status");
        echo json_encode($response, JSON_PRETTY_PRINT);
        
    } catch(PDOException $e) {
        debugLog("Database error in GET request: " . $e->getMessage());
        
        http_response_code(500);
        echo json_encode([
            'status' => 'server_running',
            'database' => 'error',
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
    }
    
    debugLog("GET request completed");
    exit;
}

// Only accept POST requests for data storage
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debugLog("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'message' => 'Only POST requests are accepted for data storage',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Get raw POST data
$rawInput = file_get_contents('php://input');
$inputLength = strlen($rawInput);

debugLog("POST request received");
debugLog("Raw input length: $inputLength bytes");

if ($inputLength === 0) {
    debugLog("Empty POST body received");
    http_response_code(400);
    echo json_encode([
        'error' => 'Empty request body',
        'message' => 'No data received in POST request',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Log first 500 characters for debugging
if ($inputLength > 500) {
    debugLog("Input preview (500 chars): " . substr($rawInput, 0, 500) . "...");
} else {
    debugLog("Full input: " . $rawInput);
}

// Parse JSON data
$data = json_decode($rawInput, true);
$jsonError = json_last_error();

if ($jsonError !== JSON_ERROR_NONE) {
    debugLog("JSON parsing failed: " . json_last_error_msg() . " (Error code: $jsonError)");
    
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid JSON',
        'message' => json_last_error_msg(),
        'json_error_code' => $jsonError,
        'input_length' => $inputLength,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

debugLog("JSON parsed successfully");

// Validate required structure
if (!is_array($data)) {
    debugLog("Data is not an array");
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid data format',
        'message' => 'Expected JSON object, got ' . gettype($data),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if (!isset($data['macs']) || !is_array($data['macs'])) {
    debugLog("Missing or invalid 'macs' field");
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required field',
        'message' => 'The "macs" field is required and must be an array',
        'received_keys' => array_keys($data),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

$macCount = count($data['macs']);
$deviceId = isset($data['device_id']) ? trim($data['device_id']) : 'UNKNOWN';
$wifiRssi = isset($data['wifi_rssi']) ? (int)$data['wifi_rssi'] : null;
$espTimestamp = isset($data['esp_timestamp']) ? (int)$data['esp_timestamp'] : (time() * 1000);

debugLog("Processing $macCount MAC addresses from device: $deviceId");
debugLog("ESP timestamp: $espTimestamp, WiFi RSSI: " . ($wifiRssi ?? 'null'));

// Validate MAC count
if ($macCount === 0) {
    debugLog("Empty MAC array received");
    http_response_code(400);
    echo json_encode([
        'error' => 'No MAC addresses provided',
        'message' => 'The macs array cannot be empty',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if ($macCount > 100) {
    debugLog("Too many MACs received: $macCount");
    http_response_code(413);
    echo json_encode([
        'error' => 'Too many MAC addresses',
        'message' => "Maximum 100 MAC addresses allowed per request, received $macCount",
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

try {
    // Connect to database
    debugLog("Connecting to database...");
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    debugLog("Database connection successful");
    
    // Verify table exists and has correct structure
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'captured_macs'")->rowCount();
    if ($tableCheck === 0) {
        throw new Exception("Table 'captured_macs' does not exist in database '$dbname'");
    }
    
    // Check table structure (especially AUTO_INCREMENT)
    $structure = $pdo->query("SHOW COLUMNS FROM captured_macs WHERE Field = 'id'")->fetch(PDO::FETCH_ASSOC);
    if (!$structure || strpos($structure['Extra'], 'auto_increment') === false) {
        debugLog("WARNING: ID column may not have AUTO_INCREMENT");
    }
    
    debugLog("Table verification complete");
    
    // Get initial record count
    $initialCount = $pdo->query("SELECT COUNT(*) FROM captured_macs")->fetchColumn();
    debugLog("Initial record count: $initialCount");
    
    // Prepare insert statement
    $insertSQL = "INSERT INTO captured_macs (mac_address, rssi, channel, esp32_timestamp, device_id, wifi_rssi) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($insertSQL);
    
    debugLog("Insert statement prepared");
    
    // Initialize counters
    $insertedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    $errors = [];
    
    // Start transaction for better performance and consistency
    $pdo->beginTransaction();
    debugLog("Transaction started");
    
    // Process each MAC address
    foreach ($data['macs'] as $index => $macData) {
        try {
            // Validate required fields for this MAC
            $requiredFields = ['mac', 'rssi', 'channel', 'timestamp'];
            $missingFields = [];
            
            foreach ($requiredFields as $field) {
                if (!isset($macData[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                throw new Exception("Missing required fields: " . implode(', ', $missingFields));
            }
            
            // Extract and validate MAC address
            $macAddress = strtoupper(trim($macData['mac']));
            
            // Validate MAC address format (XX:XX:XX:XX:XX:XX)
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $macAddress)) {
                throw new Exception("Invalid MAC address format: " . $macData['mac']);
            }
            
            // Skip obviously invalid MACs
            if ($macAddress === 'FF:FF:FF:FF:FF:FF' || $macAddress === '00:00:00:00:00:00') {
                debugLog("Skipping invalid MAC: $macAddress");
                $skippedCount++;
                continue;
            }
            
            // Extract and validate other fields
            $rssi = (int)$macData['rssi'];
            $channel = (int)$macData['channel'];
            $timestamp = (int)$macData['timestamp'];
            
            // Validate ranges (with warnings for unusual values)
            if ($rssi > 0 || $rssi < -120) {
                debugLog("WARNING: Unusual RSSI value: $rssi for MAC: $macAddress");
            }
            
            if ($channel < 1 || $channel > 14) {
                debugLog("WARNING: Invalid WiFi channel: $channel for MAC: $macAddress");
                // Don't skip, just log warning
            }
            
            if ($timestamp <= 0) {
                debugLog("WARNING: Invalid timestamp: $timestamp for MAC: $macAddress");
                $timestamp = time() * 1000; // Use current time
            }
            
            // Execute the insert
            $success = $stmt->execute([
                $macAddress,
                $rssi,
                $channel,
                $timestamp,
                $deviceId,
                $wifiRssi
            ]);
            
            if ($success) {
                $insertedCount++;
                $lastId = $pdo->lastInsertId();
                
                // Log every 10th insert to avoid spam
                if ($insertedCount % 10 === 0 || $insertedCount <= 5) {
                    debugLog("Inserted MAC: $macAddress (ID: $lastId, RSSI: $rssi, CH: $channel)");
                }
            } else {
                throw new Exception("Insert operation returned false");
            }
            
        } catch (PDOException $e) {
            $errorCount++;
            $errorMsg = "Database error for MAC #" . ($index + 1) . " (" . ($macData['mac'] ?? 'unknown') . "): " . $e->getMessage();
            debugLog($errorMsg);
            
            // Check if it's a duplicate key error
            if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                $skippedCount++;
                debugLog("Duplicate entry detected, continuing...");
                continue; // Don't count as error for duplicates
            } else {
                $errors[] = $errorMsg;
            }
            
        } catch (Exception $e) {
            $errorCount++;
            $errorMsg = "Error processing MAC #" . ($index + 1) . ": " . $e->getMessage();
            debugLog($errorMsg);
            $errors[] = $errorMsg;
        }
    }
    
    // Commit transaction
    $pdo->commit();
    debugLog("Transaction committed successfully");
    
    // Get final statistics
    $finalCount = $pdo->query("SELECT COUNT(*) FROM captured_macs")->fetchColumn();
    $actualInserted = $finalCount - $initialCount;
    
    debugLog("Processing complete:");
    debugLog("  - Attempted: $macCount");
    debugLog("  - Inserted: $insertedCount");
    debugLog("  - Actual DB increase: $actualInserted");
    debugLog("  - Skipped: $skippedCount");
    debugLog("  - Errors: $errorCount");
    
    // Prepare response
    $response = [
        'status' => 'success',
        'message' => 'Data processed successfully',
        'data' => [
            'total_received' => $macCount,
            'successfully_inserted' => $insertedCount,
            'actual_db_increase' => (int)$actualInserted,
            'skipped' => $skippedCount,
            'errors' => $errorCount
        ],
        'database' => [
            'total_records' => (int)$finalCount,
            'records_before' => (int)$initialCount
        ],
        'device_info' => [
            'device_id' => $deviceId,
            'wifi_rssi' => $wifiRssi,
            'esp_timestamp' => $espTimestamp
        ],
        'processing' => [
            'processing_time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3),
            'memory_used' => memory_get_usage(true),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    // Add error details if there were errors (limit to first 5)
    if (!empty($errors)) {
        $response['error_details'] = array_slice($errors, 0, 5);
        if (count($errors) > 5) {
            $response['error_note'] = 'Showing first 5 errors only. Total errors: ' . count($errors);
        }
    }
    
    // Set appropriate HTTP status code
    if ($insertedCount > 0) {
        http_response_code(200);
        debugLog("SUCCESS: Request completed successfully with $insertedCount insertions");
    } else if ($errorCount === 0) {
        http_response_code(202); // Accepted but no new data
        $response['message'] = 'Request processed but no new records were inserted';
        debugLog("INFO: Request processed but no new data inserted");
    } else {
        http_response_code(207); // Multi-status (some succeeded, some failed)
        $response['message'] = 'Request partially processed with some errors';
        debugLog("WARNING: Request completed with errors");
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    // Rollback transaction if it exists
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
        debugLog("Transaction rolled back due to error");
    }
    
    debugLog("DATABASE ERROR: " . $e->getMessage());
    debugLog("Error code: " . $e->getCode());
    debugLog("SQL State: " . ($e->errorInfo[0] ?? 'unknown'));
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => 'Database operation failed',
        'details' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    debugLog("GENERAL ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} finally {
    debugLog("=== REQUEST COMPLETED ===\n");
}
?>