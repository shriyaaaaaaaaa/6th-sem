<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Get student ID
$student_id = $_SESSION['user_id'];

// Set content type to JSON
header('Content-Type: application/json');

// Check if request ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request ID'
    ]);
    exit;
}

$request_id = intval($_GET['id']);

// Get request details
$requestQuery = "SELECT ar.*, s.name as subject_name, s.code as subject_code, c.name as class_name,
                t.name as teacher_name, t.email as teacher_email, t.profile_image as teacher_image
                FROM attendance_requests ar
                JOIN subjects s ON ar.subject_id = s.id
                JOIN classes c ON ar.class_id = c.id
                JOIN users t ON ar.teacher_id = t.id
                WHERE ar.id = ? AND ar.student_id = ?";
$stmt = mysqli_prepare($conn, $requestQuery);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Request not found or you do not have permission to view it'
    ]);
    exit;
}

$request = mysqli_fetch_assoc($result);

// Format response
echo json_encode([
    'success' => true,
    'request' => [
        'id' => $request['id'],
        'date' => $request['date'],
        'reason' => $request['reason'],
        'status' => $request['status'],
        'created_at' => $request['created_at'],
        'updated_at' => $request['updated_at'],
        'subject_code' => $request['subject_code'],
        'subject_name' => $request['subject_name'],
        'class_name' => $request['class_name'],
        'teacher_name' => $request['teacher_name'],
        'teacher_email' => $request['teacher_email'],
        'teacher_image' => $request['teacher_image'],
        'feedback' => $request['feedback'] ?? null
    ]
]);
?>

