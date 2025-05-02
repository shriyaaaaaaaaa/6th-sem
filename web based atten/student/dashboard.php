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

// Get recent attendance records
$recentAttendanceQuery = "SELECT a.*, s.name as subject_name, s.code as subject_code, u.name as teacher_name
                        FROM attendance a
                        JOIN subjects s ON a.subject_id = s.id
                        JOIN users u ON a.teacher_id = u.id
                        WHERE a.student_id = ?
                        ORDER BY a.date DESC, a.marked_at DESC
                        LIMIT 5";
$stmt = mysqli_prepare($conn, $recentAttendanceQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$recentAttendance = mysqli_stmt_get_result($stmt);

// Get pending attendance requests
$pendingRequestsQuery = "SELECT ar.*, s.name as subject_name, s.code as subject_code, c.name as class_name
                        FROM attendance_requests ar
                        JOIN subjects s ON ar.subject_id = s.id
                        JOIN classes c ON ar.class_id = c.id
                        WHERE ar.student_id = ? AND ar.status = 'pending'
                        ORDER BY ar.created_at DESC";
$stmt = mysqli_prepare($conn, $pendingRequestsQuery);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$pendingRequests = mysqli_stmt_get_result($stmt);

// Get teachers for the student's semester based on teacher_subjects table
$teachersQuery = "SELECT DISTINCT u.id, u.name, u.email, u.phone, s.id as subject_id, 
                s.name as subject_name, s.code as subject_code
                FROM users u
                JOIN teacher_subjects ts ON u.id = ts.teacher_id
                JOIN subjects s ON ts.subject_id = s.id
                WHERE s.semester_id = ? AND u.role = 'teacher'
                ORDER BY s.name, u.name";
$stmt = mysqli_prepare($conn, $teachersQuery);
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$teachersResult = mysqli_stmt_get_result($stmt);

// Group teachers by subject
$teachersBySubject = [];
while ($teacher = mysqli_fetch_assoc($teachersResult)) {
    $subjectId = $teacher['subject_id'];
    if (!isset($teachersBySubject[$subjectId])) {
        $teachersBySubject[$subjectId] = [
            'subject_id' => $subjectId,
            'subject_name' => $teacher['subject_name'],
            'subject_code' => $teacher['subject_code'],
            'teachers' => []
        ];
    }
    $teachersBySubject[$subjectId]['teachers'][] = [
        'id' => $teacher['id'],
        'name' => $teacher['name'],
        'email' => $teacher['email'],
        'phone' => $teacher['phone']
    ];
}
// Get attendance data for calendar
$currentMonth = date('m');
$currentYear = date('Y');
$firstDay = date('Y-m-01');
$lastDay = date('Y-m-t');

// Get all subjects for the student's semester
$subjectsQuery = "SELECT id, name, code FROM subjects WHERE semester_id = ?";
$stmt = mysqli_prepare($conn, $subjectsQuery);
mysqli_stmt_bind_param($stmt, "i", $semester_id);
mysqli_stmt_execute($stmt);
$subjectsResult = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($subject = mysqli_fetch_assoc($subjectsResult)) {
    $subjects[$subject['id']] = $subject;
}

// Get attendance records for the current month grouped by date and subject
$attendanceQuery = "SELECT a.date, a.subject_id, a.status, s.name as subject_name, s.code as subject_code 
                  FROM attendance a 
                  JOIN subjects s ON a.subject_id = s.id 
                  WHERE a.student_id = ? AND a.date BETWEEN ? AND ?
                  ORDER BY a.date, s.name";
$stmt = mysqli_prepare($conn, $attendanceQuery);
mysqli_stmt_bind_param($stmt, "iss", $student_id, $firstDay, $lastDay);
mysqli_stmt_execute($stmt);
$attendanceResult = mysqli_stmt_get_result($stmt);

// Get attendance requests for the current month
$requestsCalendarQuery = "SELECT ar.*, s.name as subject_name, s.code as subject_code 
                        FROM attendance_requests ar 
                        JOIN subjects s ON ar.subject_id = s.id 
                        WHERE ar.student_id = ? AND ar.date BETWEEN ? AND ?";
$stmt = mysqli_prepare($conn, $requestsCalendarQuery);
mysqli_stmt_bind_param($stmt, "iss", $student_id, $firstDay, $lastDay);
mysqli_stmt_execute($stmt);
$requestsCalendarResult = mysqli_stmt_get_result($stmt);

// Get holidays for the current month
$holidaysQuery = "SELECT * FROM holidays WHERE date BETWEEN ? AND ?";
$stmt = mysqli_prepare($conn, $holidaysQuery);
mysqli_stmt_bind_param($stmt, "ss", $firstDay, $lastDay);
mysqli_stmt_execute($stmt);
$holidaysResult = mysqli_stmt_get_result($stmt);

// Prepare calendar data
$calendarData = [];

// Initialize calendar data structure for each day
$daysInMonth = date('t');
for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = date('Y-m-d', strtotime("$currentYear-$currentMonth-$day"));
    $calendarData[$date] = [
        'date' => $date,
        'isHoliday' => false,
        'holidayName' => '',
        'subjects' => [],
        'presentCount' => 0,
        'totalSubjects' => count($subjects),
        'attendancePercentage' => 0
    ];
}

// Add holidays to calendar data
while ($holiday = mysqli_fetch_assoc($holidaysResult)) {
    $date = $holiday['date'];
    if (isset($calendarData[$date])) {
        $calendarData[$date]['isHoliday'] = true;
        $calendarData[$date]['holidayName'] = $holiday['name'];
    }
}

// Add attendance records to calendar data
while ($attendance = mysqli_fetch_assoc($attendanceResult)) {
    $date = $attendance['date'];
    if (isset($calendarData[$date])) {
        $calendarData[$date]['subjects'][] = [
            'id' => $attendance['subject_id'],
            'name' => $attendance['subject_name'],
            'code' => $attendance['subject_code'],
            'status' => $attendance['status']
        ];
        
        if ($attendance['status'] == 'present') {
            $calendarData[$date]['presentCount']++;
        }
    }
}

// Add attendance requests to calendar data
while ($request = mysqli_fetch_assoc($requestsCalendarResult)) {
    $date = $request['date'];
    if (isset($calendarData[$date])) {
        $calendarData[$date]['subjects'][] = [
            'id' => $request['subject_id'],
            'name' => $request['subject_name'],
            'code' => $request['subject_code'],
            'status' => 'requested'
        ];
    }
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

// Calculate attendance percentage for each day
foreach ($calendarData as $date => $data) {
    if ($data['totalSubjects'] > 0 && !empty($data['subjects'])) {
        $calendarData[$date]['attendancePercentage'] = ($data['presentCount'] / count($data['subjects'])) * 100;
    }
}

// Convert calendar data to JSON for JavaScript
$calendarDataJson = json_encode($calendarData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Calendar - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Calendar styles */
        .calendar-container {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .calendar {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        .calendar th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            text-align: center;
            padding: 10px;
            border: 1px solid #dee2e6;
        }
        
        .calendar td {
            height: 100px;
            vertical-align: top;
            padding: 5px;
            border: 1px solid #dee2e6;
            position: relative;
        }
        
        .calendar .date-number {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .calendar .today {
            background-color:rgb(114, 146, 173);
        }
        
        .calendar .outside-month {
            background-color:rgb(250, 248, 248);
            color: #adb5bd;
        }
        
        .calendar .holiday {
            background-color:rgb(201, 44, 44);
        }
        
        /* Attendance status colors */
        .attendance-full {
            background-color: #198754; /* Dark green for 100% attendance */
            color: white;
        }
        
        .attendance-good {
            background-color: #75c275; /* Light green for ≥50% attendance */
            color: white;
        }
        
        .attendance-poor {
            background-color: #fd7e14; /* Orange for <50% attendance */
            color: white;
        }
        
        /* Tooltip styles */
        .attendance-tooltip {
            display: none;
            position: absolute;
            top: 0;
            left: 100%;
            z-index: 100;
            width: 250px;
            background-color: #aaa;
            border: 1px solid rgb(255, 255, 255);
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 10px;
        }
        
        .calendar td:hover .attendance-tooltip {
            display: block;
        }
        
        /* For cells on the right side of the calendar */
        .calendar td:nth-child(6) .attendance-tooltip,
        .calendar td:nth-child(7) .attendance-tooltip {
            left: auto;
            right: 100%;
        }
        
        .subject-list {
            margin-top: 8px;
            max-height: 150px;
            overflow-y: auto;
        }
        
        .subject-item {
            padding: 4px 0;
            border-bottom: 1px solid rgb(14, 13, 13);
        }
        
        .subject-item:last-child {
            border-bottom: none;
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
            justify-content: center;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            margin-right: 5px;
            border-radius: 3px;
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
                        <a class="nav-link active" href="dashboard.php">
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
                            <a class="nav-link" href="dashboard.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="attendance.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-calendar-check me-2"></i>My Attendance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="submit_otp.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-key me-2"></i>Submit OTP
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="requests.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-clipboard-list me-2"></i>My Requests
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profile.php" aria-label="Dashboard" style="color: black;">
                                <i class="fas fa-user-cog me-2"></i>Profile
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

             <!-- Main content -->
             <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-tachometer-alt me-2"></i>Student Dashboard</h1>
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
                                        <h5 class="card-title text-primary">Overall Attendance</h5>
                                        <h2 class="mb-0"><?php echo $attendancePercentage; ?>%</h2>
                                    </div>
                                    <div class="align-self-center">
                                        <div class="icon-bg">
                                            <i class="fas fa-chart-pie fa-2x text-primary"></i>
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
                                <a href="attendance.php" class="btn btn-sm btn-primary mt-3">View Details</a>
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
                                <a href="attendance.php" class="btn btn-sm btn-success mt-3">View Details</a>
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
                                <a href="attendance.php" class="btn btn-sm btn-danger mt-3">View Details</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Month Navigation -->
                    <h1 class="h2"><i class="fas fa-calendar-check me-2"></i>Atendance Calender</h1>
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <button id="prevMonth" class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-left me-1"></i> Previous Month
                            </button>
                            <h3 id="currentMonthDisplay"><?php echo date('F Y'); ?></h3>
                            <button id="nextMonth" class="btn btn-outline-secondary">
                                Next Month <i class="fas fa-chevron-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Attendance Calendar -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Monthly Attendance</h5>
                            </div>
                            <div class="card-body">
                                <div class="calendar-container">
                                    <table class="calendar" id="attendanceCalendar">
                                        <thead>
                                            <tr>
                                                <th>Sunday</th>
                                                <th>Monday</th>
                                                <th>Tuesday</th>
                                                <th>Wednesday</th>
                                                <th>Thursday</th>
                                                <th>Friday</th>
                                                <th>Saturday</th>
                                            </tr>
                                        </thead>
                                        <tbody id="calendarBody">
                                            <!-- Calendar will be generated by JavaScript -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Calendar Legend -->
                                <div class="legend">
                                    <div class="legend-item">
                                        <div class="legend-color attendance-full"></div>
                                        <span>Present (All Subjects)</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color attendance-good"></div>
                                        <span>Present (≥50% Subjects)</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color attendance-poor"></div>
                                        <span>Present (<50% Subjects)</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background-color:rgb(201, 44, 44);"></div>
                                        <span>Holiday</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="legend-color" style="background-color:rgb(114, 146, 173)"></div>
                                        <span>Today</span>
                                    </div>
                                </div>
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

                <!-- My Teachers -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>My Teachers</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($teachersBySubject) > 0): ?>
                                <div class="row">
                                    <?php foreach ($teachersBySubject as $subject): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card subject-card shadow-sm">
                                            <div class="card-header bg-light">
                                                <h5 class="mb-0">
                                                    <span class="badge bg-danger me-2"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <?php if (!empty($subject['teachers'])): ?>
                                                    <?php foreach ($subject['teachers'] as $teacher): ?>
                                                    <div class="teacher-info">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <div>
                                                                <h6 class="mb-1"><?php echo htmlspecialchars($teacher['name']); ?></h6>
                                                                <p class="mb-1 small"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($teacher['email']); ?></p>
                                                                <p class="mb-0 small"><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($teacher['phone']); ?></p>
                                                            </div>
                                                            <a href="request_attendance.php?teacher_id=<?php echo $teacher['id']; ?>&subject_id=<?php echo $subject['subject_id']; ?>" class="btn btn-sm btn-outline-">
                                                                <i class="fas fa-clipboard-list"></i> Request Attendance
                                                            </a>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-muted">No teachers assigned to this subject yet.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-user-tie fa-4x text-muted mb-3"></i>
                                    <h5>No Teachers Found</h5>
                                    <p class="text-muted">No teachers have been assigned to your semester subjects yet.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Attendance Section (Full Width) -->
                <div class="row mb-4">
                    <div class="col-md-12 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Attendance</h5>
                            </div>
                            <div class="card-body">
                                <?php if (mysqli_num_rows($recentAttendance) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Subject</th>
                                                <th>Teacher</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($attendance = mysqli_fetch_assoc($recentAttendance)): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($attendance['date'])); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['subject_code']); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['teacher_name']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($attendance['status'] == 'present') ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($attendance['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-end mt-3">
                                    <a href="attendance.php" class="btn btn-sm btn-danger">View All</a>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-calendar-check fa-4x text-muted mb-3"></i>
                                    <h5>No Recent Attendance</h5>
                                    <p class="text-muted">Your recent attendance records will appear here.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Attendance Requests -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Pending Attendance Requests</h5>
                            </div>
                            <div class="card-body">
                                <?php if (mysqli_num_rows($pendingRequests) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Class</th>
                                                <th>Subject</th>
                                                <th>Reason</th>
                                                <th>Requested On</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($request = mysqli_fetch_assoc($pendingRequests)): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($request['date'])); ?></td>
                                                <td><?php echo htmlspecialchars($request['class_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['subject_code']); ?> - <?php echo htmlspecialchars($request['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['reason'] ?: 'No reason provided'); ?></td>
                                                <td><?php echo date('d M Y H:i', strtotime($request['created_at'])); ?></td>
                                                <td>
                                                    <a href="cancel_request.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to cancel this request?');">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-end mt-3">
                                    <a href="requests.php" class="btn btn-sm btn-danger">View All Requests</a>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-clipboard-check fa-4x text-muted mb-3"></i>
                                    <h5>No Pending Requests</h5>
                                    <p class="text-muted">You don't have any pending attendance requests.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Calendar data from PHP
            const calendarData = <?php echo $calendarDataJson; ?>;
            
            // Current date tracking
            let currentDate = new Date();
            let currentMonth = currentDate.getMonth();
            let currentYear = currentDate.getFullYear();
            
            // Initialize calendar
            generateCalendar(currentMonth, currentYear);
            
            // Event listeners for navigation
            document.getElementById('prevMonth').addEventListener('click', function() {
                currentMonth--;
                if (currentMonth < 0) {
                    currentMonth = 11;
                    currentYear--;
                }
                generateCalendar(currentMonth, currentYear);
            });
            
            document.getElementById('nextMonth').addEventListener('click', function() {
                currentMonth++;
                if (currentMonth > 11) {
                    currentMonth = 0;
                    currentYear++;
                }
                generateCalendar(currentMonth, currentYear);
            });
            
            // Function to generate calendar
            function generateCalendar(month, year) {
                const calendarBody = document.getElementById('calendarBody');
                const monthDisplay = document.getElementById('currentMonthDisplay');
                
                // Update month display
                const monthNames = ["January", "February", "March", "April", "May", "June",
                                    "July", "August", "September", "October", "November", "December"];
                monthDisplay.textContent = `${monthNames[month]} ${year}`;
                
                // Clear previous calendar
                calendarBody.innerHTML = '';
                
                // Get first day of month and number of days
                const firstDay = new Date(year, month, 1);
                const lastDay = new Date(year, month + 1, 0);
                const daysInMonth = lastDay.getDate();
                const startingDay = firstDay.getDay(); // 0 = Sunday
                
                // Create calendar rows
                let date = 1;
                for (let i = 0; i < 6; i++) {
                    // Break if we've already used all days of the month
                    if (date > daysInMonth) break;
                    
                    const row = document.createElement('tr');
                    
                    // Create calendar cells for each day of the week
                    for (let j = 0; j < 7; j++) {
                        const cell = document.createElement('td');
                        
                        if ((i === 0 && j < startingDay) || date > daysInMonth) {
                            // Empty cell
                            cell.classList.add('outside-month');
                        } else {
                            // Format date string for lookup in calendarData
                            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(date).padStart(2, '0')}`;
                            const dayData = calendarData[dateStr] || {
                                isHoliday: false,
                                subjects: [],
                                presentCount: 0,
                                totalSubjects: 0,
                                attendancePercentage: 0
                            };
                            
                            // Create date number
                            const dateNumber = document.createElement('div');
                            dateNumber.className = 'date-number';
                            dateNumber.textContent = date;
                            cell.appendChild(dateNumber);
                            
                            // Check if today
                            const today = new Date();
                            if (date === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                                cell.classList.add('today');
                            }
                            
                            // Check if holiday
                            if (dayData.isHoliday) {
                                cell.classList.add('holiday');
                                
                                const holidayName = document.createElement('div');
                                holidayName.className = 'small text-muted';
                                holidayName.textContent = dayData.holidayName;
                                cell.appendChild(holidayName);
                            } 
                            // Apply attendance status color if there are subjects with attendance
                            else if (dayData.subjects.length > 0) {
                                if (dayData.attendancePercentage === 100) {
                                    cell.classList.add('attendance-full');
                                } else if (dayData.attendancePercentage >= 50) {
                                    cell.classList.add('attendance-good');
                                } else {
                                    cell.classList.add('attendance-poor');
                                }
                                
                                // Create tooltip for subject details
                                const tooltip = document.createElement('div');
                                tooltip.className = 'attendance-tooltip';
                                
                                // Tooltip header
                                const tooltipHeader = document.createElement('div');
                                tooltipHeader.className = 'fw-bold';
                                tooltipHeader.textContent = `Attendance: ${dayData.presentCount}/${dayData.subjects.length} subjects`;
                                tooltip.appendChild(tooltipHeader);
                                
                                // Tooltip percentage
                                const tooltipPercentage = document.createElement('div');
                                tooltipPercentage.className = 'small';
                                tooltipPercentage.textContent = `${Math.round(dayData.attendancePercentage)}% present`;
                                tooltip.appendChild(tooltipPercentage);
                                
                                // Subject list
                                const subjectList = document.createElement('div');
                                subjectList.className = 'subject-list';
                                
                                dayData.subjects.forEach(subject => {
                                    const subjectItem = document.createElement('div');
                                    subjectItem.className = 'subject-item';
                                    
                                    const statusBadge = document.createElement('span');
                                    statusBadge.className = `badge ${subject.status === 'present' ? 'bg-success' : 'bg-danger'} me-1`;
                                    statusBadge.textContent = subject.status === 'present' ? 'Present' : 'Absent';
                                    
                                    subjectItem.appendChild(statusBadge);
                                    subjectItem.appendChild(document.createTextNode(` ${subject.code} - ${subject.name}`));
                                    subjectList.appendChild(subjectItem);
                                });
                                
                                tooltip.appendChild(subjectList);
                                cell.appendChild(tooltip);
                            }
                            
                            date++;
                        }
                        
                        row.appendChild(cell);
                    }
                    
                    calendarBody.appendChild(row);
                }
            }
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