<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin or teacher role
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    setMessage('You do not have permission to access this page', 'error');
    header('Location: ../index.php');
    exit;
}

// Check if student ID is provided
if (!isset($_GET['student_id'])) {
    setMessage('Student ID is required', 'error');
    header('Location: ' . (hasRole('admin') ? 'students.php' : 'attendance_records.php'));
    exit;
}

$student_id = intval($_GET['student_id']);

// Get student details
$studentQuery = "SELECT * FROM users WHERE id = ? AND role = 'student'";
$stmt = mysqli_prepare($conn, $studentQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$studentResult = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($studentResult) == 0) {
    setMessage('Student not found', 'error');
    header('Location: ' . (hasRole('admin') ? 'students.php' : 'attendance_records.php'));
    exit;
}

$student = mysqli_fetch_assoc($studentResult);

// Get attendance records
$attendanceQuery = "SELECT a.*, u.name as teacher_name 
                   FROM attendance a 
                   LEFT JOIN users u ON a.teacher_id = u.id 
                   WHERE a.student_id = ? 
                   ORDER BY a.date DESC";
$stmt = mysqli_prepare($conn, $attendanceQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$attendanceResult = mysqli_stmt_get_result($stmt);

// Create CSV file
$filename = "attendance_" . $student['roll_no'] . "_" . date('Y-m-d') . ".csv";

// Set headers for file download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel to recognize UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add student information
fputcsv($output, ['Student Attendance Report']);
fputcsv($output, ['Name:', $student['name']]);
fputcsv($output, ['Roll Number:', $student['roll_no']]);

