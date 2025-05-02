<?php
session_start();
date_default_timezone_set('Asia/Kathmandu'); 
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage('Please log in to view active OTPs', 'error');
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$active_otps = [];

// For teachers - show OTPs they've generated
if ($role === 'teacher') {
    $query = "SELECT o.*, s.name as subject_name, s.code as subject_code, 
              c.name as class_name, c.room_number,
              (SELECT COUNT(*) FROM available_otps WHERE otp_code = o.otp_code AND used = 1) as used_count,
              (SELECT COUNT(*) FROM available_otps WHERE otp_code = o.otp_code) as total_count
              FROM otp o
              JOIN subjects s ON o.subject_id = s.id
              JOIN classes c ON o.class_id = c.id
              WHERE o.teacher_id = ? AND o.expiry > NOW()
              ORDER BY o.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Calculate remaining time
        $now = new DateTime();
        $expiry = new DateTime($row['expiry']);
        $interval = $now->diff($expiry);
        $row['remaining_minutes'] = ($interval->h * 60) + $interval->i;
        $row['remaining_seconds'] = $interval->s;
        
        // Calculate usage percentage
        $row['usage_percentage'] = ($row['total_count'] > 0) ? 
            round(($row['used_count'] / $row['total_count']) * 100) : 0;
        
        $active_otps[] = $row;
    }
}
// For students - show OTPs available to them
else if ($role === 'student') {
    $query = "SELECT ao.*, s.name as subject_name, s.code as subject_code,
              u.name as teacher_name, c.name as class_name
              FROM available_otps ao
              JOIN subjects s ON ao.subject_id = s.id
              JOIN otp o ON ao.otp_id = o.id
              JOIN users u ON o.teacher_id = u.id
              JOIN classes c ON o.class_id = c.id
              WHERE ao.student_id = ? AND ao.used = 0 AND o.expiry > NOW()
              ORDER BY o.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Get OTP expiry time
        $otpQuery = "SELECT expiry FROM otp WHERE id = ?";
        $otpStmt = mysqli_prepare($conn, $otpQuery);
        mysqli_stmt_bind_param($otpStmt, "i", $row['otp_id']);
        mysqli_stmt_execute($otpStmt);
        $otpResult = mysqli_stmt_get_result($otpStmt);
        $otpData = mysqli_fetch_assoc($otpResult);
        
        if ($otpData) {
            // Calculate remaining time
            $now = new DateTime();
            $expiry = new DateTime($otpData['expiry']);
            $interval = $now->diff($expiry);
            $row['remaining_minutes'] = ($interval->h * 60) + $interval->i;
            $row['remaining_seconds'] = $interval->s;
            $row['expiry'] = $otpData['expiry'];
        }
        
        $active_otps[] = $row;
    }
}
// For admins - show all active OTPs
else if ($role === 'admin') {
    $query = "SELECT o.*, s.name as subject_name, s.code as subject_code, 
              c.name as class_name, u.name as teacher_name,
              (SELECT COUNT(*) FROM available_otps WHERE otp_code = o.otp_code AND used = 1) as used_count,
              (SELECT COUNT(*) FROM available_otps WHERE otp_code = o.otp_code) as total_count
              FROM otp o
              JOIN subjects s ON o.subject_id = s.id
              JOIN classes c ON o.class_id = c.id
              JOIN users u ON o.teacher_id = u.id
              WHERE o.expiry > NOW()
              ORDER BY o.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Calculate remaining time
        $now = new DateTime();
        $expiry = new DateTime($row['expiry']);
        $interval = $now->diff($expiry);
        $row['remaining_minutes'] = ($interval->h * 60) + $interval->i;
        $row['remaining_seconds'] = $interval->s;
        
        // Calculate usage percentage
        $row['usage_percentage'] = ($row['total_count'] > 0) ? 
            round(($row['used_count'] / $row['total_count']) * 100) : 0;
        
        $active_otps[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active OTPs - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .otp-card {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            border: none;
            transition: transform 0.3s ease;
        }
        .otp-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .otp-header {
            background-color: #dc3545;
            color: white;
            padding: 15px;
            font-weight: bold;
        }
        .otp-body {
            padding: 20px;
        }
        .otp-code {
            font-size: 2.5rem;
            font-weight: bold;
            letter-spacing: 5px;
            color: #dc3545;
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            display: inline-block;
            margin: 10px 0;
            cursor: pointer;
        }
        .otp-code:hover {
            background-color: #e9ecef;
        }
        .otp-details {
            margin-top: 15px;
        }
        .otp-detail-item {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        .otp-detail-item i {
            width: 25px;
            color: #dc3545;
            margin-right: 10px;
        }
        .otp-timer {
            font-size: 1.2rem;
            font-weight: bold;
            color: #28a745;
            margin: 15px 0;
        }
        .otp-timer i {
            margin-right: 8px;
        }
        .otp-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        .progress {
            height: 15px;
            margin-top: 5px;
        }
        .no-otps {
            text-align: center;
            padding: 50px 0;
        }
        .no-otps i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }
        .copy-tooltip {
            position: absolute;
            top: -40px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 0.9rem;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .copy-tooltip.show {
            opacity: 1;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-key text-danger me-2"></i>Active OTPs</h1>
            <a href="<?php echo $role; ?>/dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
        
        <?php if (empty($active_otps)): ?>
        <div class="no-otps">
            <i class="fas fa-key"></i>
            <h3>No Active OTPs Found</h3>
            <p class="text-muted">
                <?php if ($role === 'teacher'): ?>
                    You haven't generated any OTPs yet or all your OTPs have expired.
                <?php elseif ($role === 'student'): ?>
                    There are no active OTPs available for you at the moment.
                <?php else: ?>
                    There are no active OTPs in the system right now.
                <?php endif; ?>
            </p>
            
            <?php if ($role === 'teacher'): ?>
            <a href="teacher/generate_otp.php" class="btn btn-danger mt-3">
                <i class="fas fa-plus-circle me-2"></i>Generate New OTP
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        
        <div class="row">
            <?php foreach ($active_otps as $otp): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card otp-card">
                    <div class="otp-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <?php if (isset($otp['subject_code'])): ?>
                                    <?php echo $otp['subject_code']; ?> - <?php echo $otp['subject_name']; ?>
                                <?php else: ?>
                                    Subject OTP
                                <?php endif; ?>
                            </span>
                            <?php if ($role === 'admin'): ?>
                            <span class="badge bg-light text-dark">
                                <?php echo $otp['teacher_name']; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="otp-body">
                        <div class="position-relative">
                            <div class="otp-code" onclick="copyToClipboard('<?php echo $otp['otp_code']; ?>')">
                                <?php echo $otp['otp_code']; ?>
                                <div class="copy-tooltip" id="copy-tooltip-<?php echo $otp['otp_code']; ?>">Copied!</div>
                            </div>
                        </div>
                        
                        <div class="otp-timer" id="otp-timer-<?php echo isset($otp['id']) ? $otp['id'] : $otp['otp_id']; ?>">
                            <i class="fas fa-hourglass-half"></i> 
                            Valid for <?php echo $otp['remaining_minutes']; ?>:<?php echo str_pad($otp['remaining_seconds'], 2, '0', STR_PAD_LEFT); ?> minutes
                        </div>
                        
                        <div class="otp-details">
                            <div class="otp-detail-item">
                                <i class="fas fa-building"></i>
                                <span>Class: <?php echo $otp['class_name']; ?></span>
                            </div>
                            
                            <?php if ($role === 'student'): ?>
                            <div class="otp-detail-item">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <span>Teacher: <?php echo $otp['teacher_name']; ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($role !== 'student' && isset($otp['used_count'])): ?>
                            <div class="otp-detail-item">
                                <i class="fas fa-users"></i>
                                <span>Usage: <?php echo $otp['used_count']; ?> of <?php echo $otp['total_count']; ?> students</span>
                            </div>
                            
                            <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo $otp['usage_percentage']; ?>%" 
                                     aria-valuenow="<?php echo $otp['usage_percentage']; ?>" 
                                     aria-valuemin="0" aria-valuemax="100">
                                    <?php echo $otp['usage_percentage']; ?>%
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="otp-detail-item">
                                <i class="fas fa-clock"></i>
                                <span>Expires at: <?php echo date('h:i A', strtotime($otp['expiry'])); ?></span>
                            </div>
                        </div>
                        
                        <div class="otp-actions">
                            <button class="btn btn-sm btn-outline-primary" onclick="copyToClipboard('<?php echo $otp['otp_code']; ?>')">
                                <i class="fas fa-copy me-1"></i> Copy
                            </button>
                            
                            <?php if ($role === 'student'): ?>
                            <a href="student/submit_otp.php?otp=<?php echo $otp['otp_code']; ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-check-circle me-1"></i> Submit
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($role === 'teacher'): ?>
                            <a href="teacher/mark_attendance.php?otp_id=<?php echo $otp['id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-user-check me-1"></i> View Attendance
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($role === 'admin'): ?>
                            <a href="admin/view_otp_details.php?otp_id=<?php echo $otp['id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-info-circle me-1"></i> Details
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($role === 'teacher'): ?>
        <div class="text-center mt-4">
            <a href="teacher/generate_otp.php" class="btn btn-danger">
                <i class="fas fa-plus-circle me-2"></i>Generate New OTP
            </a>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Copy OTP to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(
                function() {
                    // Show tooltip
                    const tooltip = document.getElementById('copy-tooltip-' + text);
                    tooltip.classList.add('show');
                    
                    // Hide tooltip after 2 seconds
                    setTimeout(() => {
                        tooltip.classList.remove('show');
                    }, 2000);
                    
                    console.log('OTP copied to clipboard');
                },
                function() {
                    console.error('Failed to copy OTP');
                    
                    // Fallback for browsers that don't support clipboard API
                    const tempInput = document.createElement('input');
                    tempInput.value = text;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                    
                    // Show tooltip
                    const tooltip = document.getElementById('copy-tooltip-' + text);
                    tooltip.classList.add('show');
                    
                    // Hide tooltip after 2 seconds
                    setTimeout(() => {
                        tooltip.classList.remove('show');
                    }, 2000);
                }
            );
        }
        
        // OTP Timer function
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($active_otps as $otp): ?>
            const timerElement<?php echo isset($otp['id']) ? $otp['id'] : $otp['otp_id']; ?> = document.getElementById('otp-timer-<?php echo isset($otp['id']) ? $otp['id'] : $otp['otp_id']; ?>');
            if (timerElement<?php echo isset($otp['id']) ? $otp['id'] : $otp['otp_id']; ?>) {
                const now = new Date();
                const expiry = new Date('<?php echo $otp['expiry']; ?>');
                const remainingSeconds = Math.floor((expiry - now) / 1000);
                
                if (remainingSeconds > 0) {
                    startOtpTimer(remainingSeconds, timerElement<?php echo isset($otp['id']) ? $otp['id'] : $otp['otp_id']; ?>);
                }
            }
            <?php endforeach; ?>
        });
        
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
                        display.style.color = '#dc3545';
                    }
                    
                    // Reload the page after expiry
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                }
            }, 1000);
        }
    </script>
</body>
</html>

