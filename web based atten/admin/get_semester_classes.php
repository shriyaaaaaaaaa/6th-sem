<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get semester ID from request
$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;

// Validate semester ID
if ($semester_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid semester ID']);
    exit;
}

// Get all subjects for this semester
$subjectsQuery = "SELECT s.*, c.id as class_id 
                 FROM subjects s 
                 LEFT JOIN classes c ON s.id = c.subject_id AND c.semester_id = ? 
                 WHERE s.semester_id = ? 
                 ORDER BY s.name";
$stmt = mysqli_prepare($conn, $subjectsQuery);
mysqli_stmt_bind_param($stmt, "ii", $semester_id, $semester_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row;
}

// Get assigned classes for this teacher
$assignedClassesQuery = "SELECT c.subject_id 
                        FROM teacher_classes tc 
                        JOIN classes c ON tc.class_id = c.id 
                        WHERE tc.teacher_id = ? AND c.semester_id = ?";
$stmt = mysqli_prepare($conn, $assignedClassesQuery);
mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $semester_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$assigned_subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assigned_subjects[] = $row['subject_id'];
}

// Return data as JSON
echo json_encode([
    'success' => true,
    'subjects' => $subjects,
    'assigned_subjects' => $assigned_subjects
]);
exit;
?>

