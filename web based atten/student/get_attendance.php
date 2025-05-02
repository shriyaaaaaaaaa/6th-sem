<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Get parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$student_id = $_SESSION['user_id'];

// Get attendance data for this month
$attendanceQuery = "SELECT DATE(date) as attend_date, status FROM attendance 
                 WHERE student_id = ? AND MONTH(date) = ? AND YEAR(date) = ?";
$stmt = mysqli_prepare($conn, $attendanceQuery);
$attendanceData = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "iii", $student_id, $month, $year);
    mysqli_stmt_execute($stmt);
    $attendanceResult = mysqli_stmt_get_result($stmt);
    
    if ($attendanceResult) {
        while ($row = mysqli_fetch_assoc($attendanceResult)) {
            $attendanceData[$row['attend_date']] = $row['status'];
        }
    }
}

// Get holidays
$holidayQuery = "SELECT date, name FROM holidays";
$holidayResult = mysqli_query($conn, $holidayQuery);
$holidays = [];

if ($holidayResult) {
    while ($row = mysqli_fetch_assoc($holidayResult)) {
        $holidays[$row['date']] = $row['name'];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'attendance' => $attendanceData,
    'holidays' => $holidays,
    'month' => $month,
    'year' => $year
]);
?>

