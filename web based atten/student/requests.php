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

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Debug section - add this after getting the status parameter
// Uncomment this section if you need to debug the filter parameters
/*
echo '<div class="alert alert-info">';
echo 'Status: [' . $status . ']<br>';
echo 'Month: ' . $month . '<br>';
echo 'Year: ' . $year . '<br>';
echo 'Query: ' . $requestsQuery . '<br>';
echo 'Params: ' . print_r($queryParams, true) . '<br>';
echo '</div>';
*/

// Validate month and year
if ($month < 1 || $month > 12) $month = date('m');
if ($year < 2000 || $year > 2100) $year = date('Y');

// Get attendance request statistics
$totalRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests WHERE student_id = ?";
$pendingRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests WHERE student_id = ? AND status = 'pending'";
$approvedRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests WHERE student_id = ? AND status = 'approved'";
$rejectedRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests WHERE student_id = ? AND status = 'rejected'";

$stmt = mysqli_prepare($conn, $totalRequestsQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$totalRequests = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

$stmt = mysqli_prepare($conn, $pendingRequestsQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$pendingRequests = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

$stmt = mysqli_prepare($conn, $approvedRequestsQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$approvedRequests = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

$stmt = mysqli_prepare($conn, $rejectedRequestsQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$rejectedRequests = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

// Build requests query with filters
$requestsQuery = "SELECT ar.*, s.name as subject_name, s.code as subject_code, c.name as class_name
                FROM attendance_requests ar
                JOIN subjects s ON ar.subject_id = s.id
                JOIN classes c ON ar.class_id = c.id
                WHERE ar.student_id = ?";

$queryParams = [$student_id];

// Add filters to query
if ($status !== '') {
    $requestsQuery .= " AND ar.status = ?";
    $queryParams[] = $status;
}

if ($month > 0 && $year > 0) {
    $requestsQuery .= " AND MONTH(ar.date) = ? AND YEAR(ar.date) = ?";
    $queryParams[] = $month;
    $queryParams[] = $year;
}

$requestsQuery .= " ORDER BY ar.date DESC, ar.created_at DESC";

// Prepare and execute the query
$stmt = mysqli_prepare($conn, $requestsQuery);
if ($stmt) {
    if (count($queryParams) > 0) {
        // Create the correct parameter type string (i for integers, s for strings)
        $paramTypes = '';
        foreach ($queryParams as $param) {
            $paramTypes .= (is_int($param) ? 'i' : 's');
        }
        
        // Bind all parameters at once using the spread operator
        mysqli_stmt_bind_param($stmt, $paramTypes, ...$queryParams);
    }
    mysqli_stmt_execute($stmt);
    $requestRecords = mysqli_stmt_get_result($stmt);
}

// Get classes for the student's semester
$classesQuery = "SELECT c.id, c.name FROM classes c WHERE c.semester_id = ? ORDER BY c.name";
$stmt = mysqli_prepare($conn, $classesQuery);
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$classes = mysqli_stmt_get_result($stmt);

// Get subjects for the student's semester
$subjectsQuery = "SELECT s.id, s.code, s.name FROM subjects s WHERE s.semester_id = ? ORDER BY s.name";
$stmt = mysqli_prepare($conn, $subjectsQuery);
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$subjects = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Add this CSS to the head section of the page -->
    <style>
        .filter-btn {
            transition: all 0.3s ease;
        }
        .filter-btn:hover {
            transform: translateY(-2px);
        }
        .status-badge {
            font-size: 0.85rem;
            padding: 0.35em 0.65em;
        }
        .filter-active {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.25);
        }
        @media (max-width: 768px) {
            .filter-buttons {
                flex-direction: column;
                width: 100%;
            }
            .filter-buttons .btn {
                margin-bottom: 0.5rem;
                width: 100%;
            }
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
                        <a class="nav-link" href="submit_otp.php">
                            <i class="fas fa-key me-1"></i>Submit OTP
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="requests.php">
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

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-clipboard-list me-2"></i>My Attendance Requests</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <span class="badge bg-danger"><?php echo htmlspecialchars($semester_name ?? 'No Semester'); ?></span>
                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($student_roll ?? 'No Roll Number'); ?></span>
                        </div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>

                <!-- Request Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card h-100 border-left-primary shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-primary">Total Requests</h5>
                                        <h2 class="mb-0"><?php echo $totalRequests; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-clipboard-list fa-2x text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card h-100 border-left-warning shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-warning">Pending</h5>
                                        <h2 class="mb-0"><?php echo $pendingRequests; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-clock fa-2x text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-warning mt-2 mb-0">
                                    <i class="fas fa-hourglass-half me-1"></i>
                                    <?php echo ($totalRequests > 0) ? round(($pendingRequests / $totalRequests) * 100) : 0; ?>% of total requests
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card h-100 border-left-success shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-success">Approved</h5>
                                        <h2 class="mb-0"><?php echo $approvedRequests; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-check-circle fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-success mt-2 mb-0">
                                    <i class="fas fa-check me-1"></i>
                                    <?php echo ($totalRequests > 0) ? round(($approvedRequests / $totalRequests) * 100) : 0; ?>% of total requests
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card h-100 border-left-danger shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-danger">Rejected</h5>
                                        <h2 class="mb-0"><?php echo $rejectedRequests; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-times-circle fa-2x text-danger"></i>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-danger mt-2 mb-0">
                                    <i class="fas fa-times me-1"></i>
                                    <?php echo ($totalRequests > 0) ? round(($rejectedRequests / $totalRequests) * 100) : 0; ?>% of total requests
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- New Request Button -->
                <div class="row mb-4">
                     <div class="col-md-12 d-flex justify-content-start">
                     <a href="request_attendance.php" class="btn btn-danger">
                    <i class="fas fa-plus-circle me-2"></i> New Attendance Request
                  </a>
                 </div>
            </div>

                <!-- Attendance Requests -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <!-- Replace the card header in the Attendance Requests section with this improved version -->
                            <div class="card-header bg-danger text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Attendance Requests</h5>
                                    <div>
                                        <?php if ($status !== '' || $month != date('m') || $year != date('Y')): ?>
                                        <a href="requests.php" class="btn btn-sm btn-light">
                                            <i class="fas fa-times"></i> Clear Filters
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Replace the Filter Form with this improved version -->
                                <!-- Quick Status Filter Buttons -->
                                <div class="mb-4">
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <a href="requests.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" 
                                           class="btn <?php echo ($status === '') ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                            <i class="fas fa-list me-1"></i> All
                                            <span class="badge bg-white text-<?php echo ($status === '') ? 'primary' : 'secondary'; ?> ms-1"><?php echo $totalRequests; ?></span>
                                        </a>
                                        <a href="requests.php?status=pending&month=<?php echo $month; ?>&year=<?php echo $year; ?>" 
                                           class="btn <?php echo ($status === 'pending') ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                            <i class="fas fa-clock me-1"></i> Pending
                                            <span class="badge bg-white text-<?php echo ($status === 'pending') ? 'warning' : 'secondary'; ?> ms-1"><?php echo $pendingRequests; ?></span>
                                        </a>
                                        <a href="requests.php?status=approved&month=<?php echo $month; ?>&year=<?php echo $year; ?>" 
                                           class="btn <?php echo ($status === 'approved') ? 'btn-success' : 'btn-outline-success'; ?>">
                                            <i class="fas fa-check-circle me-1"></i> Approved
                                            <span class="badge bg-white text-<?php echo ($status === 'approved') ? 'success' : 'secondary'; ?> ms-1"><?php echo $approvedRequests; ?></span>
                                        </a>
                                        <a href="requests.php?status=rejected&month=<?php echo $month; ?>&year=<?php echo $year; ?>" 
                                           class="btn <?php echo ($status === 'rejected') ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                            <i class="fas fa-times-circle me-1"></i> Rejected
                                            <span class="badge bg-white text-<?php echo ($status === 'rejected') ? 'danger' : 'secondary'; ?> ms-1"><?php echo $rejectedRequests; ?></span>
                                        </a>
                                    </div>
                                </div>

                                <!-- Date Filter Form -->
                                <form method="GET" action="" class="row g-3 mb-4">
                                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                                    <div class="col-md-5">
                                        <label for="month" class="form-label">Month</label>
                                        <select class="form-select" id="month" name="month">
                                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($month == $i) ? 'selected' : ''; ?>>
                                                <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label for="year" class="form-label">Year</label>
                                        <select class="form-select" id="year" name="year">
                                            <?php for ($i = date('Y') - 2; $i <= date('Y'); $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($year == $i) ? 'selected' : ''; ?>>
                                                <?php echo $i; ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="fas fa-filter me-2"></i>Apply
                                        </button>
                                    </div>
                                </form>

                                <!-- Add this right before the table to show active filters -->
                                <div class="mb-3">
                                    <div class="d-flex flex-wrap align-items-center">
                                        <span class="me-2 fw-bold">Active Filters:</span>
                                        <span class="badge bg-danger me-2">
                                            Status: <?php echo ($status === '') ? 'All' : ucfirst($status); ?>
                                        </span>
                                        <span class="badge bg-secondary me-2">
                                            Month: <?php echo date('F', mktime(0, 0, 0, $month, 1)); ?>
                                        </span>
                                        <span class="badge bg-secondary">
                                            Year: <?php echo $year; ?>
                                        </span>
                                    </div>
                                </div>

                                <?php if (isset($requestRecords) && mysqli_num_rows($requestRecords) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Subject</th>
                                                <th>Class</th>
                                                <th>Reason</th>
                                                <th>Status</th>
                                                <th>Requested On</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($request = mysqli_fetch_assoc($requestRecords)): ?>
                                            <tr>
                                                <td><?php echo date('d M Y (D)', strtotime($request['date'])); ?></td>
                                                <td><?php echo htmlspecialchars($request['subject_code']); ?> - <?php echo htmlspecialchars($request['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['class_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['reason'] ?: 'No reason provided'); ?></td>
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
                                                    <button type="button" class="btn btn-sm btn-info view-request" data-id="<?php echo $request['id']; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($request['status'] == 'pending'): ?>
                                                    <a href="cancel_request.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to cancel this request?');">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                                    <h5>No Requests Found</h5>
                                    <p class="text-muted">No attendance requests match your filter criteria.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

  
    <!-- Request Details Modal -->
    <div class="modal fade" id="requestDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Request Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="requestDetailsContent">
                    <!-- Request details will be loaded here -->
                    <div class="text-center py-4">
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading request details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get location when the page loads
            getLocation();
        
            // Set max date for date input to today
            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.max = new Date().toISOString().split('T')[0];
            }
        
            // Handle form submission
            const submitButton = document.getElementById('submitRequest');
            if (submitButton) {
                submitButton.addEventListener('click', function() {
                    const form = document.getElementById('requestForm');
                    const classId = document.getElementById('class_id').value;
                    const subjectId = document.getElementById('subject_id').value;
                    const date = document.getElementById('date').value;
                    const reasonDetails = document.getElementById('reason_details').value;
                
                    if (!classId || !subjectId || !date || !reasonDetails) {
                        alertify.error('Please fill in all required fields.');
                        return;
                    }
                
                    // If location is not available, try to get it again
                    if (!document.getElementById('latitude').value || !document.getElementById('longitude').value) {
                        getLocation();
                    }
                
                    form.submit();
                });
            }
        
            // View request details
            const viewButtons = document.querySelectorAll('.view-request');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const requestId = this.getAttribute('data-id');
                
                    // Show the modal
                    const modal = new bootstrap.Modal(document.getElementById('requestDetailsModal'));
                    modal.show();
                
                    // Load request details
                    fetch('get_request_details.php?id=' + requestId)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                let statusBadge = '';
                                if (data.request.status === 'pending') {
                                    statusBadge = '<span class="badge bg-warning">Pending</span>';
                                } else if (data.request.status === 'approved') {
                                    statusBadge = '<span class="badge bg-success">Approved</span>';
                                } else {
                                    statusBadge = '<span class="badge bg-danger">Rejected</span>';
                                }
                            
                                let html = `
                                    <div class="mb-3">
                                        <h6>Date</h6>
                                        <p>${new Date(data.request.date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                    </div>
                                    <div class="mb-3">
                                        <h6>Subject</h6>
                                        <p>${data.request.subject_code} - ${data.request.subject_name}</p>
                                    </div>
                                    <div class="mb-3">
                                        <h6>Class</h6>
                                        <p>${data.request.class_name}</p>
                                    </div>
                                    <div class="mb-3">
                                        <h6>Status</h6>
                                        <p>${statusBadge}</p>
                                    </div>
                                    <div class="mb-3">
                                        <h6>Reason</h6>
                                        <p>${data.request.reason || 'No reason provided'}</p>
                                    </div>
                                    <div class="mb-3">
                                        <h6>Requested On</h6>
                                        <p>${new Date(data.request.created_at).toLocaleString()}</p>
                                    </div>
                                `;
                            
                                if (data.request.status === 'approved') {
                                    html += `
                                        <div class="mb-3">
                                            <h6>Approved On</h6>
                                            <p>${new Date(data.request.updated_at).toLocaleString()}</p>
                                        </div>
                                    `;
                                } else if (data.request.status === 'rejected') {
                                    html += `
                                        <div class="mb-3">
                                            <h6>Rejected On</h6>
                                            <p>${new Date(data.request.updated_at).toLocaleString()}</p>
                                        </div>
                                    `;
                                }
                            
                                document.getElementById('requestDetailsContent').innerHTML = html;
                            } else {
                                document.getElementById('requestDetailsContent').innerHTML = `
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-circle me-2"></i>
                                        ${data.message}
                                    </div>
                                `;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            document.getElementById('requestDetailsContent').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    Failed to load request details. Please try again.
                                </div>
                            `;
                        });
                });
            });
        });
    
        // Get user's location
        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const latitudeInput = document.getElementById('latitude');
                        const longitudeInput = document.getElementById('longitude');
                    
                        if (latitudeInput && longitudeInput) {
                            latitudeInput.value = position.coords.latitude;
                            longitudeInput.value = position.coords.longitude;
                        }
                    },
                    function(error) {
                        console.error('Error getting location:', error);
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            }
        }

        // Add this inside the DOMContentLoaded event listener
        // Highlight the active filter button
        const statusButtons = document.querySelectorAll('.filter-btn');
        statusButtons.forEach(button => {
            if (button.classList.contains('active-filter')) {
                button.classList.add('filter-active');
            }
        });

        // Update status filter when clicking status buttons
        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const status = this.getAttribute('data-status');
                document.getElementById('status-filter').value = status;
                document.getElementById('filter-form').submit();
            });
        });
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

