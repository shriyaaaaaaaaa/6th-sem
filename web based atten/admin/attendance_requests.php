<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Handle request actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $request_id = intval($_GET['id']);
    
    if ($action === 'approve' || $action === 'reject') {
        // Get request details
        $requestQuery = "SELECT * FROM attendance_requests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $requestQuery);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        if ($request) {
            // Update request status
            $status = ($action === 'approve') ? 'approved' : 'rejected';
            $updateQuery = "UPDATE attendance_requests SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $updateQuery);
            mysqli_stmt_bind_param($stmt, "si", $status, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // If approved, mark attendance as present
                if ($action === 'approve') {
                    $insertQuery = "INSERT INTO attendance (student_id, teacher_id, status, date, marked_at) 
                                   VALUES (?, ?, 'present', ?, NOW())
                                   ON DUPLICATE KEY UPDATE status = 'present', teacher_id = ?, marked_at = NOW()";
                    $stmt = mysqli_prepare($conn, $insertQuery);
                    mysqli_stmt_bind_param($stmt, "iisi", $request['student_id'], $_SESSION['user_id'], $request['date'], $_SESSION['user_id']);
                    mysqli_stmt_execute($stmt);
                }
                
                setMessage('Request ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully', 'success');
            } else {
                setMessage('Error updating request: ' . mysqli_error($conn), 'error');
            }
        } else {
            setMessage('Request not found', 'error');
        }
    } elseif ($action === 'delete') {
        // Delete request
        $deleteQuery = "DELETE FROM attendance_requests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                setMessage('Request deleted successfully', 'success');
            } else {
                setMessage('Error deleting request: ' . mysqli_error($conn), 'error');
            }
        } else {
            setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
        }
    }
    
    header('Location: attendance_requests.php');
    exit;
}

// Get filters
$status = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;

// Build query based on filters
$requestsQuery = "SELECT r.*, u.name as student_name, u.roll_no, u.semester 
               FROM attendance_requests r 
               JOIN users u ON r.student_id = u.id 
               WHERE 1=1";

if (!empty($status)) {
    $requestsQuery .= " AND r.status = '$status'";
}

if ($semester > 0) {
    $requestsQuery .= " AND u.semester = $semester";
}

$requestsQuery .= " ORDER BY r.status = 'pending' DESC, r.date DESC";
$requestsResult = mysqli_query($conn, $requestsQuery);
$requests = [];

if ($requestsResult) {
    while ($row = mysqli_fetch_assoc($requestsResult)) {
        $requests[] = $row;
    }
}

// Get all semesters for filter
$semestersQuery = "SELECT DISTINCT semester FROM users WHERE role = 'student' ORDER BY semester ASC";
$semestersResult = mysqli_query($conn, $semestersQuery);
$semesters = [];

if ($semestersResult) {
    while ($row = mysqli_fetch_assoc($semestersResult)) {
        $semesters[] = $row['semester'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Requests - BCA Attendance System</title>
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
                        <a class="nav-link active" href="attendance_requests.php">
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
                        <a class="nav-link" href="settings.php">
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
                    <h2><i class="fas fa-clipboard-check me-2"></i>Attendance Requests</h2>
                    <div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>
                
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Requests</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="semester" class="form-label">Semester</label>
                                <select class="form-select" id="semester" name="semester">
                                    <option value="0">All Semesters</option>
                                    <?php foreach ($semesters as $sem): ?>
                                    <option value="<?php echo $sem; ?>" <?php echo $semester == $sem ? 'selected' : ''; ?>>
                                        Semester <?php echo $sem; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-filter me-2"></i>Apply Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Student Attendance Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($requests)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <th>Roll No</th>
                                        <th>Semester</th>
                                        <th>Date</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Requested On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $counter = 1;
                                    foreach ($requests as $request): ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo htmlspecialchars($request['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['roll_no']); ?></td>
                                        <td><?php echo htmlspecialchars($request['semester']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($request['date'])); ?></td>
                                        <td><?php echo htmlspecialchars($request['reason'] ?: 'Not provided'); ?></td>
                                        <td>
                                            <?php if ($request['status'] == 'pending'): ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php elseif ($request['status'] == 'approved'): ?>
                                                <span class="badge bg-success">Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d M Y h:i A', strtotime($request['created_at'])); ?></td>
                                        <td>
                                            <?php if ($request['status'] == 'pending'): ?>
                                            <div class="btn-group" role="group">
                                                <a href="attendance_requests.php?action=approve&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Are you sure you want to approve this request?')">
                                                    <i class="fas fa-check"></i> Approve
                                                </a>
                                                <a href="attendance_requests.php?action=reject&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to reject this request?')">
                                                    <i class="fas fa-times"></i> Reject
                                                </a>
                                            </div>
                                            <?php else: ?>
                                            <a href="attendance_requests.php?action=delete&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this request?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                            <h5>No Attendance Requests</h5>
                            <p class="text-muted">No attendance requests found matching your criteria.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script src="../assets/js/main.js"></script>
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

