<?php
// File: get_period_attendance.php
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
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = $_GET['date'] ?? '';
    $department = $_GET['department'] ?? '';
    $section = $_GET['section'] ?? '';
    
    if (empty($date) || empty($department) || empty($section)) {
        echo json_encode(['error' => 'Date, department, and section are required']);
        exit;
    }
    
    try {
        // Get students with their period attendance
        $sql = "SELECT DISTINCT
                    s.id,
                    s.name,
                    s.roll_no,
                    s.department,
                    s.section,
                    s.year
                FROM students s
                WHERE s.department = :department 
                AND s.section = :section
                ORDER BY s.roll_no";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':department' => $department,
            ':section' => $section
        ]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        
        foreach ($students as $student) {
            // Get period attendance for this student on this date
            $periodSql = "SELECT period, status
                         FROM period_attendance
                         WHERE student_id = :student_id
                         AND date = :date
                         ORDER BY period";
            
            $periodStmt = $pdo->prepare($periodSql);
            $periodStmt->execute([
                ':student_id' => $student['id'],
                ':date' => $date
            ]);
            $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create periods array
            $periodsData = [];
            for ($i = 1; $i <= 9; $i++) {
                $periodsData["p$i"] = 'A'; // Default to Absent
            }
            
            // Update with actual attendance data
            foreach ($periods as $period) {
                $periodNum = $period['period'];
                $status = $period['status'];
                
                $statusCode = match($status) {
                    'Present' => 'P',
                    'On Duty' => 'OD',
                    'Holiday' => 'H',
                    default => 'A'
                };
                
                $periodsData["p$periodNum"] = $statusCode;
            }
            
            $result[] = [
                'id' => $student['id'],
                'name' => $student['name'],
                'roll_no' => $student['roll_no'],
                'department' => $student['department'],
                'section' => $student['section'],
                'periods' => $periodsData
            ];
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>
