<?php
// mark_period_onduty.php
header('Content-Type: application/json');
require_once 'config.php';

try {
    $roll_no = isset($_POST['roll_no']) ? trim($_POST['roll_no']) : '';
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    
    if (empty($roll_no) || empty($date)) {
        echo json_encode([
            'status' => 'Error',
            'error' => 'Roll number and date are required'
        ]);
        exit;
    }
    
    // Get student_id
    $stmt = $conn->prepare("SELECT id, name FROM students WHERE roll_no = :roll_no");
    $stmt->execute(['roll_no' => $roll_no]);
    $student = $stmt->fetch();
    
    if (!$student) {
        echo json_encode([
            'status' => 'Error',
            'error' => 'Student not found with roll number: ' . $roll_no
        ]);
        exit;
    }
    
    $student_id = $student['id'];
    $conn->beginTransaction();
    
    // Mark all 9 periods as On Duty
    $success_count = 0;
    for ($period = 1; $period <= 9; $period++) {
        $stmt = $conn->prepare("
            INSERT INTO period_attendance (student_id, date, period, status) 
            VALUES (:student_id, :date, :period, 'On Duty')
            ON DUPLICATE KEY UPDATE status = 'On Duty', updated_at = NOW()
        ");
        
        $stmt->execute([
            'student_id' => $student_id,
            'date' => $date,
            'period' => $period
        ]);
        $success_count++;
    }
    
    $conn->commit();
    
    echo json_encode([
        'status' => 'Success',
        'message' => "Student {$student['name']} marked as On Duty for all $success_count periods on $date",
        'roll_no' => $roll_no
    ]);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'status' => 'Error',
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>