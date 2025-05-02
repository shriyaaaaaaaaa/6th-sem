<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get statistics
$totalStudentsQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'student'";
$totalTeachersQuery = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher'";
$totalAttendanceQuery = "SELECT COUNT(*) as count FROM attendance";
$averageAttendanceQuery = "SELECT AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100 as average FROM attendance";
$pendingRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests WHERE status = 'pending'";

// Get upcoming holiday
$upcomingHolidayQuery = "SELECT * FROM holidays WHERE date >= CURDATE() ORDER BY date ASC LIMIT 1";
$upcomingHolidayResult = mysqli_query($conn, $upcomingHolidayQuery);
$upcomingHoliday = mysqli_fetch_assoc($upcomingHolidayResult);
$daysUntilHoliday = 0;
$holidayName = "No upcoming holidays";

if ($upcomingHoliday) {
    $today = new DateTime(date('Y-m-d'));
    $holidayDate = new DateTime($upcomingHoliday['date']);
    $daysUntilHoliday = $today->diff($holidayDate)->days;
    $holidayName = $upcomingHoliday['name'];
}

$totalStudents = mysqli_fetch_assoc(mysqli_query($conn, $totalStudentsQuery))['count'] ?? 0;
$totalTeachers = mysqli_fetch_assoc(mysqli_query($conn, $totalTeachersQuery))['count'] ?? 0;
$totalAttendance = mysqli_fetch_assoc(mysqli_query($conn, $totalAttendanceQuery))['count'] ?? 0;
$averageAttendance = round(mysqli_fetch_assoc(mysqli_query($conn, $averageAttendanceQuery))['average'] ?? 0, 2);
$pendingRequests = mysqli_fetch_assoc(mysqli_query($conn, $pendingRequestsQuery))['count'] ?? 0;

// Get recent attendance requests
$recentRequestsQuery = "SELECT ar.*, u.name as student_name, c.name as class_name, s.name as subject_name
                      FROM attendance_requests ar
                      JOIN users u ON ar.student_id = u.id
                      JOIN classes c ON ar.class_id = c.id
                      JOIN subjects s ON c.subject_id = s.id
                      WHERE ar.status = 'pending'
                      ORDER BY ar.created_at DESC
                      LIMIT 5";
$recentRequestsResult = mysqli_query($conn, $recentRequestsQuery);
$recentRequests = [];
if ($recentRequestsResult) {
    while ($row = mysqli_fetch_assoc($recentRequestsResult)) {
        $recentRequests[] = $row;
    }
}

// Get latest students - Fixed query to ensure proper joins and handle potential missing data
$latestStudentsQuery = "SELECT u.*, s.roll_number, sem.name as semester_name
                      FROM users u
                      LEFT JOIN students s ON u.id = s.user_id
                      LEFT JOIN semesters sem ON s.semester = sem.id
                      WHERE u.role = 'student'
                      ORDER BY u.created_at DESC
                      LIMIT 5";
$latestStudentsResult = mysqli_query($conn, $latestStudentsQuery);
$latestStudents = [];
if ($latestStudentsResult) {
    while ($row = mysqli_fetch_assoc($latestStudentsResult)) {
        $latestStudents[] = $row;
    }
}

// Get attendance data for chart
$attendanceChartQuery = "SELECT 
                        DATE_FORMAT(date, '%b %d') as date_label,
                        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count
                      FROM attendance
                      WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      GROUP BY date
                      ORDER BY date ASC";
$attendanceChartResult = mysqli_query($conn, $attendanceChartQuery);
$attendanceChartData = [];
if ($attendanceChartResult) {
    while ($row = mysqli_fetch_assoc($attendanceChartResult)) {
        $attendanceChartData[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a class="nav-link active" href="dashboard.php">
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
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-1"></i>Settings
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo $_SESSION['name'] ?? 'Admin'; ?>
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

                    <li class="nav-item">
                        <a class="nav-link" href="attendance_requests.php" aria-label="Attendance Requests" style="color: black;">
                             <i class="fas fa-clipboard-check me-2"></i>Requset
                             
                            <?php $requests_count =  mysqli_num_rows(mysqli_query($conn, "SELECT * FROM attendance_requests WHERE status = 'pending'"));
                                 ?>
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
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-tachometer-alt me-2"></i>Admin Dashboard</h2>
                    <div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Total Students</h5>
                                        <h2 class="mb-0"><?php echo $totalStudents; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-graduate fa-2x text-dark"></i>
                                    </div>
                                </div>
                                <a href="students.php" class="text-muted mt-3 d-inline-block">View details <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Total Teachers</h5>
                                        <h2 class="mb-0"><?php echo $totalTeachers; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-chalkboard-teacher fa-2x text-dark"></i>
                                    </div>
                                </div>
                                <a href="teachers.php" class="text-muted mt-3 d-inline-block">View details <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Upcoming Holiday</h5>
                                        <h2 class="mb-0"><?php echo $daysUntilHoliday; ?> days</h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-calendar-day fa-2x text-dark"></i>
                                    </div>
                                </div>
                                <p class="text-muted mt-2 mb-0"><?php echo htmlspecialchars($holidayName); ?></p>
                                <a href="holidays.php" class="text-muted mt-2 d-inline-block">View all holidays <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Pending Requests</h5>
                                        <h2 class="mb-0"><?php echo $pendingRequests; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clipboard-list fa-2x text-dark"></i>
                                    </div>
                                </div>
                                <a href="attendance_requests.php" class="text-muted mt-3 d-inline-block">View details <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Recent Attendance Requests</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentRequests)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                                        <h5>No Recent Requests</h5>
                                        <p class="text-muted">Student attendance requests will appear here.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach ($recentRequests as $request): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h5 class="mb-1"><?php echo $request['student_name']; ?></h5>
                                                    <small><?php echo date('d M Y', strtotime($request['date'])); ?></small>
                                                </div>
                                                <p class="mb-1">
                                                    <strong>Class:</strong> <?php echo $request['class_name']; ?> - <?php echo $request['subject_name']; ?>
                                                </p>
                                                <p class="mb-1">
                                                    <strong>Reason:</strong> <?php echo $request['reason']; ?>
                                                </p>
                                                <div class="mt-2">
                                                    <a href="attendance_requests.php?action=approve&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-success">
                                                        <i class="fas fa-check"></i> Approve
                                                    </a>
                                                    <a href="attendance_requests.php?action=reject&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-times"></i> Reject
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-end mt-3">
                                    <a href="attendance_requests.php" class="btn btn-sm btn-danger">View All Requests</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Attendance Overview</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h5 class="card-title">Total Attendance</h5>
                                                <h2 class="mb-0"><?php echo $totalAttendance; ?></h2>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h5 class="card-title">Average Attendance</h5>
                                                <h2 class="mb-0"><?php echo $averageAttendance; ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (empty($attendanceChartData)): ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                                    <h5>No Attendance Data</h5>
                                    <p class="text-muted">Attendance statistics will appear here once data is available.</p>
                                </div>
                                <?php else: ?>
                                <div class="chart-container" style="position: relative; height:200px; width:100%">
                                    <canvas id="attendanceChart"></canvas>
                                </div>
                                <?php endif; ?>
                                
                                <div class="text-end mt-3">
                                    <a href="reports.php" class="btn btn-sm btn-danger">View Detailed Reports</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Latest Students</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($latestStudents)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
                                        <h5>No Students Found</h5>
                                        <p class="text-muted">Student data will appear here once students register.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Roll Number</th>
                                                    <th>Email</th>
                                                    <th>Semester</th>
                                                    <th>Registered On</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($latestStudents as $student): ?>
                                                    <tr>
                                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                                        <td><?php echo htmlspecialchars($student['roll_no']); ?></td>
                                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                                        <td><?php echo htmlspecialchars($student['semester']); ?></td>
                                                        <td><?php echo date('d M Y', strtotime($student['created_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <div class="text-end">
                                    <a href="students.php" class="btn btn-sm btn-danger">View All Students</a>
                                </div>
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
    
    <?php if (!empty($attendanceChartData)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            const attendanceChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($attendanceChartData as $data): ?>
                            '<?php echo $data['date_label']; ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [
                        {
                            label: 'Present',
                            data: [
                                <?php foreach ($attendanceChartData as $data): ?>
                                    <?php echo $data['present_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: 'rgba(40, 167, 69, 0.7)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Absent',
                            data: [
                                <?php foreach ($attendanceChartData as $data): ?>
                                    <?php echo $data['absent_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: 'rgba(220, 53, 69, 0.7)',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
    
    <?php
    if (isset($_SESSION['message'])) {
        echo "<script>
            alertify.notify('" . $_SESSION['message'] . "', '" . $_SESSION['message_type'] . "', 5);
        </script>";
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
    ?>
</body>
</html>
