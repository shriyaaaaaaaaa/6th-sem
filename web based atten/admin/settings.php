<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Initialize variables
$message = '';
$message_type = '';

// Get current settings
$settingsQuery = "SELECT * FROM settings WHERE id = 1";
$settingsResult = mysqli_query($conn, $settingsQuery);
$settings = mysqli_fetch_assoc($settingsResult);

// If settings don't exist, create default
if (!$settings) {
    // Generate a random teacher registration code
    $teacher_code = generateRandomCode(8);
    
    $createSettingsQuery = "INSERT INTO settings (
        distance_threshold, 
        otp_validity_minutes, 
        attendance_start_time,
        attendance_end_time,
        allow_manual_attendance,
        allow_attendance_requests,
        email_notifications,
        sms_notifications,
        min_attendance_percentage,
        max_teachers,
        teacher_registration_code,
        created_at
    ) VALUES (
        100, 
        15, 
        '" . date('Y') . "-06-01', 
        '" . (date('Y') + 1) . "-05-31',
        '09:00:00',
        '17:00:00',
        1,
        1,
        1,
        0,
        75,
        20,
        '$teacher_code',
        NOW()
    )";
    
    mysqli_query($conn, $createSettingsQuery);
    $settingsResult = mysqli_query($conn, $settingsQuery);
    $settings = mysqli_fetch_assoc($settingsResult);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $distance_threshold = filter_input(INPUT_POST, 'distance_threshold', FILTER_VALIDATE_INT);
    $otp_validity_minutes = filter_input(INPUT_POST, 'otp_validity_minutes', FILTER_VALIDATE_INT);
    $attendance_start_time = filter_input(INPUT_POST, 'attendance_start_time', FILTER_SANITIZE_STRING);
    $attendance_end_time = filter_input(INPUT_POST, 'attendance_end_time', FILTER_SANITIZE_STRING);
    $allow_manual_attendance = isset($_POST['allow_manual_attendance']) ? 1 : 0;
    $allow_attendance_requests = isset($_POST['allow_attendance_requests']) ? 1 : 0;
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $min_attendance_percentage = filter_input(INPUT_POST, 'min_attendance_percentage', FILTER_VALIDATE_INT);
    $max_teachers = filter_input(INPUT_POST, 'max_teachers', FILTER_VALIDATE_INT);
    
    // Generate new teacher code if requested
    if (isset($_POST['generate_new_code']) && $_POST['generate_new_code'] == 1) {
        $teacher_registration_code = generateRandomCode(8);
    } else {
        $teacher_registration_code = $settings['teacher_registration_code'];
    }
    
    // Validate inputs
    if ($distance_threshold === false || $distance_threshold < 10 || $distance_threshold > 1000) {
        $message = 'Distance threshold must be between 10 and 1000 meters.';
        $message_type = 'error';
    } elseif ($otp_validity_minutes === false || $otp_validity_minutes < 1 || $otp_validity_minutes > 60) {
        $message = 'OTP validity must be between 1 and 60 minutes.';
        $message_type = 'error';
    } elseif ($min_attendance_percentage === false || $min_attendance_percentage < 0 || $min_attendance_percentage > 100) {
        $message = 'Minimum attendance percentage must be between 0 and 100.';
        $message_type = 'error';
    } elseif ($max_teachers === false || $max_teachers < 1) {
        $message = 'Maximum teachers must be at least 1.';
        $message_type = 'error';
    } elseif (strtotime($attendance_start_time) >= strtotime($attendance_end_time)) {
        $message = 'Attendance end time must be after start time.';
        $message_type = 'error';
    } else {
        // Check if new max_teachers is less than current teacher count
        $teacherCountQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher'";
        $teacherCountResult = mysqli_query($conn, $teacherCountQuery);
        $teacherCount = mysqli_fetch_assoc($teacherCountResult)['count'];
        
        if ($max_teachers < $teacherCount) {
            $message = "Cannot set maximum teachers to $max_teachers as there are already $teacherCount teachers registered.";
            $message_type = 'error';
        } else {
            // Update settings
            $updateQuery = "UPDATE settings SET 
                distance_threshold = ?, 
                otp_validity_minutes = ?, 
                attendance_start_time = ?,
                attendance_end_time = ?,
                allow_manual_attendance = ?,
                allow_attendance_requests = ?,
                email_notifications = ?,
                sms_notifications = ?,
                min_attendance_percentage = ?,
                max_teachers = ?,
                teacher_registration_code = ?,
                updated_at = NOW()
                WHERE id = 1";
                
            $stmt = mysqli_prepare($conn, $updateQuery);
            mysqli_stmt_bind_param($stmt, 'iissiiiiiss', 
                $distance_threshold, 
                $otp_validity_minutes, 
                $attendance_start_time,
                $attendance_end_time,
                $allow_manual_attendance,
                $allow_attendance_requests,
                $email_notifications,
                $sms_notifications,
                $min_attendance_percentage,
                $max_teachers,
                $teacher_registration_code
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Settings updated successfully.';
                $message_type = 'success';
                
                // Refresh settings
                $settingsResult = mysqli_query($conn, $settingsQuery);
                $settings = mysqli_fetch_assoc($settingsResult);
            } else {
                $message = 'Error updating settings: ' . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
    
    // Set session message
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    
    // Redirect to avoid form resubmission
    header('Location: settings.php');
    exit;
}

// Handle database backup
if (isset($_GET['backup']) && $_GET['backup'] == 1) {
    // Set headers for download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bca_attendance_backup_' . date('Y-m-d_H-i-s') . '.sql"');
    
    // Get all tables
    $tables = [];
    $tablesResult = mysqli_query($conn, 'SHOW TABLES');
    while ($row = mysqli_fetch_row($tablesResult)) {
        $tables[] = $row[0];
    }
    
    $output = '';
    
    // Export each table
    foreach ($tables as $table) {
        // Table structure
        $output .= "DROP TABLE IF EXISTS `$table`;\n";
        $createTableResult = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
        $createTableRow = mysqli_fetch_row($createTableResult);
        $output .= $createTableRow[1] . ";\n\n";
        
        // Table data
        $dataResult = mysqli_query($conn, "SELECT * FROM `$table`");
        $numFields = mysqli_num_fields($dataResult);
        
        while ($row = mysqli_fetch_row($dataResult)) {
            $output .= "INSERT INTO `$table` VALUES(";
            for ($i = 0; $i < $numFields; $i++) {
                if (isset($row[$i])) {
                    $row[$i] = addslashes($row[$i]);
                    $row[$i] = str_replace("\n", "\\n", $row[$i]);
                    $output .= '"' . $row[$i] . '"';
                } else {
                    $output .= 'NULL';
                }
                
                if ($i < ($numFields - 1)) {
                    $output .= ',';
                }
            }
            $output .= ");\n";
        }
        
        $output .= "\n\n";
    }
    
    echo $output;
    exit;
}

// Get system stats
$totalStudentsQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'student'";
$totalStudentsResult = mysqli_query($conn, $totalStudentsQuery);
$totalStudents = mysqli_fetch_assoc($totalStudentsResult)['count'];

$totalTeachersQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher'";
$totalTeachersResult = mysqli_query($conn, $totalTeachersQuery);
$totalTeachers = mysqli_fetch_assoc($totalTeachersResult)['count'];

$totalAttendanceQuery = "SELECT COUNT(*) as count FROM attendance";
$totalAttendanceResult = mysqli_query($conn, $totalAttendanceQuery);
$totalAttendance = mysqli_fetch_assoc($totalAttendanceResult)['count'];

$totalHolidaysQuery = "SELECT COUNT(*) as count FROM holidays";
$totalHolidaysResult = mysqli_query($conn, $totalHolidaysQuery);
$totalHolidays = mysqli_fetch_assoc($totalHolidaysResult)['count'];

$totalRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests";
$totalRequestsResult = mysqli_query($conn, $totalRequestsQuery);
$totalRequests = mysqli_fetch_assoc($totalRequestsResult)['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-user-shield me-2"></i>Admin Dashboard
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
                        <a class="nav-link" href="students.php">
                            <i class="fas fa-user-graduate me-1"></i>Students
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="teachers.php">
                            <i class="fas fa-chalkboard-teacher me-1"></i>Teachers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="holidays.php">
                            <i class="fas fa-calendar-alt me-1"></i>Holidays
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance_requests.php">
                            <i class="fas fa-clipboard-check me-1"></i>Requests
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="attendance_records.php">
                            <i class="fas fa-calendar-check me-1"></i>Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-1"></i>Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="settings.php">
                            <i class="fas fa-cog me-1"></i>Settings
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>
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
                            <a class="nav-link active" href="dashboard.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="students.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-user-graduate me-2"></i>Students
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="teachers.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-chalkboard-teacher me-2"></i>Teachers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="holidays.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-calendar-alt me-2"></i>Holidays
                            </a>
                        </li>
                        <li class="nav-item">
                         <a class="nav-link" href="attendance_requests.php" aria-label="Attendance Requests" style="color: black;">
                             <i class="fas fa-clipboard-check me-2"></i>Requset
                                 <?php $requests_count =  mysqli_num_rows(mysqli_query($conn, "SELECT * FROM attendance_requests WHERE status = 'pending'"));?>
                                   <?= $requests_count > 0 ? '<span class="badge bg-danger ms-2">' . $requests_count . '</span>' : '' ?>
                         </a>
                         <?php if ($requests_count > 0) : ?>
                      <?php endif; ?>
                        </a>
                       </li>
                        <li class="nav-item">
                            <a class="nav-link" href="attendance_records.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-calendar-check me-2"></i>Attendance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reports.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-chart-bar me-2"></i>Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="settings.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-cog me-2"></i>Settings
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="col-md-9 col-lg-10 ms-auto py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-cog me-2"></i>System Settings</h2>
                    <div>
                        <a href="settings.php?backup=1" class="btn btn-success">
                            <i class="fas fa-database me-2"></i>Backup Database
                        </a>
                    </div>
                </div>
                
                <!-- System Stats -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card bg-primary text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Students</h6>
                                        <h2 class="mb-0"><?php echo $totalStudents; ?></h2>
                                    </div>
                                    <i class="fas fa-user-graduate fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Teachers</h6>
                                        <h2 class="mb-0"><?php echo $totalTeachers; ?> / <?php echo $settings['max_teachers']; ?></h2>
                                    </div>
                                    <i class="fas fa-chalkboard-teacher fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-info text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Attendance Records</h6>
                                        <h2 class="mb-0"><?php echo $totalAttendance; ?></h2>
                                    </div>
                                    <i class="fas fa-calendar-check fa-3x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Settings Form -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Attendance Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="settings.php">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="distance_threshold" class="form-label">Distance Threshold (meters)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="distance_threshold" name="distance_threshold" value="<?php echo htmlspecialchars($settings['distance_threshold']); ?>" min="10" max="1000" required>
                                        <span class="input-group-text">meters</span>
                                    </div>
                                    <div class="form-text">Maximum allowed distance from college for attendance marking</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="otp_validity_minutes" class="form-label">OTP Validity Duration</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="otp_validity_minutes" name="otp_validity_minutes" value="<?php echo htmlspecialchars($settings['otp_validity_minutes']); ?>" min="1" max="60" required>
                                        <span class="input-group-text">minutes</span>
                                    </div>
                                    <div class="form-text">How long an OTP remains valid after generation</div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="attendance_start_time" class="form-label">Attendance Start Time</label>
                                    <input type="time" class="form-control" id="attendance_start_time" name="attendance_start_time" value="<?php echo htmlspecialchars($settings['attendance_start_time']); ?>" required>
                                    <div class="form-text">When attendance marking begins each day</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="attendance_end_time" class="form-label">Attendance End Time</label>
                                    <input type="time" class="form-control" id="attendance_end_time" name="attendance_end_time" value="<?php echo htmlspecialchars($settings['attendance_end_time']); ?>" required>
                                    <div class="form-text">When attendance marking ends each day</div>
                                </div>
                            </div>
                            
                            <!-- Teacher Registration Settings -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="max_teachers" class="form-label">Maximum Number of Teachers</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="max_teachers" name="max_teachers" value="<?php echo htmlspecialchars($settings['max_teachers']); ?>" min="<?php echo $totalTeachers; ?>" required>
                                        <span class="input-group-text">teachers</span>
                                    </div>
                                    <div class="form-text">Maximum number of teachers that can register (currently <?php echo $totalTeachers; ?> registered)</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="teacher_registration_code" class="form-label">Teacher Registration Code</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="teacher_registration_code" value="<?php echo htmlspecialchars($settings['teacher_registration_code']); ?>" readonly>
                                        <button class="btn btn-outline-secondary" type="button" id="copyCodeBtn" onclick="copyCode()">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="generate_new_code" name="generate_new_code" value="1">
                                        <label class="form-check-label" for="generate_new_code">
                                            Generate new code on save
                                        </label>
                                    </div>
                                    <div class="form-text">This code is required for teacher registration</div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="min_attendance_percentage" class="form-label">Minimum Attendance Percentage</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="min_attendance_percentage" name="min_attendance_percentage" value="<?php echo htmlspecialchars($settings['min_attendance_percentage']); ?>" min="0" max="100" required>
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <div class="form-text">Required attendance percentage for students</div>
                                </div>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="allow_manual_attendance" name="allow_manual_attendance" <?php echo $settings['allow_manual_attendance'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_manual_attendance">Allow Manual Attendance Marking</label>
                                    </div>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="allow_attendance_requests" name="allow_attendance_requests" <?php echo $settings['allow_attendance_requests'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_attendance_requests">Allow Attendance Requests</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="email_notifications">Enable Email Notifications</label>
                                    </div>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="sms_notifications" name="sms_notifications" <?php echo $settings['sms_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="sms_notifications">Enable SMS Notifications</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Save Settings
                                </button>
                            </div>
                        </form>
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
        // Function to copy teacher registration code
        function copyCode() {
            const codeInput = document.getElementById('teacher_registration_code');
            codeInput.select();
            document.execCommand('copy');
            
            // Show alert
            alertify.success('Teacher registration code copied to clipboard');
        }
    </script>
    <?php
    if (isset($_SESSION['message'])) {
        echo "<script>
            alertify.notify('" . addslashes($_SESSION['message']) . "', '" . addslashes($_SESSION['message_type']) . "', 5);
        </script>";
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
    ?>
</body>
</html>

