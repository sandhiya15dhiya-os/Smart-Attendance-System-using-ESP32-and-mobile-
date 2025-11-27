<?php
// File: get_timetable.php
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
    $department = $_GET['department'] ?? '';
    $section = $_GET['section'] ?? '';
    $day = $_GET['day'] ?? '';
    
    try {
        $sql = "SELECT 
                    t.period,
                    t.subject_code,
                    s.subject_name,
                    CASE t.period
                        WHEN 1 THEN '08:30-09:15'
                        WHEN 2 THEN '09:15-10:00'
                        WHEN 3 THEN '10:00-10:45'
                        WHEN 4 THEN '11:05-11:50'
                        WHEN 5 THEN '11:50-12:35'
                        WHEN 6 THEN '13:20-14:05'
                        WHEN 7 THEN '14:05-14:50'
                        WHEN 8 THEN '15:05-15:50'
                        WHEN 9 THEN '15:50-16:35'
                    END as time_slot
                FROM timetable t
                INNER JOIN subjects s ON t.subject_code = s.subject_code
                WHERE t.department = :department
                AND t.section = :section
                " . ($day ? "AND t.day_of_week = :day" : "") . "
                AND t.is_active = 1
                ORDER BY t.day_of_week, t.period";
        
        $params = [
            ':department' => $department,
            ':section' => $section
        ];
        
        if ($day) {
            $params[':day'] = $day;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $timetable = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($timetable);
        
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>