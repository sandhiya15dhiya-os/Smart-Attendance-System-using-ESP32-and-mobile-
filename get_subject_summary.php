<?php
// Database connection
$pdo = new PDO('mysql:host=localhost;dbname=attendance_db', 'root', '@989412musT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Aggregate period attendance
$sql = "
    SELECT 
        studentid, 
        subjectcode, 
        date,
        COUNT(*) AS totalperiods,
        SUM(status = 'Present') AS presentperiods,
        SUM(status = 'Absent') AS absentperiods,
        SUM(status = 'On Duty') AS ondutyperiods
    FROM periodattendance
    GROUP BY studentid, subjectcode, date
";
$stmt = $pdo->query($sql);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Insert or update subjectattendance
foreach ($attendances as $row) {
    $insert = "
        INSERT INTO subjectattendance 
            (studentid, subjectcode, date, totalperiods, presentperiods, absentperiods, ondutyperiods) 
        VALUES 
            (:studentid, :subjectcode, :date, :totalperiods, :presentperiods, :absentperiods, :ondutyperiods)
        ON DUPLICATE KEY UPDATE
            totalperiods = VALUES(totalperiods),
            presentperiods = VALUES(presentperiods),
            absentperiods = VALUES(absentperiods),
            ondutyperiods = VALUES(ondutyperiods)
    ";
    $stmt2 = $pdo->prepare($insert);
    $stmt2->execute([
        ':studentid' => $row['studentid'],
        ':subjectcode' => $row['subjectcode'],
        ':date' => $row['date'],
        ':totalperiods' => $row['totalperiods'],
        ':presentperiods' => $row['presentperiods'],
        ':absentperiods' => $row['absentperiods'],
        ':ondutyperiods' => $row['ondutyperiods']
    ]);
}

echo "Subject attendance has been updated successfully.";
?>
