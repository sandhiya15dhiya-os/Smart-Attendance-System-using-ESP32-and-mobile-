<?php
// File: mark_period_attendance.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
$servername = "localhost";
$username = "root";
$password = "@989412musT";
$dbname = "attendance_db";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['status' => 'Error', 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? '';
    $date = $_POST['date'] ?? '';
    $period = $_POST['period'] ?? '';
    $status = $_POST['status'] ?? '';
    
    if (empty($student_id) || empty($date) || empty($period) || empty($status)) {
        echo json_encode(['status' => 'Error', 'error' => 'All fields are required']);
        exit;
    }
    
    try {
        // Get subject code for this period
        $subjectSql = "SELECT t.subject_code
                      FROM students s
                      INNER JOIN timetable t ON s.department = t.department 
                          AND s.year = t.year 
                          AND s.section = t.section
                          AND t.day_of_week = DAYOFWEEK(:date)
                          AND t.period = :period
                      WHERE s.id = :student_id";
        
        $subjectStmt = $pdo->prepare($subjectSql);
        $subjectStmt->execute([
            ':date' => $date,
            ':period' => $period,
            ':student_id' => $student_id
        ]);
        
        $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subject) {
            echo json_encode(['status' => 'Error', 'error' => 'No timetable entry found for this period']);
            exit;
        }
        
        // Update period attendance
        $updateSql = "INSERT INTO period_attendance (student_id, date, period, subject_code, status, marked_by)
                     VALUES (:student_id, :date, :period, :subject_code, :status, 'Manual')
                     ON DUPLICATE KEY UPDATE 
                     status = :status, 
                     marked_by = 'Manual'";
        
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':student_id' => $student_id,
            ':date' => $date,
            ':period' => $period,
            ':subject_code' => $subject['subject_code'],
            ':status' => $status
        ]);
        
        // Update subject attendance
        $pdo->prepare("CALL UpdateSubjectAttendance(:date, :period)")->execute([
            ':date' => $date,
            ':period' => $period
        ]);
        
        echo json_encode(['status' => 'Success', 'message' => 'Period attendance updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'Error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>