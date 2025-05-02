<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Ensure it's an AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = mysqli_real_escape_string($conn, $_POST['date']);
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0;
    $reason = isset($_POST['reason']) ? mysqli_real_escape_string($conn, $_POST['reason']) : '';
    $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
    $student_id = $_SESSION['user_id'];
    
    // Validate required fields
    if (empty($date) || empty($reason)) {
        echo json_encode([
            'success' => false,
            'message' => 'Date and reason are required fields'
        ]);
        exit;
    }
    
    // Validate reason length
    if (strlen($reason) < 10) {
        echo json_encode([
            'success' => false,
            'message' => 'Reason must be at least 10 characters long'
        ]);
        exit;
    }
    
    // Validate date (should be in the past)
    $requestDate = new DateTime($date);
    $today = new DateTime();
    $today->setTime(0, 0, 0); // Set to beginning of day for fair comparison
    
    if ($requestDate >= $today) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot request attendance for future dates'
        ]);
        exit;
    }
    
    // Check if date is too old (more than 30 days)
    $thirtyDaysAgo = new DateTime();
    $thirtyDaysAgo->modify('-30 days');
    $thirtyDaysAgo->setTime(0, 0, 0);
    
    if ($requestDate < $thirtyDaysAgo) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot request attendance for dates older than 30 days'
        ]);
        exit;
    }
    
    // Check if date is a holiday
    $holidays = getHolidays($conn);
    if (isset($holidays[$date])) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot request attendance for a holiday: ' . $holidays[$date]
        ]);
        exit;
    }
    
    // Check if date is a weekend
    $dayOfWeek = $requestDate->format('w'); // 0 (Sunday) to 6 (Saturday)
    if ($dayOfWeek == 0 || $dayOfWeek == 6) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot request attendance for weekends'
        ]);
        exit;
    }
    
    // Check if attendance already marked for this date
    $checkQuery = "SELECT * FROM attendance WHERE student_id = ? AND DATE(date) = ?";
    $stmt = mysqli_prepare($conn, $checkQuery);
    
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, "is", $student_id, $date);
    mysqli_stmt_execute($stmt);
    $checkResult = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($checkResult) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Attendance already marked for this date'
        ]);
        exit;
    }
    
    // Check if request already exists for this date (only consider pending or approved)
    $checkRequestQuery = "SELECT * FROM attendance_requests 
                         WHERE student_id = ? AND date = ? 
                         AND (status = 'pending' OR status = 'approved')";
    $stmt = mysqli_prepare($conn, $checkRequestQuery);
    
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, "is", $student_id, $date);
    mysqli_stmt_execute($stmt);
    $checkRequestResult = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($checkRequestResult) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'A request already exists for this date'
        ]);
        exit;
    }
    
    // If no teacher_id provided, get a teacher for this student's semester
    if (!$teacher_id) {
        // Get student's semester
        $semesterQuery = "SELECT semester FROM users WHERE id = ? AND role = 'student'";
        $stmt = mysqli_prepare($conn, $semesterQuery);
        mysqli_stmt_bind_param($stmt, "i", $student_id);
        mysqli_stmt_execute($stmt);
        $semesterResult = mysqli_stmt_get_result($stmt);
        $semesterRow = mysqli_fetch_assoc($semesterResult);
        
        if ($semesterRow) {
            $semester = $semesterRow['semester'];
            
            // Get a teacher for this semester
            $teacherQuery = "SELECT u.id 
                            FROM users u 
                            JOIN teacher_classes tc ON u.id = tc.teacher_id 
                            WHERE tc.semester = ? AND u.role = 'teacher' 
                            LIMIT 1";
            $stmt = mysqli_prepare($conn, $teacherQuery);
            mysqli_stmt_bind_param($stmt, "i", $semester);
            mysqli_stmt_execute($stmt);
            $teacherResult = mysqli_stmt_get_result($stmt);
            $teacherRow = mysqli_fetch_assoc($teacherResult);
            
            if ($teacherRow) {
                $teacher_id = $teacherRow['id'];
            }
        }
    }
    
    // Insert request
    $insertQuery = "INSERT INTO attendance_requests 
                   (student_id, teacher_id, date, reason, latitude, longitude, status, created_at) 
                   VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = mysqli_prepare($conn, $insertQuery);
    
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, "iissdd", $student_id, $teacher_id, $date, $reason, $latitude, $longitude);
    
    if (mysqli_stmt_execute($stmt)) {
        $request_id = mysqli_insert_id($conn);
        
        // Log the request
        logActivity($conn, $student_id, 'attendance_request', "Student submitted attendance request for $date");
        
        echo json_encode([
            'success' => true,
            'request_id' => $request_id,
            'message' => 'Attendance request submitted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Error submitting request: ' . mysqli_error($conn)
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>

