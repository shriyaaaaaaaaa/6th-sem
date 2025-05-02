<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get semester IDs and teacher ID from request
$semester_ids = isset($_GET['semester_ids']) ? $_GET['semester_ids'] : [];
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;

if (empty($semester_ids) || $teacher_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Get semesters
$semesterPlaceholders = implode(',', array_fill(0, count($semester_ids), '?'));
$semestersQuery = "SELECT * FROM semesters WHERE id IN ($semesterPlaceholders) ORDER BY name";

$stmt = mysqli_prepare($conn, $semestersQuery);
$types = str_repeat('i', count($semester_ids));
$bindParams = array($stmt, $types);
foreach ($semester_ids as $key => $value) {
    $bindParams[] = &$semester_ids[$key];
}
call_user_func_array('mysqli_stmt_bind_param', $bindParams);

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$semesters = [];
while ($row = mysqli_fetch_assoc($result)) {
    $semesters[] = $row;
}

// Get subjects for the selected semesters
$subjectsQuery = "SELECT s.*, sem.name as semester_name 
                 FROM subjects s 
                 JOIN semesters sem ON s.semester_id = sem.id 
                 WHERE s.semester_id IN ($semesterPlaceholders)
                 ORDER BY sem.name, s.name";

$stmt = mysqli_prepare($conn, $subjectsQuery);
$types = str_repeat('i', count($semester_ids));
$bindParams = array($stmt, $types);
foreach ($semester_ids as $key => $value) {
    $bindParams[] = &$semester_ids[$key];
}
call_user_func_array('mysqli_stmt_bind_param', $bindParams);

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row;
}

// Get teacher's assigned subjects
$assignedSubjectsQuery = "SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?";
$stmt = mysqli_prepare($conn, $assignedSubjectsQuery);
mysqli_stmt_bind_param($stmt, "i", $teacher_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$assignedSubjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assignedSubjects[] = intval($row['subject_id']);
}

echo json_encode([
    'success' => true,
    'semesters' => $semesters,
    'subjects' => $subjects,
    'assigned_subjects' => $assignedSubjects
]);
exit;
?>
