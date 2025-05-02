<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Get student ID and semester
$student_id = $_SESSION['user_id'];
$semester_id = null;

// Get student details including semester
$studentQuery = "SELECT u.*, s.name as semester_name 
                FROM users u 
                JOIN semesters s ON u.semester = s.id 
                WHERE u.id = ? AND u.role = 'student'";
$stmt = mysqli_prepare($conn, $studentQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$studentResult = mysqli_stmt_get_result($stmt);
$studentData = mysqli_fetch_assoc($studentResult);

if ($studentData) {
    $semester_id = $studentData['semester'];
    $semester_name = $studentData['semester_name'];
    $student_name = $studentData['name'];
    $student_roll = $studentData['roll_no'];
}

// Process OTP submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_code = trim($_POST['otp_code']);
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    
    // Validate OTP
    if (empty($otp_code)) {
        $message = 'Please enter the OTP code.';
        $message_type = 'error';
    } else {
        // Check if OTP exists and is valid in the otp table
        $otpQuery = "SELECT o.*, s.name as subject_name, s.code as subject_code, s.semester_id,
                    u.name as teacher_name
                    FROM otp o
                    JOIN subjects s ON o.subject_id = s.id
                    JOIN users u ON o.teacher_id = u.id
                    WHERE o.otp_code = ? AND o.expiry > NOW()";
        $stmt = mysqli_prepare($conn, $otpQuery);
        mysqli_stmt_bind_param($stmt, "s", $otp_code);
        mysqli_stmt_execute($stmt);
        $otpResult = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($otpResult) === 0) {
            $message = 'Invalid or expired OTP code.';
            $message_type = 'error';
        } else {
            $otpData = mysqli_fetch_assoc($otpResult);
            $teacher_id = $otpData['teacher_id'];
            $class_id = $otpData['class_id'];
            $subject_id = $otpData['subject_id'];
            $otp_latitude = $otpData['latitude'];
            $otp_longitude = $otpData['longitude'];
            $radius = $otpData['radius'];
            $otp_id = $otpData['id'];
            
            // Check if student's semester matches the subject's semester
            if ($semester_id != $otpData['semester_id']) {
                $message = 'This OTP is not for your semester.';
                $message_type = 'error';
            } else {
                // Check if attendance is already marked for today
                $today = date('Y-m-d');
                $attendanceCheckQuery = "SELECT id FROM attendance
                                       WHERE student_id = ? AND subject_id = ? AND date = ?";
                $stmt = mysqli_prepare($conn, $attendanceCheckQuery);
                mysqli_stmt_bind_param($stmt, "iis", $student_id, $subject_id, $today);
                mysqli_stmt_execute($stmt);
                $attendanceCheckResult = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($attendanceCheckResult) > 0) {
                    $message = 'Your attendance has already been marked for this class today.';
                    $message_type = 'warning';
                } else {
                    // Check location if coordinates are provided
                    $locationValid = true;
                    if ($latitude !== null && $longitude !== null && $otp_latitude && $otp_longitude) {
                        // Use the OTP generation location (teacher's location) as the reference point
                        $distance = calculateDistance($latitude, $longitude, $otp_latitude, $otp_longitude);
                        
                        // If distance is very small (less than 10 meters), consider it as same location
                        if ($distance < 10) {
                            $distance = 0;
                        }
                        
                        // For debugging, log the coordinates and distance
                        error_log("Student location: $latitude, $longitude");
                        error_log("Teacher location: $otp_latitude, $otp_longitude");
                        error_log("Distance: $distance meters, Radius: $radius meters");
                        
                        if ($distance > $radius) {
                            $locationValid = false;
                            $message = 'You are not within the required distance from the class. Distance: ' . round($distance) . 'm, Required: ' . $radius . 'm';
                            $message_type = 'error';
                        }
                    }
                    
                    if ($locationValid) {
                        // Mark attendance
                        $markAttendanceQuery = "INSERT INTO attendance (student_id, teacher_id, class_id, subject_id, status, date, marked_at, created_at)
                                             VALUES (?, ?, ?, ?, 'present', ?, NOW(), NOW())";
                        $stmt = mysqli_prepare($conn, $markAttendanceQuery);
                        mysqli_stmt_bind_param($stmt, "iiiis", $student_id, $teacher_id, $class_id, $subject_id, $today);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $message = 'Attendance marked successfully for ' . $otpData['subject_name'] . '.';
                            $message_type = 'success';
                            
                            // FIXED: Wrap logActivity in try-catch to handle missing table
                            try {
                                // Log the attendance
                                logActivity($conn, $student_id, 'attendance_marked', 'Student marked attendance for ' . $otpData['subject_name'] . ' using OTP');
                            } catch (Exception $e) {
                                // Silently ignore activity logging errors
                                error_log("Activity logging failed: " . $e->getMessage());
                            }
                        } else {
                            $message = 'Failed to mark attendance. Please try again.';
                            $message_type = 'error';
                        }
                    }
                }
            }
        }
    }
    
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
}

// Check if today is a holiday
$today_date = date('Y-m-d');
$holidayQuery = "SELECT * FROM holidays WHERE date = ?";
$stmt = mysqli_prepare($conn, $holidayQuery);
mysqli_stmt_bind_param($stmt, "s", $today_date);
mysqli_stmt_execute($stmt);
$holidayResult = mysqli_stmt_get_result($stmt);
$isHoliday = mysqli_num_rows($holidayResult) > 0;
$holidayData = $isHoliday ? mysqli_fetch_assoc($holidayResult) : null;

// Get today's attendance records
$todayAttendanceQuery = "SELECT a.*, s.name as subject_name, s.code as subject_code, c.name as class_name
                       FROM attendance a
                       JOIN subjects s ON a.subject_id = s.id
                       JOIN classes c ON a.class_id = c.id
                       WHERE a.student_id = ? AND a.date = ?";
$stmt = mysqli_prepare($conn, $todayAttendanceQuery);
mysqli_stmt_bind_param($stmt, "is", $student_id, $today_date);
mysqli_stmt_execute($stmt);
$todayAttendance = mysqli_stmt_get_result($stmt);
$attendanceRecords = [];
while ($record = mysqli_fetch_assoc($todayAttendance)) {
    $attendanceRecords[$record['subject_id']] = $record;
}

// Get student's current location from form or session
$student_latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
$student_longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;

// Store location in session for future use
if ($student_latitude && $student_longitude) {
    $_SESSION['student_latitude'] = $student_latitude;
    $_SESSION['student_longitude'] = $student_longitude;
} else {
    // Try to get from session
    $student_latitude = isset($_SESSION['student_latitude']) ? $_SESSION['student_latitude'] : null;
    $student_longitude = isset($_SESSION['student_longitude']) ? $_SESSION['student_longitude'] : null;
}

// Get all available OTPs for this student's semester
$allOTPs = [];
$availableOTPsQuery = "SELECT o.*, s.name as subject_name, s.code as subject_code
                     FROM otp o
                     JOIN subjects s ON o.subject_id = s.id
                     WHERE o.expiry > NOW() AND s.semester_id = ?
                     ORDER BY o.created_at DESC";
$stmt = mysqli_prepare($conn, $availableOTPsQuery);
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$availableOTPsResult = mysqli_stmt_get_result($stmt);

// Filter OTPs based on attendance and location
$availableOTPs = []; // OTPs within range
$outOfRangeOTPs = []; // OTPs outside range

while ($otp = mysqli_fetch_assoc($availableOTPsResult)) {
    // Check if attendance is already marked for this subject today
    $subject_id = $otp['subject_id'];
    if (isset($attendanceRecords[$subject_id])) {
        continue; // Skip if attendance already marked
    }
    
    // Add to all OTPs list
    $allOTPs[] = $otp;
    
    // Check if student is within range
    if ($student_latitude && $student_longitude && $otp['latitude'] && $otp['longitude']) {
        $distance = calculateDistance(
            $student_latitude, 
            $student_longitude, 
            $otp['latitude'], 
            $otp['longitude']
        );
        
        $otp['distance'] = round($distance);
        
        if ($distance <= $otp['radius']) {
            // Student is within range, show OTP
            $availableOTPs[] = $otp;
        } else {
            // Student is outside range, don't show OTP
            $outOfRangeOTPs[] = $otp;
        }
    } else {
        // No location data yet, will be filtered by JavaScript
        $outOfRangeOTPs[] = $otp;
    }
}

// Function to calculate distance between two points using Haversine formula
if (!function_exists('calculateDistance')) {
    function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        // Convert degrees to radians
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        // Haversine formula
        $dlat = $lat2 - $lat1;
        $dlon = $lat2 - $lon1;
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $radius = 6371000; // Earth's radius in meters
        $distance = $radius * $c;
        
        return $distance;
    }
}

// ADDED: Safe wrapper for logActivity function
if (!function_exists('safeLogActivity')) {
    function safeLogActivity($conn, $user_id, $action, $description) {
        try {
            // Check if activity_logs table exists
            $tableCheckQuery = "SHOW TABLES LIKE 'activity_logs'";
            $result = mysqli_query($conn, $tableCheckQuery);
            
            if (mysqli_num_rows($result) > 0) {
                // Table exists, proceed with logging
                logActivity($conn, $user_id, $action, $description);
            } else {
                // Table doesn't exist, just log to error_log
                error_log("Activity log (not saved): User $user_id - $action - $description");
            }
        } catch (Exception $e) {
            // Silently ignore errors
            error_log("Activity logging failed: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit OTP - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .otp-badge {
            font-size: 1.2rem;
            letter-spacing: 2px;
        }
        .distance-badge {
            font-size: 0.8rem;
        }
        #outOfRangeSection {
            display: none;
        }
        .location-status {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-user-graduate me-2"></i>BCA Attendance
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance.php">
                            <i class="fas fa-calendar-check me-1"></i>My Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="submit_otp.php">
                            <i class="fas fa-key me-1"></i>Submit OTP
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="requests.php">
                            <i class="fas fa-clipboard-list me-1"></i>My Requests
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($student_name ?? 'Student'); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
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
                        <li class="nav-item">
                            <a class="nav-link" href="attendance.php"aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-calendar-check me-2"></i>My Attendance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="submit_otp.php"aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-key me-2"></i>Submit OTP
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="requests.php"aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-clipboard-list me-2"></i>My Requests
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profile.php"aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-user-cog me-2"></i>Profile
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-key me-2"></i>Submit OTP</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <span class="badge bg-danger"><?php echo htmlspecialchars($semester_name ?? 'No Semester'); ?></span>
                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($student_roll ?? 'No Roll Number'); ?></span>
                        </div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>

                <?php if ($isHoliday): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-calendar-day me-2"></i>
                    <strong>Today is a holiday:</strong> <?php echo htmlspecialchars($holidayData['name']); ?>
                </div>
                <?php endif; ?>

                <!-- Location Status Alert -->
                <div id="locationStatus" class="alert alert-info location-status">
                    <i class="fas fa-location-arrow me-2"></i>
                    <span id="locationMessage">Requesting your location...</span>
                </div>

                <!-- OTP Submission Form -->
                <div class="row mb-4">
                    <div class="col-md-6 mx-auto">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Enter OTP Code</h5>
                            </div>
                            <div class="card-body">
                                <form id="otpForm" method="POST" action="">
                                    <div class="mb-3">
                                        <label for="otp_code" class="form-label">OTP Code</label>
                                        <input type="text" class="form-control form-control-lg text-center" id="otp_code" name="otp_code" placeholder="Enter 6-digit OTP" maxlength="6" required>
                                    </div>
                                    <input type="hidden" id="latitude" name="latitude" value="">
                                    <input type="hidden" id="longitude" name="longitude" value="">
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-danger btn-lg">
                                            <i class="fas fa-check-circle me-2"></i>Submit OTP
                                        </button>
                                    </div>
                                </form>
                                <div class="text-center mt-3">
                                    <p class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Enter the OTP code provided by your teacher to mark your attendance.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Available OTPs (within range) -->
                <div id="availableOTPsSection" class="row mb-4 <?php echo empty($availableOTPs) ? 'd-none' : ''; ?>">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Available OTPs (Within Range)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($availableOTPs)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>OTP Code</th>
                                                <th>Subject</th>
                                                <th>Distance</th>
                                                <th>Expires At</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="availableOTPsTable">
                                            <?php foreach ($availableOTPs as $otp): ?>
                                            <tr>
                                                <td><span class="badge bg-success otp-badge"><?php echo $otp['otp_code']; ?></span></td>
                                                <td><?php echo $otp['subject_code']; ?> - <?php echo $otp['subject_name']; ?></td>
                                                <td>
                                                    <span class="badge bg-info distance-badge">
                                                        <i class="fas fa-map-marker-alt me-1"></i> <?php echo isset($otp['distance']) ? $otp['distance'] . 'm' : 'Calculating...'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('h:i A', strtotime($otp['expiry'])); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" onclick="useOTP('<?php echo $otp['otp_code']; ?>')">
                                                        <i class="fas fa-check-circle me-1"></i>Use
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4" id="noAvailableOTPs">
                                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                    <h5>No OTPs Available Within Range</h5>
                                    <p class="text-muted">You need to be within the required distance from the class to see available OTPs.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- OTPs Outside Range -->
                <div id="outOfRangeSection" class="row mb-4 <?php echo empty($outOfRangeOTPs) ? 'd-none' : ''; ?>">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>OTPs Outside Range</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-info-circle me-2"></i>
                                    These OTPs are available but you are not within the required distance from the class.
                                    Move closer to the classroom to access these OTPs.
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th>Distance</th>
                                                <th>Required</th>
                                                <th>Expires At</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="outOfRangeTable">
                                            <?php foreach ($outOfRangeOTPs as $otp): ?>
                                            <tr data-otp-id="<?php echo $otp['id']; ?>" 
                                                data-otp-code="<?php echo $otp['otp_code']; ?>"
                                                data-otp-lat="<?php echo $otp['latitude']; ?>"
                                                data-otp-lng="<?php echo $otp['longitude']; ?>"
                                                data-otp-radius="<?php echo $otp['radius']; ?>">
                                                <td><?php echo $otp['subject_code']; ?> - <?php echo $otp['subject_name']; ?></td>
                                                <td>
                                                    <span class="badge bg-danger distance-badge" id="distance-<?php echo $otp['id']; ?>">
                                                        <i class="fas fa-map-marker-alt me-1"></i> <?php echo isset($otp['distance']) ? $otp['distance'] . 'm' : 'Calculating...'; ?>
                                                    </span>
                                                </td>
                                                <td><span class="badge bg-secondary"><?php echo $otp['radius']; ?>m</span></td>
                                                <td><?php echo date('h:i A', strtotime($otp['expiry'])); ?></td>
                                                <td>
                                                    <span class="badge bg-danger">Out of Range</span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Attendance -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Today's Attendance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($attendanceRecords) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th>Class</th>
                                                <th>Status</th>
                                                <th>Marked At</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($attendanceRecords as $attendance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($attendance['subject_code']); ?> - <?php echo htmlspecialchars($attendance['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['class_name']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($attendance['status'] == 'present') ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($attendance['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('h:i A', strtotime($attendance['marked_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-clipboard fa-4x text-muted mb-3"></i>
                                    <h5>No Attendance Records</h5>
                                    <p class="text-muted">No attendance has been marked for you today.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script>
        // Store OTP data for dynamic filtering
        const outOfRangeOTPs = <?php echo json_encode($outOfRangeOTPs); ?>;
        let studentLat = null;
        let studentLng = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Get location when the page loads
            getLocation();
            
            // Focus on OTP input
            document.getElementById('otp_code').focus();
            
            // Handle form submission
            document.getElementById('otpForm').addEventListener('submit', function(e) {
                const otpCode = document.getElementById('otp_code').value.trim();
                if (otpCode.length !== 6) {
                    e.preventDefault();
                    alertify.error('Please enter a valid 6-digit OTP code.');
                    return false;
                }
                
                // If location is not available, try to get it again
                if (!document.getElementById('latitude').value || !document.getElementById('longitude').value) {
                    e.preventDefault();
                    alertify.error('Location is required. Please allow location access and try again.');
                    getLocation();
                    return false;
                }
            });
        });
        
        // Get user's location and filter OTPs based on distance
        function getLocation() {
            const locationStatus = document.getElementById('locationStatus');
            const locationMessage = document.getElementById('locationMessage');
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        studentLat = position.coords.latitude;
                        studentLng = position.coords.longitude;
                        
                        // Store location in hidden form fields
                        document.getElementById('latitude').value = studentLat;
                        document.getElementById('longitude').value = studentLng;
                        
                        // Update location status
                        locationStatus.classList.remove('alert-info', 'alert-danger');
                        locationStatus.classList.add('alert-success');
                        locationMessage.innerHTML = 'Location acquired successfully. OTPs within range will be displayed.';
                        
                        // Filter OTPs based on location
                        filterOTPsByDistance();
                    },
                    function(error) {
                        locationStatus.classList.remove('alert-info', 'alert-success');
                        locationStatus.classList.add('alert-danger');
                        
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                locationMessage.innerHTML = 'Location access denied. Please enable location services to see available OTPs.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                locationMessage.innerHTML = 'Location information is unavailable. Cannot determine if you are within range.';
                                break;
                            case error.TIMEOUT:
                                locationMessage.innerHTML = 'Location request timed out. Please refresh the page to try again.';
                                break;
                            default:
                                locationMessage.innerHTML = 'An unknown error occurred while getting your location.';
                                break;
                        }
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            } else {
                locationStatus.classList.remove('alert-info', 'alert-success');
                locationStatus.classList.add('alert-danger');
                locationMessage.innerHTML = 'Geolocation is not supported by this browser. Cannot determine if you are within range.';
            }
        }
        
        // Filter OTPs based on student's distance from OTP generation point
        function filterOTPsByDistance() {
            if (!studentLat || !studentLng) return;
            
            let hasAvailableOTPs = false;
            let hasOutOfRangeOTPs = false;
            
            // Process out-of-range OTPs
            const outOfRangeRows = document.querySelectorAll('#outOfRangeTable tr');
            outOfRangeRows.forEach(row => {
                const otpId = row.getAttribute('data-otp-id');
                const otpLat = parseFloat(row.getAttribute('data-otp-lat'));
                const otpLng = parseFloat(row.getAttribute('data-otp-lng'));
                const otpRadius = parseFloat(row.getAttribute('data-otp-radius'));
                const otpCode = row.getAttribute('data-otp-code');
                
                // Calculate distance
                const distance = calculateDistance(studentLat, studentLng, otpLat, otpLng);
                
                // Update distance display
                const distanceElement = document.getElementById(`distance-${otpId}`);
                if (distanceElement) {
                    distanceElement.innerHTML = `<i class="fas fa-map-marker-alt me-1"></i> ${Math.round(distance)}m`;
                }
                
                // Check if within radius
                if (distance <= otpRadius) {
                    // Move to available OTPs
                    const availableTable = document.getElementById('availableOTPsTable');
                    const newRow = document.createElement('tr');
                    newRow.innerHTML = `
                        <td><span class="badge bg-success otp-badge">${otpCode}</span></td>
                        <td>${row.cells[0].innerHTML}</td>
                        <td><span class="badge bg-info distance-badge"><i class="fas fa-map-marker-alt me-1"></i> ${Math.round(distance)}m</span></td>
                        <td>${row.cells[3].innerHTML}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="useOTP('${otpCode}')">
                                <i class="fas fa-check-circle me-1"></i>Use
                            </button>
                        </td>
                    `;
                    availableTable.appendChild(newRow);
                    
                    // Remove from out of range
                    row.remove();
                    
                    hasAvailableOTPs = true;
                } else {
                    hasOutOfRangeOTPs = true;
                }
            });
            
            // Show/hide sections based on content
            const availableSection = document.getElementById('availableOTPsSection');
            const outOfRangeSection = document.getElementById('outOfRangeSection');
            const noAvailableOTPs = document.getElementById('noAvailableOTPs');
            
            if (hasAvailableOTPs) {
                availableSection.classList.remove('d-none');
                if (noAvailableOTPs) {
                    noAvailableOTPs.classList.add('d-none');
                }
            } else {
                if (noAvailableOTPs) {
                    noAvailableOTPs.classList.remove('d-none');
                }
            }
            
            if (hasOutOfRangeOTPs) {
                outOfRangeSection.classList.remove('d-none');
            } else {
                outOfRangeSection.classList.add('d-none');
            }
        }
        
        // Calculate distance between two points
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000; // Earth's radius in meters
            const dLat = deg2rad(lat2 - lat1);
            const dLon = deg2rad(lon2 - lon1);
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) * 
                Math.sin(dLon/2) * Math.sin(dLon/2); 
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); 
            const distance = R * c;
            return distance;
        }
        
        function deg2rad(deg) {
            return deg * (Math.PI/180);
        }
        
        // Use OTP from available OTPs
        function useOTP(otpCode) {
            document.getElementById('otp_code').value = otpCode;
            alertify.success('OTP code applied. Click Submit to mark your attendance.');
        }
        
        // Periodically update distances and check for OTPs that come into range
        setInterval(function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        studentLat = position.coords.latitude;
                        studentLng = position.coords.longitude;
                        
                        // Update form fields
                        document.getElementById('latitude').value = studentLat;
                        document.getElementById('longitude').value = studentLng;
                        
                        // Re-filter OTPs
                        filterOTPsByDistance();
                    },
                    function(error) {
                        console.log("Error updating location: ", error);
                    },
                    { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
                );
            }
        }, 30000); // Update every 30 seconds
    </script>
    <?php
    if (isset($_SESSION['message'])) {
        echo "<script>
            alertify.notify('" . $_SESSION['message'] . "', '" . ($_SESSION['message_type'] ?? 'success') . "', 5);
        </script>";
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
    ?>
</body>
</html>
