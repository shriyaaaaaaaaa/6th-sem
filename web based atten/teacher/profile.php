<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

$teacher_id = $_SESSION['user_id'];

// Get teacher data
$teacherQuery = "SELECT * FROM users WHERE id = ? AND role = 'teacher'";
$stmt = mysqli_prepare($conn, $teacherQuery);
$teacher = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $teacher = mysqli_fetch_assoc($result) ?: [];
    }
}

// Get teacher's subjects
$subjectsQuery = "SELECT s.name, s.code 
                FROM subjects s
                JOIN teacher_classes tc ON s.id = tc.subject_id
                WHERE tc.teacher_id = ?
                ORDER BY s.name";
$stmt = mysqli_prepare($conn, $subjectsQuery);
$subjects = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subjects[] = $row;
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    
    // Validate email
    if (!validateEmail($email)) {
        setMessage('Invalid email format', 'error');
        header('Location: profile.php');
        exit;
    }
    
    // Validate phone
    if (!validatePhone($phone)) {
        setMessage('Phone number must be 10 digits', 'error');
        header('Location: profile.php');
        exit;
    }
    
    // Check if email already exists (excluding current user)
    $checkEmailQuery = "SELECT * FROM users WHERE email = ? AND id != ?";
    $stmt = mysqli_prepare($conn, $checkEmailQuery);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "si", $email, $teacher_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            setMessage('Email already exists', 'error');
            header('Location: profile.php');
            exit;
        }
    }
    
    // Update profile
    $updateQuery = "UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $updateQuery);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sssi", $name, $email, $phone, $teacher_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update session variables
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;
            
            setMessage('Profile updated successfully', 'success');
        } else {
            setMessage('Error updating profile: ' . mysqli_error($conn), 'error');
        }
    } else {
        setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
    }
    
    header('Location: profile.php');
    exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate password match
    if ($new_password !== $confirm_password) {
        setMessage('New passwords do not match', 'error');
        header('Location: profile.php');
        exit;
    }
    
    // Validate password length
    if (strlen($new_password) < 6) {
        setMessage('Password must be at least 6 characters long', 'error');
        header('Location: profile.php');
        exit;
    }
    
    // Verify current password
    $passwordQuery = "SELECT password FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $passwordQuery);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            if (password_verify($current_password, $row['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updateQuery = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $updateQuery);
                
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "si", $hashed_password, $teacher_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        setMessage('Password changed successfully', 'success');
                    } else {
                        setMessage('Error changing password: ' . mysqli_error($conn), 'error');
                    }
                } else {
                    setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
                }
            } else {
                setMessage('Current password is incorrect', 'error');
            }
        } else {
            setMessage('User  not found', 'error');
        }
    } else {
        setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
    }
    
    header('Location: profile.php');
    exit;
}

// Get attendance statistics
$statsQuery = "SELECT 
              COUNT(DISTINCT a.student_id) as total_students,
              COUNT(a.id) as total_records,
              SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count
            FROM attendance a
            WHERE a.teacher_id = ?";
$stmt = mysqli_prepare($conn, $statsQuery);
$stats = [
    'total_students' => 0,
    'total_records' => 0,
    'present_count' => 0
];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $stats = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - BCA Attendance System</title>
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
                <i class="fas fa-chalkboard-teacher me-2"></i>Teacher Dashboard
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="generate_otp.php">
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
                    <h2><i class="fas fa-user-cog me-2 text-danger"></i>My Profile</h2>
                    <div>
                        <span class="text-dark"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Profile Information</h5>
                            </div>
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <div class="profile-image mx-auto mb-3 d-flex align-items-center justify-content-center">
                                        <i class="fas fa-chalkboard-teacher fa-5x"></i>
                                    </div>
                                    <h4 class="text-dark"><?php echo htmlspecialchars($teacher['name'] ?? 'Teacher'); ?></h4>
                                    <p class="text-muted mb-0">BCA Teacher</p>
                                </div>
                                <div class="border-top border-secondary pt-3">
                                    <div class="row mb-2">
                                        <div class="col-6 text-start text-muted">Email:</div>
                                        <div class="col-6 text-end text-dark"><?php echo htmlspecialchars($teacher['email'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-6 text-start text-muted">Phone:</div>
                                        <div class="col-6 text-end text-dark"><?php echo htmlspecialchars($teacher['phone'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-6 text-start text-muted">Joined:</div>
                                        <div class="col-6 text-end text-dark"><?php echo isset($teacher['created_at']) ? date('d M Y', strtotime($teacher['created_at'])) : 'N/A'; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card shadow-sm mt-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <h4 class="text-dark"><?php echo $stats['total_students']; ?></h4>
                                        <p class="text-muted small">Students</p>
                                    </div>
                                    <div class="col-4">
                                        <h4 class="text-dark"><?php echo $stats['total_records']; ?></h4>
                                        <p class="text-muted small">Records</p>
                                    </div>
                                    <div class="col-4">
                                        <h4 class="text-dark"><?php echo $stats['present_count']; ?></h4>
                                        <p class="text-muted small">Present</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($subjects)): ?>
                        <div class="card shadow-sm mt-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-book me-2"></i>My Subjects</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($subjects as $subject): ?>
                                    <li class="list-group-item bg-transparent text-dark border-secondary">
                                        <?php echo htmlspecialchars($subject['code']); ?> - <?php echo htmlspecialchars($subject['name']); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Profile</h5>
                            </div>
                            <div class="card-body">
                                <form action="profile.php" method="POST">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="name" class="form-label">Full Name</label>
                                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($teacher['name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($teacher['email'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($teacher['phone'] ?? ''); ?>" required>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" name="update_profile" class="btn btn-danger">
                                            <i class="fas fa-save me-2"></i>Update Profile
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
                            </div>
                            <div class="card-body">
                                <form action="profile.php" method="POST">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="new_password" class="form-label">New Password</label>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        </div>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" name="change_password" class="btn btn-danger">
                                            <i class="fas fa-key me-2"></i>Change Password
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
        // Phone number validation
        document.getElementById('phone').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 10) {
                this.value = this.value.slice(0, 10);
            }
        });
        
        // Email validation
        document.getElementById('email').addEventListener('blur', function() {
            const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;
            if (!emailRegex.test(this.value)) {
                alertify.error('Please enter a valid email address');
                this.focus();
            }
        });
        
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('blur', function() {
            const newPassword = document.getElementById('new_password').value;
            if (this.value !== newPassword) {
                alertify.error('Passwords do not match');
                this.focus();
            }
        });
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