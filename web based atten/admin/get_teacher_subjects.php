<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get teacher ID from request
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;

if ($teacher_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
    exit;
}

// Get teacher's assigned subjects
$subjectsQuery = "SELECT ts.subject_id, s.name, s.code, s.semester_id, sem.name as semester_name
                 FROM teacher_subjects ts
                 JOIN subjects s ON ts.subject_id = s.id
                 JOIN semesters sem ON s.semester_id = sem.id
                 WHERE ts.teacher_id = ?
                 ORDER BY sem.name, s.name";

$stmt = mysqli_prepare($conn, $subjectsQuery);
mysqli_stmt_bind_param($stmt, "i", $teacher_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row;
}

echo json_encode([
    'success' => true,
    'subjects' => $subjects
]);
exit;
?>
