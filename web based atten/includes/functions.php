<?php
// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check if user has specific role
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Require specific role to access page
function requireRole($role) {
    if (!isLoggedIn() || !hasRole($role)) {
        setMessage('You do not have permission to access this page', 'error');
        header('Location: ../index.php');
        exit;
    }
}

// Set flash message
function setMessage($message, $type = 'success') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

// Get flash message
function getMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'success';
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// Sanitize input
function sanitizeInput($input) {
    if (is_array($input)) {
        foreach ($input as $key => $value) {
            $input[$key] = sanitizeInput($value);
        }
    } else {
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
    return $input;
}

// Generate random string
function generateRandomString($length = 6) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

// Calculate distance between two points using Haversine formula
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
  // If coordinates are very close (same device), return 0
  $latDiff = abs($lat1 - $lat2);
  $lonDiff = abs($lon1 - $lon2);
  
  // If difference is extremely small (likely same device with GPS fluctuation)
  if ($latDiff < 0.0001 && $lonDiff < 0.0001) {
      return 0;
  }
  
  $earthRadius = 6371000; // meters
  $lat1 = deg2rad($lat1);
  $lon1 = deg2rad($lon1);
  $lat2 = deg2rad($lat2);
  $lon2 = deg2rad($lon2);
  
  $latDelta = $lat2 - $lat1;
  $lonDelta = $lon2 - $lon1;
  
  $a = sin($latDelta/2) * sin($latDelta/2) +
       cos($lat1) * cos($lat2) *
       sin($lonDelta/2) * sin($lonDelta/2);
  $c = 2 * atan2(sqrt($a), sqrt(1-$a));
  
  return $earthRadius * $c;
}

// Get holidays
function getHolidays($conn) {
    $holidays = [];
    $query = "SELECT date, name FROM holidays";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $holidays[$row['date']] = $row['name'];
        }
    }
    
    return $holidays;
}

// Log activity
function logActivity($conn, $user_id, $action, $description) {
    $query = "INSERT INTO activity_logs (user_id, action, description, created_at) 
              VALUES (?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iss", $user_id, $action, $description);
        mysqli_stmt_execute($stmt);
    }
}

// Get settings
function getSettings($conn) {
    $settings = [];
    $query = "SELECT * FROM settings LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $settings = mysqli_fetch_assoc($result);
    }
    
    return $settings;
}

// Format date
function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

// Format time
function formatTime($time, $format = 'h:i A') {
    return date($format, strtotime($time));
}

// Get user details
function getUserDetails($conn, $user_id) {
    $query = "SELECT * FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            return mysqli_fetch_assoc($result);
        }
    }
    
    return null;
}

// Get semester name
function getSemesterName($conn, $semester_id) {
    $query = "SELECT name FROM semesters WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $semester_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['name'];
        }
    }
    
    return 'Unknown Semester';
}

// Get subject name
function getSubjectName($conn, $subject_id) {
    $query = "SELECT name, code FROM subjects WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $subject_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['code'] . ' - ' . $row['name'];
        }
    }
    
    return 'Unknown Subject';
}

// Get class name
function getClassName($conn, $class_id) {
    $query = "SELECT name FROM classes WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['name'];
        }
    }
    
    return 'Unknown Class';
}

// Get teacher name
function getTeacherName($conn, $teacher_id) {
    $query = "SELECT name FROM users WHERE id = ? AND role = 'teacher'";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['name'];
        }
    }
    
    return 'Unknown Teacher';
}

// Get student name
function getStudentName($conn, $student_id) {
    $query = "SELECT name FROM users WHERE id = ? AND role = 'student'";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $student_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['name'];
        }
    }
    
    return 'Unknown Student';
}

// Check if date is a holiday
function isHoliday($conn, $date) {
    $query = "SELECT * FROM holidays WHERE date = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $date);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        return mysqli_num_rows($result) > 0;
    }
    
    return false;
}

// Check if date is a weekend
function isWeekend($date) {
    $day = date('w', strtotime($date));
    return ($day == 0 || $day == 6); // 0 = Sunday, 6 = Saturday
}

// Mark absent students for expired OTPs
function markAbsentForExpiredOTPs($conn) {
    // Get all expired OTPs that haven't been processed for absent marking
    $expiredOtpsQuery = "SELECT otp_code FROM otp 
                        WHERE expiry < NOW() 
                        AND (absent_processed IS NULL OR absent_processed = 0)";
    $result = mysqli_query($conn, $expiredOtpsQuery);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Get OTP details
            $otpQuery = "SELECT o.*, s.semester_id 
                        FROM otp o 
                        JOIN subjects s ON o.subject_id = s.id 
                        WHERE o.otp_code = ?";
            $stmt = mysqli_prepare($conn, $otpQuery);
            mysqli_stmt_bind_param($stmt, "s", $row['otp_code']);
            mysqli_stmt_execute($stmt);
            $otpResult = mysqli_stmt_get_result($stmt);
            
            if ($otpRow = mysqli_fetch_assoc($otpResult)) {
                $subject_id = $otpRow['subject_id'];
                $semester_id = $otpRow['semester_id'];
                $teacher_id = $otpRow['teacher_id'];
                $today = date('Y-m-d');
                
                // Find a class_id for this subject
                $classQuery = "SELECT id FROM classes WHERE subject_id = ? AND semester_id = ? LIMIT 1";
                $stmt = mysqli_prepare($conn, $classQuery);
                mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
                mysqli_stmt_execute($stmt);
                $classResult = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($classResult) > 0) {
                    $classRow = mysqli_fetch_assoc($classResult);
                    $class_id = $classRow['id'];
                } else {
                    // Create a new class
                    $subjectQuery = "SELECT name, code FROM subjects WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $subjectQuery);
                    mysqli_stmt_bind_param($stmt, "i", $subject_id);
                    mysqli_stmt_execute($stmt);
                    $subjectResult = mysqli_stmt_get_result($stmt);
                    $subject = mysqli_fetch_assoc($subjectResult);
                    
                    $class_name = $subject['code'] . " Class";
                    $class_code = $subject['code'];
                    
                    $insertQuery = "INSERT INTO classes (name, code, semester_id, subject_id, created_at) 
                                   VALUES (?, ?, ?, ?, NOW())";
                    $stmt = mysqli_prepare($conn, $insertQuery);
                    mysqli_stmt_bind_param($stmt, "ssii", $class_name, $class_code, $semester_id, $subject_id);
                    mysqli_stmt_execute($stmt);
                    
                    $class_id = mysqli_insert_id($conn);
                }
                
                // Get all students in this semester
                $studentsQuery = "SELECT id FROM users WHERE role = 'student' AND semester = ? AND status = 'active'";
                $stmt = mysqli_prepare($conn, $studentsQuery);
                mysqli_stmt_bind_param($stmt, "i", $semester_id);
                mysqli_stmt_execute($stmt);
                $studentsResult = mysqli_stmt_get_result($stmt);
                
                while ($student = mysqli_fetch_assoc($studentsResult)) {
                    $student_id = $student['id'];
                    
                    // Check if attendance already exists for this student, subject, and date
                    $checkQuery = "SELECT id FROM attendance 
                                  WHERE student_id = ? AND subject_id = ? AND date = ?";
                    $stmt = mysqli_prepare($conn, $checkQuery);
                    mysqli_stmt_bind_param($stmt, "iis", $student_id, $subject_id, $today);
                    mysqli_stmt_execute($stmt);
                    $checkResult = mysqli_stmt_get_result($stmt);
                    
                    // If no attendance record exists, mark as absent
                    if (mysqli_num_rows($checkResult) == 0) {
                        $insertQuery = "INSERT INTO attendance 
                                      (student_id, class_id, subject_id, teacher_id, status, date, marked_at, created_at) 
                                      VALUES (?, ?, ?, ?, 'absent', ?, NOW(), NOW())";
                        $stmt = mysqli_prepare($conn, $insertQuery);
                        mysqli_stmt_bind_param($stmt, "iiiis", $student_id, $class_id, $subject_id, $teacher_id, $today);
                        mysqli_stmt_execute($stmt);
                        
                        // Log activity
                        $description = "Student ID $student_id automatically marked absent for subject ID $subject_id";
                        logActivity($conn, $teacher_id, 'auto_absent', $description);
                    }
                }
                
                // Mark OTP as processed for absent marking
                $updateQuery = "UPDATE otp SET absent_processed = 1 WHERE otp_code = ?";
                $stmt = mysqli_prepare($conn, $updateQuery);
                mysqli_stmt_bind_param($stmt, "s", $row['otp_code']);
                mysqli_stmt_execute($stmt);
            }
        }
    }
}
function validateEmail($email) {
    $pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
    return preg_match($pattern, $email) === 1;
}
function validatePhone($phone) {
    // Regular expression to match a 10-digit phone number
    $pattern = '/^\d{10}$/';
    return preg_match($pattern, $phone) === 1;
}
?>

