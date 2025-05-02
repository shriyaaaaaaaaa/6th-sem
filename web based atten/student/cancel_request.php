<?php
/**
 * Cancel Attendance Request API
 * 
 * This script allows students to cancel their pending attendance requests.
 * It validates the request ownership and status before cancellation.
 */

session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Determine if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

try {
    // Get student ID from session
    $student_id = $_SESSION['user_id'] ?? 0;
    
    if (!$student_id) {
        throw new Exception('User ID not found in session');
    }
    
    // Get request ID (accept both GET and POST)
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) 
                ?? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$request_id) {
        throw new Exception('Valid request ID is required');
    }
    
    // Prepare database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Check if the request exists and belongs to the student
    $checkQuery = "SELECT * FROM attendance_requests WHERE id = ? AND student_id = ?";
    $stmt = mysqli_prepare($conn, $checkQuery);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $request_id, $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) === 0) {
        throw new Exception('Request not found or you do not have permission to cancel it');
    }
    
    $request = mysqli_fetch_assoc($result);
    
    // Check if the request is still pending
    if ($request['status'] !== 'pending') {
        throw new Exception('Only pending requests can be cancelled');
    }
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    // Delete the request
    $deleteQuery = "DELETE FROM attendance_requests WHERE id = ?";
    $stmt = mysqli_prepare($conn, $deleteQuery);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare delete statement: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    $success = mysqli_stmt_execute($stmt);
    
    if (!$success) {
        throw new Exception('Failed to cancel attendance request: ' . mysqli_error($conn));
    }
    
    // Log the action
    try {
        logActivity($conn, $student_id, 'cancel_attendance_request', 
            "Student cancelled attendance request for " . date('Y-m-d', strtotime($request['date'])));
    } catch (Exception $e) {
        // Just log the error but don't fail the transaction
        error_log("Failed to log activity: " . $e->getMessage());
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Set success message in session
    $_SESSION['message'] = 'Attendance request cancelled successfully';
    $_SESSION['message_type'] = 'success';
    
    // Handle response based on request type
    if ($isAjax) {
        // Return JSON for AJAX requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Attendance request cancelled successfully',
            'request_id' => $request_id
        ]);
    } else {
        // Redirect for regular form submissions
        header('Location: requests.php');
        exit;
    }
    
} catch (Exception $e) {
    // Rollback transaction if active
    if (isset($conn) && mysqli_connect_errno() === 0) {
        mysqli_rollback($conn);
    }
    
    // Set error message in session
    $_SESSION['message'] = $e->getMessage();
    $_SESSION['message_type'] = 'error';
    
    // Handle error response based on request type
    if ($isAjax) {
        // Return JSON error for AJAX requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } else {
        // Redirect for regular form submissions
        header('Location: requests.php');
        exit;
    }
    
    // Log the error
    error_log("Error cancelling attendance request: " . $e->getMessage());
}
?>