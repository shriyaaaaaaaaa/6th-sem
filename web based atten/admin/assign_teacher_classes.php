<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;
    $subjects = isset($_POST['subjects']) ? $_POST['subjects'] : [];
    $semester_ids = isset($_POST['semester_ids']) ? explode(',', $_POST['semester_ids']) : [];

    // Validate data
    if ($teacher_id <= 0) {
        setMessage('Invalid teacher ID', 'error');
        header('Location: teachers.php');
        exit;
    }

    if (empty($semester_ids)) {
        setMessage('No semesters selected', 'error');
        header('Location: teachers.php');
        exit;
    }

    // Start transaction
    mysqli_begin_transaction($conn);

    try {
        // Get all subject IDs for these semesters
        $placeholders = implode(',', array_fill(0, count($semester_ids), '?'));
        $subjectsQuery = "SELECT id FROM subjects WHERE semester_id IN ($placeholders)";
        $stmt = mysqli_prepare($conn, $subjectsQuery);
        
        // Bind parameters dynamically
        $types = str_repeat('i', count($semester_ids));
        $bindParams = array($stmt, $types);
        foreach ($semester_ids as $key => $value) {
            $bindParams[] = &$semester_ids[$key];
        }
        
        call_user_func_array('mysqli_stmt_bind_param', $bindParams);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $semesterSubjectIds = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $semesterSubjectIds[] = $row['id'];
        }
        
        // Remove existing subject assignments for this teacher and semester
        if (!empty($semesterSubjectIds)) {
            $placeholders = implode(',', array_fill(0, count($semesterSubjectIds), '?'));
            $deleteQuery = "DELETE FROM teacher_subjects WHERE teacher_id = ? AND subject_id IN ($placeholders)";
            
            $stmt = mysqli_prepare($conn, $deleteQuery);
            
            // Bind parameters dynamically
            $types = "i" . str_repeat("i", count($semesterSubjectIds));
            $bindParams = array($stmt, $types);
            $bindParams[] = &$teacher_id;
            foreach ($semesterSubjectIds as $key => $value) {
                $bindParams[] = &$semesterSubjectIds[$key];
            }
            
            call_user_func_array('mysqli_stmt_bind_param', $bindParams);
            mysqli_stmt_execute($stmt);
        }
        
        // If subjects were selected, insert new assignments
        if (!empty($subjects)) {
            // Prepare the insert statement
            $insertQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id, created_at) VALUES (?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $insertQuery);
            
            // Bind parameters and execute for each subject
            mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $subject_id);
            
            foreach ($subjects as $subject_id) {
                $subject_id = intval($subject_id);
                mysqli_stmt_execute($stmt);
            }
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        // Get teacher name for the success message
        $teacherQuery = "SELECT name FROM users WHERE id = ? AND role = 'teacher'";
        $stmt = mysqli_prepare($conn, $teacherQuery);
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $teacher = mysqli_fetch_assoc($result);
        
        // Set success message
        $message = 'Subjects for ' . htmlspecialchars($teacher['name']) . ' updated successfully';
        setMessage($message, 'success');
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        setMessage('Error updating teacher subjects: ' . $e->getMessage(), 'error');
    }

    // Redirect back to teachers page
    header('Location: teachers.php');
    exit;
}

// If not a POST request, redirect to teachers page
header('Location: teachers.php');
exit;
?>

