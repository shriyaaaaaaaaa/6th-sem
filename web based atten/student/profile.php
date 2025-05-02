<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

$student_id = $_SESSION['user_id'];

// Get student data
$studentQuery = "SELECT * FROM users WHERE id = ? AND role = 'student'";
$stmt = mysqli_prepare($conn, $studentQuery);
$student = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $student = mysqli_fetch_assoc($result) ?: [];
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $parent_email = isset($_POST['parent_email']) ? mysqli_real_escape_string($conn, $_POST['parent_email']) : '';
    
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
        mysqli_stmt_bind_param($stmt, "si", $email, $student_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            setMessage('Email already exists', 'error');
            header('Location: profile.php');
            exit;
        }
    }
    
    // Update profile
    $updateQuery = "UPDATE users SET name = ?, email = ?, phone = ?, parent_email = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $updateQuery);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssssi", $name, $email, $phone, $parent_email, $student_id);
        
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
        mysqli_stmt_bind_param($stmt, "i", $student_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            if (password_verify($current_password, $row['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updateQuery = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $updateQuery);
                
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "si", $hashed_password, $student_id);
                    
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
            setMessage('User not found', 'error');
        }
    } else {
        setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
    }
    
    header('Location: profile.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="student-dashboard">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-user-graduate me-2"></i>Student Dashboard
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
                            <i class="fas fa-calendar-check me-1"></i>Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="submit_otp.php">
                            <i class="fas fa-key me-1"></i>Submit OTP
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="requests.php">
                            <i class="fas fa-clipboard-list me-1"></i>Requests
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['name'] ?? 'Student'); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item active" href="profile.php"><i class="fas fa-id-card me-2"></i>Profile</a></li>
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
                            <a class="nav-link" href="submit_otp.php"aria-label="Dashboard" style="color: black;">
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
            <div class="col-md-9 col-lg-10 ms-auto py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-user-cog me-2"></i>My Profile</h2>
                    <div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
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
                                    <div class="profile-image mx-auto mb-3 d-flex align-items-center justify-content-center bg-light">
                                        <i class="fas fa-user-graduate fa-5x text-muted"></i>
                                    </div>
                                    <h4><?php echo htmlspecialchars($student['name'] ?? 'Student'); ?></h4>
                                    <p class="text-muted mb-0">BCA Student</p>
                                </div>
                                <div class="border-top pt-3">
                                    <div class="row mb-2">
                                        <div class="col-6 text-start text-muted">Roll Number:</div>
                                        <div class="col-6 text-end"><?php echo htmlspecialchars($student['roll_no'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-6 text-start text-muted">Semester:</div>
                                        <div class="col-6 text-end"><?php echo htmlspecialchars($student['semester'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-6 text-start text-muted">Joined:</div>
                                        <div class="col-6 text-end"><?php echo isset($student['created_at']) ? date('d M Y', strtotime($student['created_at'])) : 'N/A'; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($student['name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="phone" class="form-label">Phone Number</label>
                                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="parent_email" class="form-label">Parent Email (for notifications)</label>
                                            <input type="email" class="form-control" id="parent_email" name="parent_email" value="<?php echo htmlspecialchars($student['parent_email'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="roll_no" class="form-label">Roll Number</label>
                                            <input type="text" class="form-control" id="roll_no" value="<?php echo htmlspecialchars($student['roll_no'] ?? ''); ?>" disabled>
                                            <div class="form-text">Roll number cannot be changed</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="semester" class="form-label">Semester</label>
                                            <input type="text" class="form-control" id="semester" value="<?php echo htmlspecialchars($student['semester'] ?? ''); ?>" disabled>
                                            <div class="form-text">Semester can only be updated by admin</div>
                                        </div>
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

