<?php
// Database configuration
$host = '127.0.0.1';
$dbname = 'attendance_db';
$username = 'root';
$password = '@989412musT';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Parameters
    $target_date = '2025-10-06'; // Change this to your target date
    $period_number = 1; // Change this to the period you want to mark (1-9)
    
    // Optional: Specify time range for captured_macs (if you want to limit by time)
    $start_time = '03:30:00'; // e.g., start of period
    $end_time = '04:30:00';   // e.g., end of period
    
    echo "Processing attendance for Date: $target_date, Period: $period_number\n";
    echo str_repeat("-", 60) . "\n";
    
    // Step 1: Get all students with MAC addresses
    $stmt = $pdo->prepare("
        SELECT id, name, roll_no, mac_address, department, section, year 
        FROM students 
        WHERE mac_address IS NOT NULL
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total students with MAC addresses: " . count($students) . "\n\n";
    
    // Step 2: Check each student's attendance
    $present_count = 0;
    $absent_count = 0;
    
    foreach ($students as $student) {
        $mac = strtoupper($student['mac_address']);
        
        // Check if this MAC address was captured on the target date (with optional time range)
        $query = "
            SELECT COUNT(*) as capture_count 
            FROM captured_macs 
            WHERE UPPER(mac_address) = :mac 
            AND DATE(captured_at) = :target_date
        ";
        
        // Add time range if specified
        if ($start_time && $end_time) {
            $query .= " AND TIME(captured_at) BETWEEN :start_time AND :end_time";
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':mac', $mac);
        $stmt->bindParam(':target_date', $target_date);
        
        if ($start_time && $end_time) {
            $stmt->bindParam(':start_time', $start_time);
            $stmt->bindParam(':end_time', $end_time);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $was_present = $result['capture_count'] > 0;
        $status = $was_present ? 'Present' : 'Absent';
        
        // Step 3: Insert or update attendance record
        $insert_query = "
            INSERT INTO period_attendance 
            (student_id, date, period, status, created_at, updated_at)
            VALUES 
            (:student_id, :date, :period, :status, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
            status = :status_update,
            updated_at = NOW()
        ";
        
        $stmt = $pdo->prepare($insert_query);
        $stmt->bindParam(':student_id', $student['id']);
        $stmt->bindParam(':date', $target_date);
        $stmt->bindParam(':period', $period_number);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':status_update', $status);
        $stmt->execute();
        
        if ($was_present) {
            $present_count++;
            echo "✓ {$student['name']} ({$student['roll_no']}) - PRESENT\n";
        } else {
            $absent_count++;
            echo "✗ {$student['name']} ({$student['roll_no']}) - ABSENT\n";
        }
    }
    
    echo "\n" . str_repeat("-", 60) . "\n";
    echo "Summary:\n";
    echo "Present: $present_count\n";
    echo "Absent: $absent_count\n";
    echo "Total: " . count($students) . "\n";
    echo "\nAttendance records have been saved to period_attendance table.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>