<?php
// mark_period_onduty.php
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$dbname = 'attendance_db';
$username = 'root';
$password = '@989412musT';

try {
    // Create database connection
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get POST parameters
    $roll_no = isset($_POST['roll_no']) ? trim($_POST['roll_no']) : '';
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    
    // Validate inputs
    if (empty($roll_no) || empty($date)) {
        echo json_encode([
            'status' => 'Error',
            'error' => 'Roll number and date are required'
        ]);
        exit;
    }
    
    // Get student_id from students table
    $stmt = $conn->prepare("SELECT id FROM students WHERE roll_no = :roll_no");
    $stmt->execute(['roll_no' => $roll_no]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'status' => 'Error',
            'error' => 'Student not found'
        ]);
        exit;
    }
    
    $student_id = $student['id'];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Update or insert attendance for all 9 periods
    $success_count = 0;
    for ($period = 1; $period <= 9; $period++) {
        // Check if record exists
        $check_stmt = $conn->prepare("
            SELECT id FROM period_attendance 
            WHERE student_id = :student_id 
            AND date = :date 
            AND period = :period
        ");
        $check_stmt->execute([
            'student_id' => $student_id,
            'date' => $date,
            'period' => $period
        ]);
        
        if ($check_stmt->fetch()) {
            // Update existing record
            $update_stmt = $conn->prepare("
                UPDATE period_attendance 
                SET status = 'On Duty',
                    updated_at = NOW()
                WHERE student_id = :student_id 
                AND date = :date 
                AND period = :period
            ");
            $update_stmt->execute([
                'student_id' => $student_id,
                'date' => $date,
                'period' => $period
            ]);
        } else {
            // Insert new record
            $insert_stmt = $conn->prepare("
                INSERT INTO period_attendance 
                (student_id, date, period, status, created_at, updated_at) 
                VALUES 
                (:student_id, :date, :period, 'On Duty', NOW(), NOW())
            ");
            $insert_stmt->execute([
                'student_id' => $student_id,
                'date' => $date,
                'period' => $period
            ]);
        }
        $success_count++;
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'status' => 'Success',
        'message' => "Student marked as On Duty for all $success_count periods",
        'student_id' => $student_id,
        'roll_no' => $roll_no,
        'date' => $date
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode([
        'status' => 'Error',
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'Error',
        'error' => $e->getMessage()
    ]);
}
?>