<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

// Get teacher ID
$teacher_id = $_SESSION['user_id'];

// Get total students
$totalStudentsQuery = "SELECT COUNT(DISTINCT u.id) as count 
                       FROM users u
                       WHERE u.role = 'student'";

// Fetch total students
$stmt = mysqli_prepare($conn, $totalStudentsQuery);
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $totalStudents = mysqli_fetch_assoc($result)['count'] ?? 0;
} else {
    $totalStudents = 0;
}

// Fetch total attendance
$totalAttendanceQuery = "SELECT COUNT(*) as count FROM attendance WHERE teacher_id = ?";
$stmt = mysqli_prepare($conn, $totalAttendanceQuery);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $totalAttendance = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;
} else {
    $totalAttendance = 0;
}

// Fetch pending requests
$pendingRequestsQuery = "SELECT COUNT(*) as count FROM attendance_requests WHERE status = 'pending' AND subject_id IN (
                          SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?)";
$stmt = mysqli_prepare($conn, $pendingRequestsQuery);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $pendingRequests = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;
} else {
    $pendingRequests = 0;
}

// Get today's attendance
$todayAttendanceQuery = "SELECT a.*, u.name as student_name, u.roll_no, s.name as subject_name
                         FROM attendance a
                         JOIN users u ON a.student_id = u.id
                         JOIN subjects s ON a.subject_id = s.id
                         WHERE a.teacher_id = ? AND a.date = CURDATE()
                         ORDER BY a.marked_at DESC
                         LIMIT 10";
$stmt = mysqli_prepare($conn, $todayAttendanceQuery);
$todayAttendance = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $todayAttendance[] = $row;
        }
    }
}

// Get holidays for the calendar
$holidaysQuery = "SELECT * FROM holidays ORDER BY date ASC";
$holidays = [];
$result = mysqli_query($conn, $holidaysQuery);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $holidays[] = $row;
    }
}

// Get current month and year for calendar
$currentMonth = date('m');
$currentYear = date('Y');
$daysInMonth = date('t');
$firstDayOfMonth = date('w', strtotime("$currentYear-$currentMonth-01"));

// Check if OTP already exists and is valid
$currentOtpQuery = "SELECT * FROM otp WHERE teacher_id = ? AND expiry > NOW()";
$stmt = mysqli_prepare($conn, $currentOtpQuery);
$hasValidOtp = false;
$otpData = null;

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $hasValidOtp = mysqli_num_rows($result) > 0;
    $otpData = $hasValidOtp ? mysqli_fetch_assoc($result) : null;
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
$hasSubjects = false;

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $hasSubjects = mysqli_num_rows($result) > 0;
        while ($row = mysqli_fetch_assoc($result)) {
            $teacherSubjects[] = $row;
        }
    }
}

// Get teacher's assigned classes
$classesQuery = "SELECT tc.*, c.name as class_name, c.semester_id, s.name as subject_name, 
                 s.code as subject_code, sem.name as semester_name
                 FROM teacher_classes tc
                 JOIN classes c ON tc.class_id = c.id
                 JOIN subjects s ON c.subject_id = s.id
                 JOIN semesters sem ON c.semester_id = sem.id
                 WHERE tc.teacher_id = ?
                 ORDER BY sem.id, s.name";
$stmt = mysqli_prepare($conn, $classesQuery);
$teacherClasses = [];
$hasClasses = false;

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $hasClasses = mysqli_num_rows($result) > 0;
        while ($row = mysqli_fetch_assoc($result)) {
            $teacherClasses[] = $row;
        }
    }
}

// Group classes by semester
$classesBySemester = [];
foreach ($teacherClasses as $class) {
    $semesterId = $class['semester_id'];
    if (!isset($classesBySemester[$semesterId])) {
        $classesBySemester[$semesterId] = [
            'semester_name' => $class['semester_name'],
            'classes' => []
        ];
    }
    $classesBySemester[$semesterId]['classes'][] = $class;
}

// Fetch attendance data for the calendar
$attendanceQuery = "SELECT a.date, a.status, s.name as subject_name, s.code as subject_code
                    FROM attendance a
                    JOIN subjects s ON a.subject_id = s.id
                    WHERE a.teacher_id = ?";
$stmt = mysqli_prepare($conn, $attendanceQuery);
$attendanceData = [];
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attendanceData[] = $row;
        }
    }
}

// Get recent attendance requests
$recentRequestsQuery = "SELECT ar.*, u.name as student_name, u.roll_no, s.name as subject_name
                        FROM attendance_requests ar
                        JOIN users u ON ar.student_id = u.id
                        JOIN subjects s ON ar.subject_id = s.id
                        JOIN teacher_subjects ts ON s.id = ts.subject_id
                        WHERE ts.teacher_id = ? AND ar.status = 'pending'
                        ORDER BY ar.created_at DESC
                        LIMIT 5";
$stmt = mysqli_prepare($conn, $recentRequestsQuery);
$recentRequests = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $recentRequests[] = $row;
        }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
    .fc-event-holiday {
    background-color: red !important;
    color: white !important;
    border: none !important; 
}
.fc-daygrid-day-number {
    color: black!important;
    font-size: 1.2em!important;
    text-decoration: none!important;
    font-weight: bold!important;
}
    .legend-color {
        width: 20px;
        height: 20px;
        color: white;
        display: inline-block;
        margin-right: 5px;
        border-radius: 3px;
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
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-1"></i><?php echo $_SESSION['name'] ?? 'Teacher'; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
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
                    <h2><i class="fas fa-tachometer-alt me-2"></i>Teacher Dashboard</h2>
                    <div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Total Students</h5>
                                        <h2 class="mb-0"><?php echo $totalStudents; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-graduate fa-2x text-danger"></i>
                                    </div>
                                </div>
                                <a href="reports.php" class="text-muted mt-3 d-inline-block">View details <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Total Attendance</h5>
                                        <h2 class="mb-0"><?php echo $totalAttendance; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-calendar-check fa-2x text-danger"></i>
                                    </div>
                                </div>
                                <a href="reports.php" class="text-muted mt-3 d-inline-block">View details <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card dashboard-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">Pending Requests</h5>
                                        <h2 class="mb-0"><?php echo $pendingRequests; ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clipboard-list fa-2x text-danger"></i>
                                    </div>
                                </div>
                                <a href="attendance_requests.php" class="text-muted mt-3 d-inline-block">View details <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>OTP Generation</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($hasValidOtp): ?>
                                    <div class="text-center mb-3">
                                        <h6 class="text-muted">Current OTP</h6>
                                        <div class="otp-display"><?php echo $otpData['otp_code']; ?></div>
                                        <div class="otp-timer" id="otpTimer">Loading...</div>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                Radius: <?php echo $otpData['radius']; ?> meters
                                            </small>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <button class="btn btn-danger" id="generateOtpBtn" disabled>
                                            <i class="fas fa-key me-2"></i>Generate New OTP
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <form id="otpForm" action="generate_otp.php" method="POST">
                                        <div class="mb-3">
                                            <label for="radius" class="form-label">Geolocation Radius (meters)</label>
                                            <input type="range" class="form-range" id="radius" name="radius" min="10" max="100" value="50" oninput="updateRadiusValue(this.value)">
                                            <div class="text-center" id="radiusValue">50 meters</div>
                                        </div>
                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-danger" id="generateOtpBtn">
                                                <i class="fas fa-key"></i> Generate OTP
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Recent Attendance Requests</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentRequests)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No recent attendance requests.
                                    </div>
                                <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach ($recentRequests as $request): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between">
                                                    <h5><?php echo htmlspecialchars($request['student_name']); ?> (<?php echo htmlspecialchars($request['roll_no']); ?>)</h5>
                                                    <small><?php echo date('d M Y', strtotime($request['date'])); ?></small>
                                                </div>
                                                <p>
                                                    <strong>Subject:</strong> <?php echo htmlspecialchars($request['subject_name']); ?><br>
                                                    <strong>Reason:</strong> <?php echo htmlspecialchars($request['reason'] ?: 'No reason provided'); ?>
                                                </p>
                                                <div class="btn-group">
                                                    <a href="attendance_requests.php?action=approve&id=<?php echo $request['id']; ?>" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check"></i> Approve
                                                    </a>
                                                    <a href="attendance_requests.php?action=reject&id=<?php echo $request['id']; ?>" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-times"></i> Reject
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="text-end mt-3">
                                        <a href="attendance_requests.php" class="btn btn-danger btn-sm">View All Requests</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-12 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Today's Attendance Status</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($todayAttendance)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-calendar-check fa-4x text-muted mb-3"></i>
                                    <h5>No Attendance Records</h5>
                                    <p class="text-muted">Student attendance records will appear here once students mark their attendance.</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Roll No</th>
                                                <th>Subject</th>
                                                <th>Time</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($todayAttendance as $attendance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($attendance['student_name']); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['roll_no']); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['subject_name']); ?></td>
                                                <td><?php echo date('h:i A', strtotime($attendance['marked_at'])); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($attendance['status'] == 'present') ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($attendance['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                                <div class="text-end">
                                    <a href="mark_attendance.php" class="btn btn-outline-danger">Mark Attendance</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Holiday Calendar</h5>
                            </div>
                            <div class="card-body">
                                <div id="holidayCalendar"></div>
                                <div class="calendar-legend mt-3">
                                    <div class="legend-item">
                                        <div class="legend-color" style="background-color: red;"></div>
                                        <span>Holiday</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> 
            </div>
        </div>
    </div>

    <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
    <script>

        // Initialize FullCalendar
        document.addEventListener('DOMContentLoaded', function () {
    var calendarEl = document.getElementById('holidayCalendar');

    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth'
            },
            themeSystem: 'bootstrap5',
            fixedWeekCount: false,
            dayMaxEventRows: true,
            height: 'auto',
            events: [
                <?php foreach ($holidays as $holiday): ?>
                {
                    title: '<?php echo $holiday['name']; ?>',
                    start: '<?php echo $holiday['date']; ?>',
                    className: 'fc-event-holiday'
                },
                <?php endforeach; ?>
            ],
            dayCellDidMount: function (info) {
                const eventDate = new Date(info.date);

                // Highlight Saturdays
                if (eventDate.getDay() === 6) { // Check if the day is Saturday
                    info.el.style.backgroundColor = 'red'; // Set the background color to red
                    info.el.style.color = 'white'; // Set the text color to white
                }

                // Highlight holidays from the database
                <?php foreach ($holidays as $holiday): ?>
                // if (info.date.toISOString().split('T')[0] === '<?php echo $holiday['date']; ?>') {
                //     info.el.style.backgroundColor = 'red'; // Set the background color to red
                //     info.el.style.color = 'white'; // Set the text color to white
                // }
                <?php endforeach; ?>
            }
        });

        calendar.render();
    }});

        // Update radius value display
        function updateRadiusValue(val) {
            document.getElementById('radiusValue').innerText = val + ' meters';
        }
        
        // OTP Timer
        <?php if ($hasValidOtp): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const expiry = new Date('<?php echo $otpData['expiry']; ?>');
            const remainingSeconds = Math.floor((expiry - now) / 1000);
            
            if (remainingSeconds > 0) {
                const timerDisplay = document.getElementById('otpTimer');
                startOtpTimer(remainingSeconds, timerDisplay);
            }
        });
        <?php endif; ?>
        
        // Get location when generating OTP
        document.getElementById('otpForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            getLocation(function(locationData) {
                if (locationData.success) {
                    // Add location to form
                    const form = document.getElementById('otpForm');
                    
                    const latInput = document.createElement('input');
                    latInput.type = 'hidden';
                    latInput.name = 'latitude';
                    latInput.value = locationData.latitude;
                    form.appendChild(latInput);
                    
                    const lngInput = document.createElement('input');
                    lngInput.type = 'hidden';
                    lngInput.name = 'longitude';
                    lngInput.value = locationData.longitude;
                    form.appendChild(lngInput);
                    
                    // Submit form
                    form.submit();
                }
            });
        });
        
        // OTP Timer function
        function startOtpTimer(seconds, display) {
            let timer = seconds;
            const interval = setInterval(function () {
                const minutes = parseInt(timer / 60, 10);
                const seconds = parseInt(timer % 60, 10);
                
                display.textContent = minutes + ":" + (seconds < 10 ? "0" + seconds : seconds);
                
                if (--timer < 0) {
                    clearInterval(interval);
                    display.textContent = "Expired";
                    display.classList.add("expired");
                    document.getElementById('generateOtpBtn').disabled = false;
                }
            }, 1000);
        }
        
        // Get location function
        function getLocation(callback) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        callback({
                            success: true,
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude
                        });
                    },
                    function(error) {
                        alertify.error('Error getting location: ' + error.message);
                        callback({ success: false });
                    }
                );
            } else {
                alertify.error('Geolocation is not supported by this browser.');
                callback({ success: false });
            }
        }
        
        <?php if (isset($_SESSION['message'])): ?>
            alertify.<?php echo $_SESSION['message_type']; ?>('<?php echo $_SESSION['message']; ?>');
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>
    </script>
</body>
</html>