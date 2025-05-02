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
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Validate month and year
if ($month < 1 || $month > 12) $month = date('m');
if ($year < 2000 || $year > 2100) $year = date('Y');

// Get attendance statistics
$totalAttendanceQuery = "SELECT COUNT(*) as count FROM attendance WHERE student_id = ?";
$presentAttendanceQuery = "SELECT COUNT(*) as count FROM attendance WHERE student_id = ? AND status = 'present'";
$absentAttendanceQuery = "SELECT COUNT(*) as count FROM attendance WHERE student_id = ? AND status = 'absent'";

$stmt = mysqli_prepare($conn, $totalAttendanceQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$totalAttendance = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

$stmt = mysqli_prepare($conn, $presentAttendanceQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$presentAttendance = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

$stmt = mysqli_prepare($conn, $absentAttendanceQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$absentAttendance = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;

// Calculate attendance percentage
$attendancePercentage = ($totalAttendance > 0) ? round(($presentAttendance / $totalAttendance) * 100) : 0;

// Get subjects for the student's semester
$subjectsQuery = "SELECT id, code, name FROM subjects WHERE semester_id = ? ORDER BY name";
$stmt = mysqli_prepare($conn, $subjectsQuery);
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$subjects = mysqli_stmt_get_result($stmt);

// Build attendance query with filters
$attendanceQuery = "SELECT a.*, s.name as subject_name, s.code as subject_code, c.name as class_name, u.name as teacher_name
                  FROM attendance a
                  JOIN subjects s ON a.subject_id = s.id
                  JOIN classes c ON a.class_id = c.id
                  JOIN users u ON a.teacher_id = u.id
                  WHERE a.student_id = ?";

$queryParams = [$student_id];

// Add filters to query
if ($subject_id > 0) {
    $attendanceQuery .= " AND a.subject_id = ?";
    $queryParams[] = $subject_id;
}

if ($month > 0 && $year > 0) {
    $attendanceQuery .= " AND MONTH(a.date) = ? AND YEAR(a.date) = ?";
    $queryParams[] = $month;
    $queryParams[] = $year;
}

if ($status !== '') {
    $attendanceQuery .= " AND a.status = ?";
    $queryParams[] = $status;
}

$attendanceQuery .= " ORDER BY a.date DESC, a.marked_at DESC";

// Prepare and execute the query
$stmt = mysqli_prepare($conn, $attendanceQuery);
if ($stmt) {
    $paramTypes = str_repeat('i', count($queryParams));
    mysqli_stmt_bind_param($stmt, $paramTypes, ...$queryParams);
    mysqli_stmt_execute($stmt);
    $attendanceRecords = mysqli_stmt_get_result($stmt);
}

// Get subject-wise attendance statistics
$subjectStatsQuery = "SELECT 
                        s.id as subject_id,
                        s.name as subject_name,
                        s.code as subject_code,
                        COUNT(a.id) as total_classes,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100) as percentage
                    FROM subjects s
                    LEFT JOIN attendance a ON s.id = a.subject_id AND a.student_id = ?
                    WHERE s.semester_id = ?
                    GROUP BY s.id
                    ORDER BY s.name";
$stmt = mysqli_prepare($conn, $subjectStatsQuery);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $semester_id);
mysqli_stmt_execute($stmt);
$subjectStats = mysqli_stmt_get_result($stmt);

// Get minimum attendance percentage from settings
$settingsQuery = "SELECT min_attendance_percentage FROM settings LIMIT 1";
$settingsResult = mysqli_query($conn, $settingsQuery);
$settings = mysqli_fetch_assoc($settingsResult);
$minAttendancePercentage = $settings['min_attendance_percentage'] ?? 75;

// Get monthly attendance data for chart
$monthlyDataQuery = "SELECT 
                    MONTH(date) as month,
                    YEAR(date) as year,
                    COUNT(*) as total_classes,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count
                FROM attendance
                WHERE student_id = ?
                GROUP BY YEAR(date), MONTH(date)
                ORDER BY YEAR(date), MONTH(date)";
$stmt = mysqli_prepare($conn, $monthlyDataQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$monthlyDataResult = mysqli_stmt_get_result($stmt);

$monthlyLabels = [];
$monthlyData = [];
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

while ($row = mysqli_fetch_assoc($monthlyDataResult)) {
    $monthlyLabels[] = $monthNames[$row['month'] - 1] . ' ' . $row['year'];
    $percentage = ($row['total_classes'] > 0) ? round(($row['present_count'] / $row['total_classes']) * 100) : 0;
    $monthlyData[] = $percentage;
}

// Get holidays
$holidays = getHolidays($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="student-dashboard">
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
                        <a class="nav-link active" href="attendance.php">
                            <i class="fas fa-calendar-check me-1"></i>My Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="submit_otp.php">
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
                    <h1 class="h2"><i class="fas fa-calendar-check me-2"></i>My Attendance</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <span class="badge bg-danger"><?php echo htmlspecialchars($semester_name ?? 'No Semester'); ?></span>
                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($student_roll ?? 'No Roll Number'); ?></span>
                        </div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>

                <!-- Attendance Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card h-100 border-left-primary shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-danger">Overall Attendance</h5>
                                        <h2 class="mb-0"><?php echo $attendancePercentage; ?>%</h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-chart-pie fa-2x text-danger"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="progress mt-3" style="height: 10px;">
                                    <div class="progress-bar <?php echo ($attendancePercentage < $minAttendancePercentage) ? 'bg-danger' : 'bg-success'; ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo $attendancePercentage; ?>%" 
                                         aria-valuenow="<?php echo $attendancePercentage; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                                <p class="mt-2 mb-0 <?php echo ($attendancePercentage < $minAttendancePercentage) ? 'text-danger' : 'text-success'; ?>">
                                    <?php if ($attendancePercentage < $minAttendancePercentage): ?>
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Below required <?php echo $minAttendancePercentage; ?>%
                                    <?php else: ?>
                                        <i class="fas fa-check-circle me-1"></i>
                                        Above required <?php echo $minAttendancePercentage; ?>%
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card h-100 border-left-success shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-success">Present Days</h5>
                                        <h2 class="mb-0"><?php echo $presentAttendance; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-calendar-check fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-success mt-2 mb-0">
                                    <i class="fas fa-check-circle me-1"></i>
                                    <?php echo ($totalAttendance > 0) ? round(($presentAttendance / $totalAttendance) * 100) : 0; ?>% of total days
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card h-100 border-left-danger shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title text-danger">Absent Days</h5>
                                        <h2 class="mb-0"><?php echo $absentAttendance; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-calendar-times fa-2x text-danger"></i>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-danger mt-2 mb-0">
                                    <i class="fas fa-times-circle me-1"></i>
                                    <?php echo ($totalAttendance > 0) ? round(($absentAttendance / $totalAttendance) * 100) : 0; ?>% of total days
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Chart -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Monthly Attendance Trend</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="attendanceChart" height="100"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subject-wise Attendance -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Subject-wise Attendance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (mysqli_num_rows($subjectStats) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Subject Code</th>
                                                <th>Subject Name</th>
                                                <th>Total Classes</th>
                                                <th>Present</th>
                                                <th>Percentage</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($subject = mysqli_fetch_assoc($subjectStats)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                <td><?php echo $subject['total_classes']; ?></td>
                                                <td><?php echo $subject['present_count']; ?></td>
                                                <td>
                                                    <div class="progress" style="height: 10px;">
                                                        <div class="progress-bar <?php echo ($subject['percentage'] < $minAttendancePercentage) ? 'bg-danger' : 'bg-success'; ?>" 
                                                             role="progressbar" 
                                                             style="width: <?php echo $subject['percentage']; ?>%" 
                                                             aria-valuenow="<?php echo $subject['percentage']; ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <small><?php echo $subject['percentage']; ?>%</small>
                                                </td>
                                                <td>
                                                    <?php if ($subject['total_classes'] == 0): ?>
                                                        <span class="badge bg-secondary">No Classes</span>
                                                    <?php elseif ($subject['percentage'] < $minAttendancePercentage): ?>
                                                        <span class="badge bg-danger">Below Required</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Good Standing</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="attendance.php?subject_id=<?php echo $subject['subject_id']; ?>" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-filter"></i> Filter
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-book fa-4x text-muted mb-3"></i>
                                    <h5>No Subject Data</h5>
                                    <p class="text-muted">No attendance data available for your subjects.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Records -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Attendance Records</h5>
                                <div>
                                    <?php if ($subject_id > 0 || $month != date('m') || $year != date('Y') || $status !== ''): ?>
                                    <a href="attendance.php" class="btn btn-sm btn-light">
                                        <i class="fas fa-times"></i> Clear Filters
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Filter Form -->
                                <form method="GET" action="" class="row g-3 mb-4">
                                    <div class="col-md-3">
                                        <label for="subject_id" class="form-label">Subject</label>
                                        <select class="form-select" id="subject_id" name="subject_id">
                                            <option value="0">All Subjects</option>
                                            <?php 
                                            mysqli_data_seek($subjects, 0);
                                            while ($subject = mysqli_fetch_assoc($subjects)): 
                                            ?>
                                            <option value="<?php echo $subject['id']; ?>" <?php echo ($subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['name']); ?>
                                            </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="month" class="form-label">Month</label>
                                        <select class="form-select" id="month" name="month">
                                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($month == $i) ? 'selected' : ''; ?>>
                                                <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="year" class="form-label">Year</label>
                                        <select class="form-select" id="year" name="year">
                                            <?php for ($i = date('Y') - 2; $i <= date('Y'); $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($year == $i) ? 'selected' : ''; ?>>
                                                <?php echo $i; ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="" <?php echo ($status === '') ? 'selected' : ''; ?>>All</option>
                                            <option value="present" <?php echo ($status === 'present') ? 'selected' : ''; ?>>Present</option>
                                            <option value="absent" <?php echo ($status === 'absent') ? 'selected' : ''; ?>>Absent</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="fas fa-filter me-2"></i>Apply Filters
                                        </button>
                                    </div>
                                </form>

                                <?php if (isset($attendanceRecords) && mysqli_num_rows($attendanceRecords) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Subject</th>
                                                <th>Class</th>
                                                <th>Teacher</th>
                                                <th>Status</th>
                                                <th>Marked At</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($record = mysqli_fetch_assoc($attendanceRecords)): ?>
                                            <tr>
                                                <td><?php echo date('d M Y (D)', strtotime($record['date'])); ?></td>
                                                <td><?php echo htmlspecialchars($record['subject_code']); ?> - <?php echo htmlspecialchars($record['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                                <td><?php echo htmlspecialchars($record['teacher_name']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($record['status'] == 'present') ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($record['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y h:i A', strtotime($record['marked_at'])); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                                    <h5>No Attendance Records Found</h5>
                                    <p class="text-muted">No attendance records match your filter criteria.</p>
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
    <script src="../assets/js/main.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Attendance Chart
            var ctx = document.getElementById('attendanceChart').getContext('2d');
            var attendanceChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthlyLabels); ?>,
                    datasets: [{
                        label: 'Attendance Percentage',
                        data: <?php echo json_encode($monthlyData); ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        tension: 0.1,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.raw + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    }
                }
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

