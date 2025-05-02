<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

// Get teacher ID
$teacher_id = $_SESSION['user_id'];

// Initialize variables
$error = '';
$success = '';
$otp_code = '';
$expiry_time = '';
$latitude = '';
$longitude = '';
$radius = '';
$subject_id = '';
$newly_generated = false;

// Get admin settings for OTP validity
$settingsQuery = "SELECT otp_validity_minutes, distance_threshold FROM settings LIMIT 1";
$settingsResult = mysqli_query($conn, $settingsQuery);
$settings = mysqli_fetch_assoc($settingsResult);
$otp_validity_minutes = $settings['otp_validity_minutes'] ?? 15; // Default to 15 minutes if not set
$default_radius = $settings['distance_threshold'] ?? 50; // Default radius from settings

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_form_resubmit'])) {
    // Get form data
    $subject_id = $_POST['subject_id'] ?? '';
    $radius = $_POST['radius'] ?? $default_radius;
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';
    $duration = $_POST['duration'] ?? $otp_validity_minutes; // Get selected duration
    
    // Ensure duration doesn't exceed admin setting
    if ($duration > $otp_validity_minutes) {
        $duration = $otp_validity_minutes;
    }
    
    // Validate inputs
    if (empty($subject_id)) {
        $error = 'Please select a subject';
    } elseif (empty($latitude) || empty($longitude)) {
        $error = 'Location data is required. Please allow location access.';
    } else {
        // Check if teacher is assigned to this subject
        $checkSubjectQuery = "SELECT * FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?";
        $stmt = mysqli_prepare($conn, $checkSubjectQuery);
        mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $subject_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 0) {
            $error = 'You are not assigned to this subject';
        } else {
            // Check if OTP already exists for this teacher
            $checkOtpQuery = "SELECT * FROM otp WHERE teacher_id = ? AND expiry > NOW()";
            $stmt = mysqli_prepare($conn, $checkOtpQuery);
            mysqli_stmt_bind_param($stmt, "i", $teacher_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($result) > 0) {
                // Delete existing OTP
                $deleteOtpQuery = "DELETE FROM otp WHERE teacher_id = ? AND expiry > NOW()";
                $stmt = mysqli_prepare($conn, $deleteOtpQuery);
                mysqli_stmt_bind_param($stmt, "i", $teacher_id);
                mysqli_stmt_execute($stmt);
            }

            // Get a valid class_id for this subject
            $classQuery = "SELECT c.id FROM classes c WHERE c.subject_id = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $classQuery);
            mysqli_stmt_bind_param($stmt, "i", $subject_id);
            mysqli_stmt_execute($stmt);
            $classResult = mysqli_stmt_get_result($stmt);
            $class_id = 0;

            if ($classRow = mysqli_fetch_assoc($classResult)) {
                $class_id = $classRow['id'];
            } else {
                // If no class exists for this subject, create a default one
                $semesterQuery = "SELECT semester_id FROM subjects WHERE id = ?";
                $stmt = mysqli_prepare($conn, $semesterQuery);
                mysqli_stmt_bind_param($stmt, "i", $subject_id);
                mysqli_stmt_execute($stmt);
                $semResult = mysqli_stmt_get_result($stmt);
                $semRow = mysqli_fetch_assoc($semResult);
                $semester_id = $semRow['semester_id'];
                
                $subjectQuery = "SELECT name, code FROM subjects WHERE id = ?";
                $stmt = mysqli_prepare($conn, $subjectQuery);
                mysqli_stmt_bind_param($stmt, "i", $subject_id);
                mysqli_stmt_execute($stmt);
                $subResult = mysqli_stmt_get_result($stmt);
                $subRow = mysqli_fetch_assoc($subResult);
                $class_name = $subRow['code'] . " Class";
                
                // Insert a new class
                $insertClassQuery = "INSERT INTO classes (name, semester_id, subject_id, created_at) VALUES (?, ?, ?, NOW())";
                $stmt = mysqli_prepare($conn, $insertClassQuery);
                mysqli_stmt_bind_param($stmt, "sii", $class_name, $semester_id, $subject_id);
                mysqli_stmt_execute($stmt);
                $class_id = mysqli_insert_id($conn);
                
                // Associate teacher with this class
                $insertTeacherClassQuery = "INSERT INTO teacher_classes (teacher_id, class_id, subject_id, created_at) VALUES (?, ?, ?, NOW())";
                $stmt = mysqli_prepare($conn, $insertTeacherClassQuery);
                mysqli_stmt_bind_param($stmt, "iii", $teacher_id, $class_id, $subject_id);
                mysqli_stmt_execute($stmt);
            }

            // Generate OTP
            // Generate OTP (6 digits)
            $otp_code = sprintf('%06d', mt_rand(0, 999999));

            // Use MySQL's functions to handle both timestamps consistently
            $insertOtpQuery = "INSERT INTO otp (teacher_id, class_id, subject_id, otp_code, latitude, longitude, radius, created_at, expiry, duration) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)";
            $stmt = mysqli_prepare($conn, $insertOtpQuery);
            mysqli_stmt_bind_param($stmt, "iiisddiii", $teacher_id, $class_id, $subject_id, $otp_code, $latitude, $longitude, $radius, $duration, $duration);

            if (mysqli_stmt_execute($stmt)) {
                // Set success message in session
                $_SESSION['message'] = 'OTP generated successfully';
                $_SESSION['message_type'] = 'success';
                
                // Redirect to prevent form resubmission on refresh
                header("Location: generate_otp.php");
                exit();
            } else {
                $error = 'Failed to generate OTP: ' . mysqli_error($conn);
            }

        }
    }
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $success = $_SESSION['message'];
    unset($_SESSION['message']);
    $newly_generated = true;
}

// Get teacher's assigned subjects
$subjectsQuery = "SELECT ts.*, s.name as subject_name, s.code as subject_code, 
              sem.id as semester_id, sem.name as semester_name
              FROM teacher_subjects ts
              JOIN subjects s ON ts.subject_id = s.id
              JOIN semesters sem ON s.semester_id = sem.id
              WHERE ts.teacher_id = ?
              ORDER BY sem.id, s.name";
$stmt = mysqli_prepare($conn, $subjectsQuery);
$teacherSubjects = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $teacherSubjects[] = $row;
        }
    }
}

// Update the query to get the latest OTP for the teacher, even if it's expired
$currentOtpQuery = "SELECT o.*, s.name as subject_name, s.code as subject_code, 
                    c.name as class_name, sem.name as semester_name
                    FROM otp o
                    JOIN subjects s ON o.subject_id = s.id
                    JOIN classes c ON o.class_id = c.id
                    JOIN semesters sem ON s.semester_id = sem.id
                    WHERE o.teacher_id = ?
                    ORDER BY o.created_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $currentOtpQuery);
$hasOtp = false;
$otpData = null;
$isActiveOtp = false;

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $hasOtp = mysqli_num_rows($result) > 0;
    $otpData = $hasOtp ? mysqli_fetch_assoc($result) : null;
    
    if ($hasOtp) {
        // Check if OTP is still active
        $now = new DateTime();
        $expiry = new DateTime($otpData['expiry']);
        $isActiveOtp = ($now < $expiry);
        
        // Calculate remaining time if active
        if ($isActiveOtp) {
            $interval = $now->diff($expiry);
            $remainingMinutes = ($interval->h * 60) + $interval->i;
            $remainingSeconds = $interval->s;
        }
    }
}

// Group subjects by semester
$subjectsBySemester = [];
foreach ($teacherSubjects as $subject) {
    $semesterId = $subject['semester_id'];
    if (!isset($subjectsBySemester[$semesterId])) {
        $subjectsBySemester[$semesterId] = [
            'semester_name' => $subject['semester_name'],
            'subjects' => []
        ];
    }
    $subjectsBySemester[$semesterId]['subjects'][] = $subject;
}

// Fetch recent OTPs
$recentOtpsQuery = "SELECT o.*, s.name as subject_name, s.code as subject_code
                    FROM otp o
                    JOIN subjects s ON o.subject_id = s.id
                    WHERE o.teacher_id = ?
                    ORDER BY o.created_at DESC
                    LIMIT 5";
$stmt = mysqli_prepare($conn, $recentOtpsQuery);
$recentOtps = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Calculate remaining time
            $now = new DateTime();
            $expiry = new DateTime($row['expiry']);
            $isActive = ($now < $expiry);
            
            $row['status'] = $isActive ? 'Active' : 'Expired';
            
            if ($isActive) {
                $interval = $now->diff($expiry);
                $row['remaining_minutes'] = ($interval->h * 60) + $interval->i;
                $row['remaining_seconds'] = $interval->s;
            } else {
                $row['remaining_minutes'] = 0;
                $row['remaining_seconds'] = 0;
            }
            
            $recentOtps[] = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate OTP - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .otp-display {
            font-size: 2.5rem;
            font-weight: bold;
            letter-spacing: 3px;
            background-color: #f8f9fa;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 15px 0;
            display: inline-block;
            border: 2px solid #dc3545;
            color: #dc3545;
        }
        .otp-container {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            border: 2px solid #dc3545;
            animation: fadeIn 0.5s ease-in-out;
        }
        .otp-code-container {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e7eb 100%);
            border-radius: 15px;
            padding: 25px;
            margin: 20px auto;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border: 3px solid #dc3545;
            position: relative;
            overflow: hidden;
        }
        .otp-code-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background-color: #dc3545;
        }
        .otp-code-title {
            color: #dc3545;
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .otp-code-display {
            font-family: 'Courier New', monospace;
            font-size: 3rem;
            font-weight: bold;
            letter-spacing: 5px;
            color: #dc3545;
            background-color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin: 15px 0;
            display: inline-block;
            border: 2px dashed #dc3545;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.2);
        }
        .otp-code-info {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 15px;
        }
        .otp-code-timer {
            font-size: 1.2rem;
            font-weight: bold;
            color: #343a40;
            margin: 10px 0;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .otp-container h3 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        .otp-container .badge {
            font-size: 2rem;
            padding: 10px 15px;
            letter-spacing: 3px;
            margin: 10px 0;
            background-color: #dc3545;
        }
        .otp-timer {
            font-size: 1.5rem;
            font-weight: bold;
            color: #343a40;
            margin: 15px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .otp-timer i {
            margin-right: 10px;
            color: #dc3545;
            transition: all 0.3s ease;
        }

        .otp-timer.expired {
            color: #dc3545;
            transition: all 0.3s ease;
        }

        /* Smooth transition for countdown timers */
        .countdown-timer {
            transition: all 0.1s ease-out;
        }

        /* Pulse animation for last 60 seconds */
        @keyframes pulse-warning {
            0% { color: #dc3545; }
            50% { color: #ffc107; }
            100% { color: #dc3545; }
        }

        .countdown-warning {
            animation: pulse-warning 1s infinite;
        }
        .otp-details {
            margin-top: 15px;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .active-otp-card {
            border: 2px solid #dc3545;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .active-otp-card .card-header {
            background-color: #dc3545;
            color: white;
            font-weight: bold;
            padding: 12px 20px;
        }
        .active-otp-card .card-body {
            padding: 20px;
        }
        .active-otp-display {
            font-family: 'Courier New', monospace;
            font-size: 4.5rem;
            font-weight: bold;
            letter-spacing: 12px;
            color: #dc3545;
            background-color: #f8f9fa;
            padding: 20px 30px;
            border-radius: 15px;
            margin: 20px auto;
            display: inline-block;
            border: 3px dashed #dc3545;
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.2);
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .active-otp-display:hover {
            transform: scale(1.05);
            box-shadow: 0 12px 30px rgba(220, 53, 69, 0.3);
        }
        .active-otp-info {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        .active-otp-info-item {
            flex: 1;
            min-width: 150px;
            margin-bottom: 10px;
            padding: 0 10px;
        }
        .active-otp-info-item i {
            color: #dc3545;
            margin-right: 5px;
        }
        .active-otp-info-item .label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        .active-otp-info-item .value {
            color: #495057;
        }
        .newly-generated {
            animation: pulse 1.5s ease-in-out infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .copy-tooltip {
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .copy-tooltip.show {
            opacity: 1;
        }
        .recent-otp-table {
            margin-top: 20px;
        }
        .recent-otp-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .recent-otp-table .otp-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            letter-spacing: 2px;
            color: #dc3545;
        }
        .recent-otp-table .active-otp {
            background-color: rgba(220, 53, 69, 0.1);
        }
        .recent-otp-table .newly-generated {
            animation: highlight 2s ease-in-out infinite;
        }
        @keyframes highlight {
            0% { background-color: rgba(220, 53, 69, 0.1); }
            50% { background-color: rgba(220, 53, 69, 0.2); }
            100% { background-color: rgba(220, 53, 69, 0.1); }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-chalkboard-teacher me-2"></i>Teacher Dashboard
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link " href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="generate_otp.php">
                            <i class="fas fa-key me-1"></i>Generate OTP
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="mark_attendance.php">
                            <i class="fas fa-user-check me-1"></i>Mark Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance_requests.php">
                            <i class="fas fa-clipboard-check me-1"></i>Requests
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance_records.php">
                            <i class="fas fa-calendar-check me-1"></i>Records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-1"></i>Reports
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo $_SESSION['name'] ?? 'Teacher'; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../includes/auth.php?logout=1"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link " href="dashboard.php"aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            </li>
                    <a href="generate_otp.php" class="nav-link"style="color: black;">
                        <i class="fas fa-key me-2"></i>Generate OTP
                    </a>

                    <a href="mark_attendance.php" class="nav-link"style="color: black;">
                        <i class="fas fa-user-check me-2"></i>Mark Attendance
                    </a>

                   
                 <li class="nav-item">
                    <a class="nav-link text-black" href="attendance_requests.php">
                   <i class="fas fa-clipboard-check me-2"></i>Attendance Requests
                   <?php
                       $requests_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM attendance_requests WHERE status = 'pending'"));
                       if ($requests_count > 0) {
                        echo '<span class="badge bg-danger">' . $requests_count . '</span>';
                    }
                          ?>
                          </a>
                  </li>

                    <a href="attendance_records.php" class="nav-link"style="color: black;">
                        <i class="fas fa-calendar-check me-2"></i>Attendance Records
                    </a>

                    <a href="reports.php" class="nav-link"style="color: black;">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                     </a>

                    <a href="profile.php" class="nav-link"style="color: black;">
                        <i class="fas fa-user-cog me-2"></i>Profile
                    </a>
                    
                </div>
            </div>
      
      <div class="col-md-9 col-lg-10 ms-auto py-4">
          <div class="d-flex justify-content-between align-items-center mb-4">
              <h2><i class="fas fa-key me-2 text-danger"></i>Generate OTP</h2>
              <div>
                  <span><?php echo date('l, F d, Y'); ?></span>
              </div>
          </div>
          
          <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
          <?php endif; ?>
          
          <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
              <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
          <?php endif; ?>
          
          <!-- Active OTP Section -->
          <div class="card shadow-sm mb-4">
              <div class="card-header bg-danger text-white">
                  <h5 class="mb-0"><i class="fas fa-key me-2"></i>Active OTP</h5>
              </div>
              <div class="card-body">
                  <?php if ($hasOtp): ?>
                    <div class="text-center">
                        <?php if ($isActiveOtp): ?>
                            <h4 class="text-danger mb-3">Current Active OTP</h4>
                            <div class="position-relative">
                                <div class="active-otp-display <?php echo $newly_generated ? 'newly-generated' : ''; ?>" onclick="copyToClipboard('<?php echo $otpData['otp_code']; ?>')">
                                    <?php echo $otpData['otp_code']; ?>
                                    <div class="copy-tooltip" id="copy-tooltip">Copied!</div>
                                </div>
                            </div>
                            <div class="otp-timer countdown-timer" id="otpTimer">
                                <i class="fas fa-hourglass-half"></i> Valid for <?php echo $remainingMinutes; ?>:<?php echo str_pad($remainingSeconds, 2, '0', STR_PAD_LEFT); ?> minutes
                            </div>
                            
                            <div class="mt-4">
                                <button type="button" class="btn btn-outline-primary me-2" onclick="copyToClipboard('<?php echo $otpData['otp_code']; ?>')">
                                    <i class="fas fa-copy me-2"></i>Copy OTP
                                </button>
                                <button type="button" class="btn btn-danger" id="regenerateOtpBtn" onclick="confirmRegenerate()">
                                    <i class="fas fa-sync-alt me-2"></i>Generate New OTP
                                </button>
                            </div>
                        <?php else: ?>
                            <h4 class="text-muted mb-3">Last Generated OTP (Expired)</h4>
                            <div class="position-relative">
                                <div class="active-otp-display text-muted" style="opacity: 0.7;" onclick="copyToClipboard('<?php echo $otpData['otp_code']; ?>')">
                                    <?php echo $otpData['otp_code']; ?>
                                    <div class="copy-tooltip" id="copy-tooltip">Copied!</div>
                                </div>
                            </div>
                            <div class="otp-timer expired">
                                <i class="fas fa-times-circle"></i> Expired on <?php echo date('d M Y, h:i A', strtotime($otpData['expiry'])); ?>
                            </div>
                            
                            <div class="mt-4">
                                <button type="button" class="btn btn-outline-secondary me-2" onclick="copyToClipboard('<?php echo $otpData['otp_code']; ?>')">
                                    <i class="fas fa-copy me-2"></i>Copy OTP
                                </button>
                                <button type="button" class="btn btn-danger" id="generateNewOtpBtn" onclick="scrollToOtpForm()">
                                    <i class="fas fa-key me-2"></i>Generate New OTP
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert <?php echo $isActiveOtp ? 'alert-info' : 'alert-secondary'; ?> mt-4">
                            <div class="row">
                                <div class="col-md-6 text-start">
                                    <p><i class="fas fa-book me-2"></i><strong>Subject:</strong> <?php echo $otpData['subject_code']; ?> - <?php echo $otpData['subject_name']; ?></p>
                                    <p><i class="fas fa-graduation-cap me-2"></i><strong>Semester:</strong> <?php echo $otpData['semester_name']; ?></p>
                                    <p><i class="fas fa-chalkboard me-2"></i><strong>Class:</strong> <?php echo $otpData['class_name']; ?></p>
                                    <p><i class="fas fa-map-marker-alt me-2"></i><strong>Radius:</strong> <?php echo $otpData['radius']; ?> meters</p>
                                </div>
                                <div class="col-md-6 text-start">
                                    <p><i class="fas fa-clock me-2"></i><strong>Generated at:</strong> <?php echo date('d M Y, h:i A', strtotime($otpData['created_at'])); ?></p>
                                    <p><i class="fas fa-hourglass-end me-2"></i><strong>Expires at:</strong> <?php echo date('d M Y, h:i A', strtotime($otpData['expiry'])); ?></p>
                                    <p><i class="fas fa-stopwatch me-2"></i><strong>Duration:</strong> <?php echo isset($otpData['duration']) ? $otpData['duration'] : round((strtotime($otpData['expiry']) - strtotime($otpData['created_at'])) / 60); ?> minutes</p>
                                    <p><i class="fas fa-id-card me-2"></i><strong>OTP ID:</strong> <?php echo $otpData['id']; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($isActiveOtp): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Share this OTP only with students who are physically present in the classroom.
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-key fa-3x text-muted mb-3"></i>
                        <h5>No Active OTP</h5>
                        <p class="text-muted">You don't have any active OTPs. Generate an OTP using the form below.</p>
                    </div>
                <?php endif; ?>
              </div>
          </div>
          
          <div class="row">
              <div class="col-md-6 mb-4">
                  <div class="card shadow-sm">
                      <div class="card-header bg-danger text-white">
                          <h5 class="mb-0"><i class="fas fa-key me-2"></i>OTP Generation</h5>
                      </div>
                      <div class="card-body">
                          <form id="otpForm" action="" method="POST">
                              <input type="hidden" name="_form_submitted" value="1">
                              <div class="mb-3">
                                  <label for="subject_id" class="form-label">Select Subject</label>
                                  <select class="form-select" id="subject_id" name="subject_id" required>
                                      <option value="">Select Subject</option>
                                      <?php foreach ($teacherSubjects as $subject): ?>
                                          <option value="<?php echo $subject['subject_id']; ?>">
                                              <?php echo $subject['subject_code']; ?> - <?php echo $subject['subject_name']; ?> (<?php echo $subject['semester_name']; ?>)
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                              <div class="mb-3">
                                  <label for="radius" class="form-label">Geolocation Radius (meters)</label>
                                  <input type="range" class="form-range" id="radius" name="radius" min="10" max="100" value="<?php echo $default_radius; ?>" oninput="updateRadiusValue(this.value)">
                                  <div class="text-center" id="radiusValue"><?php echo $default_radius; ?> meters</div>
                              </div>
                              <div class="mb-3">
                                  <label for="duration" class="form-label">OTP Validity Duration (minutes)</label>
                                  <input type="range" class="form-range" id="duration" name="duration" min="1" max="<?php echo $otp_validity_minutes; ?>" value="<?php echo $otp_validity_minutes; ?>" oninput="updateDurationValue(this.value)">
                                  <div class="text-center" id="durationValue"><?php echo $otp_validity_minutes; ?> minutes</div>
                                  <div class="form-text text-muted">Maximum allowed duration: <?php echo $otp_validity_minutes; ?> minutes (set by admin)</div>
                              </div>
                              <div class="mb-3 form-check">
                                  <input type="checkbox" class="form-check-input" id="locationAccess" required>
                                  <label class="form-check-label" for="locationAccess">Allow location access</label>
                                  <div class="form-text">Your location is needed to verify student attendance within the specified radius.</div>
                              </div>
                              <div class="d-grid">
                                  <button type="submit" class="btn btn-danger" id="generateOtpBtn">
                                      <i class="fas fa-key me-2"></i>Generate OTP
                                  </button>
                              </div>
                          </form>
                      </div>
                  </div>
              </div>
              
              <div class="col-md-6 mb-4">
                  <div class="card shadow-sm">
                      <div class="card-header bg-danger text-white">
                          <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>OTP Information</h5>
                      </div>
                      <div class="card-body">
                          <div class="alert alert-info">
                              <h5><i class="fas fa-lightbulb me-2"></i>How OTP Works</h5>
                              <p>The One-Time Password (OTP) system allows students to mark their attendance by entering the OTP you generate.</p>
                              <ul>
                                  <li>You can set the OTP validity duration (up to <?php echo $otp_validity_minutes; ?> minutes as configured by admin)</li>
                                  <li>Students must be within the specified radius to submit the OTP</li>
                                  <li>You can generate a new OTP at any time</li>
                                  <li>OTPs are specific to the selected subject</li>
                              </ul>
                          </div>
                          <div class="alert alert-warning">
                              <h5><i class="fas fa-exclamation-triangle me-2"></i>Important Notes</h5>
                              <p>Please ensure the following for proper attendance marking:</p>
                              <ul>
                                  <li>Generate OTP only when you are in the classroom</li>
                                  <li>Set an appropriate radius based on classroom size</li>
                                  <li>Share the OTP with students present in the class only</li>
                                  <li>Verify that all present students have marked their attendance</li>
                              </ul>
                          </div>
                      </div>
                  </div>
              </div>
          </div>
          
          <!-- Recent OTPs Section -->
          <div class="card shadow-sm mb-4">
              <div class="card-header bg-danger text-white">
                  <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recently Generated OTPs</h5>
              </div>
              <div class="card-body">
                  <?php if (count($recentOtps) > 0): ?>
                  <div class="table-responsive">
                      <table class="table table-hover recent-otp-table">
                          <thead>
                              <tr>
                                  <th>OTP Code</th>
                                  <th>Subject</th>
                                  <th>Generated</th>
                                  <th>Expiry</th>
                                  <th>Duration</th>
                                  <th>Status</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php foreach ($recentOtps as $otp): ?>
                              <tr class="<?php echo ($otp['status'] === 'Active') ? 'active-otp' : ''; ?> <?php echo ($newly_generated && $otp['otp_code'] === $otp_code) ? 'newly-generated' : ''; ?>">
                                  <td class="otp-code"><?php echo $otp['otp_code']; ?></td>
                                  <td><?php echo $otp['subject_code']; ?> - <?php echo $otp['subject_name']; ?></td>
                                  <td><?php echo date('d M Y, h:i A', strtotime($otp['created_at'])); ?></td>
                                  <td><?php echo date('d M Y, h:i A', strtotime($otp['expiry'])); ?></td>
                                  <td><?php echo isset($otp['duration']) ? $otp['duration'] : round((strtotime($otp['expiry']) - strtotime($otp['created_at'])) / 60); ?> min</td>
                                  <td>
                                      <span class="badge <?php echo ($otp['status'] === 'Active') ? 'bg-success' : 'bg-danger'; ?>">
                                          <?php echo $otp['status']; ?>
                                          <?php if ($otp['status'] === 'Active'): ?>
                                          <span id="otp-timer-small-<?php echo $otp['id']; ?>" class="countdown-timer">
                                              (<?php echo $otp['remaining_minutes']; ?>:<?php echo str_pad($otp['remaining_seconds'], 2, '0', STR_PAD_LEFT); ?>)
                                          </span>
                                          <?php endif; ?>
                                      </span>
                                  </td>
                              </tr>
                              <?php endforeach; ?>
                          </tbody>
                      </table>
                  </div>
                  <?php else: ?>
                  <div class="text-center py-4">
                      <i class="fas fa-history fa-3x text-muted mb-3"></i>
                      <h5>No OTP History</h5>
                      <p class="text-muted">You haven't generated any OTPs yet.</p>
                  </div>
                  <?php endif; ?>
              </div>
          </div>
          
          <div class="row">
              <div class="col-md-12">
                  <div class="card shadow-sm">
                      <div class="card-header bg-danger text-white">
                          <h5 class="mb-0"><i class="fas fa-book me-2"></i>My Subjects</h5>
                      </div>
                      <div class="card-body">
                          <?php if (count($subjectsBySemester) > 0): ?>
                          <?php foreach ($subjectsBySemester as $semesterId => $semesterData): ?>
                          <div class="mb-4">
                              <div class="semester-header text-danger">
                                  <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($semesterData['semester_name']); ?>
                              </div>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Subject Code</th>
                                              <th>Subject Name</th>
                                              <th>Actions</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($semesterData['subjects'] as $subject): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                              <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                              <td>
                                                  <form method="post" action="" class="d-inline">
                                                      <input type="hidden" name="subject_id" value="<?php echo $subject['subject_id']; ?>">
                                                      <button type="button" class="btn btn-sm btn-danger generate-otp-btn" data-subject-id="<?php echo $subject['subject_id']; ?>">
                                                          <i class="fas fa-key"></i> Generate OTP
                                                      </button>
                                                  </form>
                                                  <a href="mark_attendance.php?subject_id=<?php echo $subject['subject_id']; ?>" class="btn btn-sm btn-secondary">
                                                      <i class="fas fa-clipboard-check"></i> Mark Attendance
                                                  </a>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                          </div>
                          <?php endforeach; ?>
                          <?php else: ?>
                          <div class="text-center py-5">
                              <i class="fas fa-book fa-4x text-muted mb-3"></i>
                              <h5>No Subjects Assigned</h5>
                              <p class="text-muted">You haven't been assigned to any subjects yet. Please contact the administrator.</p>
                          </div>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
<script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
  // Update radius value display
  function updateRadiusValue(val) {
      document.getElementById('radiusValue').innerText = val + ' meters';
  }

  // Update duration value display
  function updateDurationValue(val) {
      document.getElementById('durationValue').innerText = val + ' minutes';
  }
  
  // OTP Timer
  <?php if ($hasOtp && $isActiveOtp): ?>
  document.addEventListener('DOMContentLoaded', function() {
      const now = new Date();
      const expiry = new Date('<?php echo $otpData['expiry']; ?>');
      const remainingSeconds = Math.max(0, Math.floor((expiry - now) / 1000));
    
      if (remainingSeconds > 0) {
          const timerDisplay = document.getElementById('otpTimer');
          if (timerDisplay) {
              timerDisplay.classList.add('countdown-timer');
              startOtpTimer(remainingSeconds, timerDisplay);
          }
        
          // Start timers for small OTP displays in the table
          <?php foreach ($recentOtps as $otp): ?>
              <?php if ($otp['status'] === 'Active'): ?>
                  const smallTimerDisplay<?php echo $otp['id']; ?> = document.getElementById('otp-timer-small-<?php echo $otp['id']; ?>');
                  if (smallTimerDisplay<?php echo $otp['id']; ?>) {
                      smallTimerDisplay<?php echo $otp['id']; ?>.classList.add('countdown-timer');
                      const otpExpiry<?php echo $otp['id']; ?> = new Date('<?php echo $otp['expiry']; ?>');
                      const otpRemainingSeconds<?php echo $otp['id']; ?> = Math.max(0, Math.floor((otpExpiry<?php echo $otp['id']; ?> - now) / 1000));
                      startSmallOtpTimer(otpRemainingSeconds<?php echo $otp['id']; ?>, smallTimerDisplay<?php echo $otp['id']; ?>);
                  }
              <?php endif; ?>
          <?php endforeach; ?>
      }
  });
  <?php endif; ?>
  
  // Get location when generating OTP
  document.getElementById('otpForm')?.addEventListener('submit', function(e) {
      e.preventDefault();
      
      // Validate subject selection
      const subjectId = document.getElementById('subject_id').value;
      if (!subjectId) {
          alertify.error('Please select a subject');
          return;
      }
      
      // Check if location access is checked
      const locationAccess = document.getElementById('locationAccess').checked;
      if (!locationAccess) {
          alertify.error('Please allow location access to generate OTP');
          return;
      }
      
      // Show loading indicator
      const generateBtn = document.getElementById('generateOtpBtn');
      generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Getting location...';
      generateBtn.disabled = true;
      
      // Get location
      if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(
              function(position) {
                  // Add location to form
                  const form = document.getElementById('otpForm');
                  
                  const latInput = document.createElement('input');
                  latInput.type = 'hidden';
                  latInput.name = 'latitude';
                  latInput.value = position.coords.latitude;
                  form.appendChild(latInput);
                  
                  const lngInput = document.createElement('input');
                  lngInput.type = 'hidden';
                  lngInput.name = 'longitude';
                  lngInput.value = position.coords.longitude;
                  form.appendChild(lngInput);
                  
                  // Submit form
                  form.submit();
              },
              function(error) {
                  // Reset button
                  generateBtn.innerHTML = '<i class="fas fa-key me-2"></i>Generate OTP';
                  generateBtn.disabled = false;
                  
                  // Show error message
                  let errorMessage = 'Error getting location';
                  switch(error.code) {
                      case error.PERMISSION_DENIED:
                          errorMessage = 'Location permission denied. Please allow location access in your browser settings.';
                          break;
                      case error.POSITION_UNAVAILABLE:
                          errorMessage = 'Location information is unavailable.';
                          break;
                      case error.TIMEOUT:
                          errorMessage = 'Location request timed out.';
                          break;
                  }
                  alertify.error(errorMessage);
              },
              {
                  enableHighAccuracy: true,
                  timeout: 10000,
                  maximumAge: 0
              }
          );
      } else {
          // Reset button
          generateBtn.innerHTML = '<i class="fas fa-key me-2"></i>Generate OTP';
          generateBtn.disabled = false;
          
          alertify.error('Geolocation is not supported by this browser.');
      }
  });
  
  // Handle quick generate OTP buttons
  document.querySelectorAll('.generate-otp-btn').forEach(button => {
      button.addEventListener('click', function() {
          const subjectId = this.getAttribute('data-subject-id');
          document.getElementById('subject_id').value = subjectId;
          
          // Check if location checkbox exists (not in current OTP view)
          const locationCheckbox = document.getElementById('locationAccess');
          if (locationCheckbox) {
              locationCheckbox.checked = true;
          }
          
          // Scroll to form
          document.getElementById('generateOtpBtn').scrollIntoView({ behavior: 'smooth' });
          
          // Focus on generate button
          setTimeout(() => {
              document.getElementById('generateOtpBtn').focus();
          }, 500);
      });
  });
  
  // Confirm regenerate OTP
  function confirmRegenerate() {
      alertify.confirm(
          'Regenerate OTP',
          'Are you sure you want to generate a new OTP? The current OTP will be invalidated.',
          function() {
              // Show loading indicator
              const regenerateBtn = document.getElementById('regenerateOtpBtn');
              regenerateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Getting location...';
              regenerateBtn.disabled = true;
              
              // Get location
              if (navigator.geolocation) {
                  navigator.geolocation.getCurrentPosition(
                      function(position) {
                          // Create form for regeneration
                          const form = document.createElement('form');
                          form.method = 'POST';
                          form.action = '';

                          // Add hidden field to prevent resubmission on refresh
                          const formSubmittedInput = document.createElement('input');
                          formSubmittedInput.type = 'hidden';
                          formSubmittedInput.name = '_form_submitted';
                          formSubmittedInput.value = '1';
                          form.appendChild(formSubmittedInput);
                          
                          // Add subject_id from current OTP
                          const subjectInput = document.createElement('input');
                          subjectInput.type = 'hidden';
                          subjectInput.name = 'subject_id';
                          subjectInput.value = '<?php echo $otpData["subject_id"] ?? ""; ?>';
                          form.appendChild(subjectInput);
                          
                          // Add class_id from current OTP
                          const classInput = document.createElement('input');
                          classInput.type = 'hidden';
                          classInput.name = 'class_id';
                          classInput.value = '<?php echo $otpData["class_id"] ?? "0"; ?>';
                          form.appendChild(classInput);
                          
                          // Add location data
                          const latInput = document.createElement('input');
                          latInput.type = 'hidden';
                          latInput.name = 'latitude';
                          latInput.value = position.coords.latitude;
                          form.appendChild(latInput);
                          
                          const lngInput = document.createElement('input');
                          lngInput.type = 'hidden';
                          lngInput.name = 'longitude';
                          lngInput.value = position.coords.longitude;
                          form.appendChild(lngInput);
                          
                          // Add radius
                          const radiusInput = document.createElement('input');
                          radiusInput.type = 'hidden';
                          radiusInput.name = 'radius';
                          radiusInput.value = '<?php echo $otpData["radius"] ?? "50"; ?>';
                          form.appendChild(radiusInput);
                          
                          // Add duration
                          const durationInput = document.createElement('input');
                          durationInput.type = 'hidden';
                          durationInput.name = 'duration';
                          durationInput.value = document.getElementById('duration')?.value || '<?php echo $otpData["duration"] ?? $otp_validity_minutes; ?>';
                          form.appendChild(durationInput);
                          
                          // Add a hidden field to prevent form resubmission
                          const resubmitCheck = document.createElement('input');
                          resubmitCheck.type = 'hidden';
                          resubmitCheck.name = '_form_resubmit';
                          resubmitCheck.value = '1';
                          form.appendChild(resubmitCheck);
                          
                          // Submit form
                          document.body.appendChild(form);
                          form.submit();
                      },
                      function(error) {
                          // Reset button
                          regenerateBtn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Generate New OTP';
                          regenerateBtn.disabled = false;
                          
                          // Show error message
                          let errorMessage = 'Error getting location';
                          switch(error.code) {
                              case error.PERMISSION_DENIED:
                                  errorMessage = 'Location permission denied. Please allow location access in your browser settings.';
                                  break;
                              case error.POSITION_UNAVAILABLE:
                                  errorMessage = 'Location information is unavailable.';
                                  break;
                              case error.TIMEOUT:
                                  errorMessage = 'Location request timed out.';
                                  break;
                          }
                          alertify.error(errorMessage);
                      },
                      {
                          enableHighAccuracy: true,
                          timeout: 10000,
                          maximumAge: 0
                      }
                  );
              } else {
                  // Reset button
                  regenerateBtn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>Generate New OTP';
                  regenerateBtn.disabled = false;
                  
                  alertify.error('Geolocation is not supported by this browser.');
              }
          },
          function() {
              // User canceled
          }
      );
  }
  
  // Also update the OTP Timer JavaScript function to make it smoother and more accurate
  // Find the startOtpTimer function:
  function startOtpTimer(seconds, display) {
      let timer = seconds;
      const interval = setInterval(function () {
          const minutes = parseInt(timer / 60, 10);
          const seconds = parseInt(timer % 60, 10);
        
          const timeString = minutes + ":" + (seconds < 10 ? "0" + seconds : seconds);
        
          if (display) {
              display.innerHTML = '<i class="fas fa-hourglass-half"></i> Valid for ' + timeString + ' minutes';
          }
        
          if (--timer < 0) {
              clearInterval(interval);
              if (display) {
                  display.innerHTML = '<i class="fas fa-times-circle"></i> Expired';
                  display.classList.add("expired");
              }
              
              // Reload the page after expiry
              setTimeout(() => {
                  location.reload();
              }, 3000);
          }
      }, 1000);
  }

  // Replace it with this improved version:
  function startOtpTimer(seconds, display) {
      const startTime = Date.now();
      const endTime = startTime + (seconds * 1000);
      
      const interval = setInterval(function () {
          const now = Date.now();
          const remainingMs = Math.max(0, endTime - now);
          const remainingSeconds = Math.floor(remainingMs / 1000);
          
          const minutes = Math.floor(remainingSeconds / 60);
          const seconds = remainingSeconds % 60;
          
          const timeString = minutes + ":" + (seconds < 10 ? "0" + seconds : seconds);
          
          if (display) {
              display.innerHTML = '<i class="fas fa-hourglass-half"></i> Valid for ' + timeString + ' minutes';
              
              // Add warning animation when less than 60 seconds remain
              if (remainingSeconds <= 60 && remainingSeconds > 0) {
                  display.classList.add('countdown-warning');
              }
          }
          
          if (remainingMs <= 0) {
              clearInterval(interval);
              if (display) {
                  display.innerHTML = '<i class="fas fa-times-circle"></i> Expired';
                  display.classList.add("expired");
                  display.classList.remove("countdown-warning");
              }
              
              // Reload the page after expiry
              setTimeout(() => {
                  location.reload();
              }, 3000);
          }
      }, 100); // Update 10 times per second for smoother countdown
      
      return interval;
  }
  
  // Small OTP Timer function for table entries
  function startSmallOtpTimer(seconds, display) {
      let timer = seconds;
      const interval = setInterval(function () {
          const minutes = parseInt(timer / 60, 10);
          const seconds = parseInt(timer % 60, 10);
        
          const timeString = minutes + ":" + (seconds < 10 ? "0" + seconds : seconds);
        
          if (display) {
              display.textContent = '(' + timeString + ')';
          }
        
          if (--timer < 0) {
              clearInterval(interval);
              if (display) {
                  display.textContent = "(Expired)";
                  
                  // Reload the page after expiry
                  setTimeout(() => {
                      location.reload();
                  }, 3000);
              }
          }
      }, 1000);
  }

  // Replace with:
  function startSmallOtpTimer(seconds, display) {
    const startTime = Date.now();
    const endTime = startTime + (seconds * 1000);
    
    const interval = setInterval(function () {
        const now = Date.now();
        const remainingMs = Math.max(0, endTime - now);
        const remainingSeconds = Math.floor(remainingMs / 1000);
        
        const minutes = Math.floor(remainingSeconds / 60);
        const seconds = remainingSeconds % 60;
        
        const timeString = minutes + ":" + (seconds < 10 ? "0" + seconds : seconds);
        
        if (display) {
            display.textContent = '(' + timeString + ')';
            
            // Add warning animation when less than 60 seconds remain
            if (remainingSeconds <= 60 && remainingSeconds > 0) {
                display.classList.add('countdown-warning');
            }
        }
        
        if (remainingMs <= 0) {
            clearInterval(interval);
            if (display) {
                display.textContent = "(Expired)";
                display.classList.remove("countdown-warning");
            }
        }
    }, 100); // Update 10 times per second for smoother countdown
    
    return interval;
}
  
  // Copy OTP to clipboard
  function copyToClipboard(text) {
      navigator.clipboard.writeText(text).then(
          function() {
              // Show tooltip
              const tooltip = document.getElementById('copy-tooltip');
              tooltip.classList.add('show');
              
              // Hide tooltip after 2 seconds
              setTimeout(() => {
                  tooltip.classList.remove('show');
              }, 2000);
              
              alertify.success('OTP copied to clipboard');
          },
          function() {
              alertify.error('Failed to copy OTP');
              
              // Fallback for browsers that don't support clipboard API
              const tempInput = document.createElement('input');
              tempInput.value = text;
              document.body.appendChild(tempInput);
              tempInput.select();
              document.execCommand('copy');
              document.body.removeChild(tempInput);
              alertify.success('OTP copied to clipboard');
          }
      );
  }

  // Scroll to OTP form
  function scrollToOtpForm() {
      document.getElementById('otpForm').scrollIntoView({ behavior: 'smooth' });
  }
</script>
<?php if (isset($_SESSION['message']) && isset($_SESSION['message_type'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        alertify.notify('<?php echo $_SESSION['message']; ?>', '<?php echo $_SESSION['message_type']; ?>', 5);
    });
</script>
<?php 
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
endif; 
?>
</body>
</html>

