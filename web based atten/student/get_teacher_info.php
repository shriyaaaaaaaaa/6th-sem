<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is student
checkUserRole('student');

$user_id = $_SESSION['user_id'];
$response = ['status' => 'error', 'message' => 'Invalid request', 'data' => []];

// Get student's semester
$student_query = "SELECT semester FROM students WHERE user_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student_result = $stmt->get_result();

if ($student_result->num_rows > 0) {
    $student = $student_result->fetch_assoc();
    $semester_id = $student['semester'];
    
    // Get subject ID if provided
    $subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
    
    // Build query based on whether subject_id is provided
    if ($subject_id > 0) {
        // Get teacher for specific subject
        $query = "SELECT u.id, u.name, u.email, s.id as subject_id, s.name as subject_name, s.code as subject_code,
                 c.id as class_id, c.name as class_name
                 FROM users u
                 JOIN teacher_classes tc ON u.id = tc.teacher_id
                 JOIN classes c ON tc.class_id = c.id
                 JOIN subjects s ON c.subject_id = s.id
                 WHERE c.semester_id = ? AND s.id = ? AND u.role = 'teacher' AND u.status = 'approved'
                 ORDER BY s.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $semester_id, $subject_id);
    } else {
        // Get all teachers for the semester
        $query = "SELECT u.id, u.name, u.email, s.id as subject_id, s.name as subject_name, s.code as subject_code,
                 c.id as class_id, c.name as class_name
                 FROM users u
                 JOIN teacher_classes tc ON u.id = tc.teacher_id
                 JOIN classes c ON tc.class_id = c.id
                 JOIN subjects s ON c.subject_id = s.id
                 WHERE c.semester_id = ? AND u.role = 'teacher' AND u.status = 'approved'
                 ORDER BY s.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $semester_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $teachers = [];
        while ($row = $result->fetch_assoc()) {
            $teachers[] = $row;
        }
        
        $response = [
            'status' => 'success',
            'message' => 'Teachers retrieved successfully',
            'data' => $teachers
        ];
    } else {
        $response = [
            'status' => 'error',
            'message' => 'No teachers found for your semester' . ($subject_id > 0 ? ' and subject' : ''),
            'data' => []
        ];
    }
} else {
    $response = [
        'status' => 'error',
        'message' => 'Student information not found',
        'data' => []
    ];
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
exit;

