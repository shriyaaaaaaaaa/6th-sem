<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

// Get teacher ID
$teacher_id = $_SESSION['user_id'];

// Check if manual attendance marking is enabled in settings
$manualAttendanceEnabled = false;
$settingsQuery = "SELECT allow_manual_attendance FROM settings LIMIT 1";
$settingsResult = mysqli_query($conn, $settingsQuery);
if ($settingsResult && mysqli_num_rows($settingsResult) > 0) {
    $settings = mysqli_fetch_assoc($settingsResult);
    $manualAttendanceEnabled = (bool)$settings['allow_manual_attendance'];
}

// If manual attendance is disabled, redirect to dashboard with message
if (!$manualAttendanceEnabled) {
    setMessage('Manual attendance marking is disabled by administrator.', 'error');
    header('Location: dashboard.php');
    exit;
}

// Get semester and subject IDs from URL
$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : null;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : null;

// Fetch teacher's assigned subjects grouped by semester
$teacherSubjects = fetchTeacherSubjects($conn, $teacher_id);
$hasSubjects = !empty($teacherSubjects);

// Process attendance marking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    handleAttendanceMarking($conn, $teacher_id);
}

// Fetch students for selected semester and subject
$students = [];
$subjectDetails = [];
if ($semester_id && $subject_id) {
    // Verify this subject belongs to the teacher
    $isTeacherSubject = false;
    foreach ($teacherSubjects as $semester) {
        foreach ($semester['subjects'] as $subject) {
            if ($subject['subject_id'] == $subject_id && $semester['semester_id'] == $semester_id) {
                $isTeacherSubject = true;
                break 2;
            }
        }
    }
    
    if (!$isTeacherSubject) {
        setMessage('You are not authorized to mark attendance for this subject.', 'error');
        header('Location: mark_attendance.php');
        exit;
    }
    
    $students = fetchStudentsBySemesterAndSubject($conn, $semester_id, $subject_id);
    $subjectDetails = fetchSubjectDetails($conn, $subject_id);
}

// Fetch attendance for selected subject and date
$attendanceData = [];
if ($semester_id && $subject_id && isset($_POST['view_attendance'])) {
    $attendanceData = fetchAttendanceData($conn, $subject_id, $_POST['view_date']);
}

function fetchTeacherSubjects($conn, $teacher_id) {
    // Get subjects assigned to teacher during registration, grouped by semester
    $query = "SELECT s.id as subject_id, s.name as subject_name, s.code as subject_code, 
              s.semester_id, sem.name as semester_name
              FROM teacher_subjects ts
              JOIN subjects s ON ts.subject_id = s.id
              JOIN semesters sem ON s.semester_id = sem.id
              WHERE ts.teacher_id = ?
              ORDER BY s.semester_id, s.name";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $subjects = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $semester_id = $row['semester_id'];
        
        if (!isset($subjects[$semester_id])) {
            $subjects[$semester_id] = [
                'semester_id' => $semester_id,
                'semester_name' => $row['semester_name'],
                'subjects' => []
            ];
        }
        
        $subjects[$semester_id]['subjects'][] = [
            'subject_id' => $row['subject_id'],
            'subject_name' => $row['subject_name'],
            'subject_code' => $row['subject_code']
        ];
    }
    
    return $subjects;
}

function handleAttendanceMarking($conn, $teacher_id) {
    $semester_id = intval($_POST['semester_id']);
    $subject_id = intval($_POST['subject_id']);
    $date = mysqli_real_escape_string($conn, $_POST['date']);

    // Ensure only today's date is allowed
    $today = date('Y-m-d');
    if ($date !== $today) {
        setMessage('Only today\'s date is allowed for marking attendance.', 'error');
        return;
    }

    $students = $_POST['students'] ?? [];

    if ($semester_id <= 0 || $subject_id <= 0 || empty($date)) {
        setMessage('Invalid input data', 'error');
        return;
    }

    // Verify this subject belongs to the teacher
    $verifyQuery = "SELECT COUNT(*) as count FROM teacher_subjects 
                   WHERE teacher_id = ? AND subject_id = ?";
    $stmt = mysqli_prepare($conn, $verifyQuery);
    mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $subject_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    if ($row['count'] == 0) {
        setMessage('You are not authorized to mark attendance for this subject.', 'error');
        return;
    }

    // Get all students who belong to this semester
    $allStudents = fetchStudentIdsBySemester($conn, $semester_id);
    mysqli_begin_transaction($conn);

    try {
        // Find a class_id for this subject (or create one if needed)
        $class_id = getOrCreateClassForSubject($conn, $subject_id, $semester_id);
        
        markAttendance($conn, $students, $class_id, $subject_id, $teacher_id, 'present', $date);
        $absentStudents = array_diff($allStudents, $students);
        markAttendance($conn, $absentStudents, $class_id, $subject_id, $teacher_id, 'absent', $date);
        
        mysqli_commit($conn);
        setMessage('Attendance marked successfully', 'success');
    } catch (Exception $e) {
        mysqli_rollback($conn);
        setMessage('Error marking attendance: ' . $e->getMessage(), 'error');
    }

    header('Location: mark_attendance.php?semester_id=' . $semester_id . '&subject_id=' . $subject_id);
    exit;
}

function getOrCreateClassForSubject($conn, $subject_id, $semester_id) {
    // Check if a class exists for this subject
    $query = "SELECT id FROM classes WHERE subject_id = ? AND semester_id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }
    
    // No class exists, create one
    $subjectQuery = "SELECT name, code FROM subjects WHERE id = ?";
    $stmt = mysqli_prepare($conn, $subjectQuery);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $subject = mysqli_fetch_assoc($result);
    
    $class_name = $subject['code'] . " Class";
    $class_code = $subject['code'];
    
    $insertQuery = "INSERT INTO classes (name, code, semester_id, subject_id, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $insertQuery);
    mysqli_stmt_bind_param($stmt, "ssii", $class_name, $class_code, $semester_id, $subject_id);
    mysqli_stmt_execute($stmt);
    
    return mysqli_insert_id($conn);
}

function fetchStudentIdsBySemester($conn, $semester_id) {
    // Get all students in this semester
    $query = "SELECT id FROM users WHERE role = 'student' AND semester = ? AND status = 'active'";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $semester_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = [];
    while ($student = mysqli_fetch_assoc($result)) {
        $students[] = $student['id'];
    }
    return $students;
}

function markAttendance($conn, $students, $class_id, $subject_id, $teacher_id, $status, $date) {
    foreach ($students as $student_id) {
        $query = "INSERT INTO attendance (student_id, class_id, subject_id, teacher_id, status, date, marked_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE status = ?, teacher_id = ?, marked_at = NOW()";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iiissssi", $student_id, $class_id, $subject_id, $teacher_id, $status, $date, $status, $teacher_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Error marking attendance for student ID $student_id: " . mysqli_error($conn));
        }
    }
}

function fetchStudentsBySemesterAndSubject($conn, $semester_id, $subject_id) {
    // Get all students in this semester
    $query = "SELECT * FROM users WHERE role = 'student' AND semester = ? AND status = 'active' ORDER BY roll_no, name";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $semester_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }
    return $students;
}

function fetchSubjectDetails($conn, $subject_id) {
    $query = "SELECT s.name as subject_name, s.code as subject_code, sem.name as semester_name, sem.id as semester_id
              FROM subjects s
              JOIN semesters sem ON s.semester_id = sem.id
              WHERE s.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function fetchAttendanceData($conn, $subject_id, $viewDate) {
    $viewDate = mysqli_real_escape_string($conn, $viewDate);
    $query = "SELECT a.*, u.name as student_name, u.roll_no 
              FROM attendance a 
              JOIN users u ON a.student_id = u.id 
              WHERE a.subject_id = ? AND a.date = ? 
              ORDER BY u.roll_no, u.name";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $subject_id, $viewDate);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $attendanceData = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $attendanceData[] = $row;
    }
    return $attendanceData;
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
                        <a class="nav-link " href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="generate_otp.php">
                            <i class="fas fa-key me-1"></i>Generate OTP
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="mark_attendance.php">
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
                    <h2><i class="fas fa-user-check me-2 text-danger"></i>Mark Attendance</h2>
                    <div>
                        <span class="text-muted"><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>
                
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Manual Attendance:</strong> You can manually mark attendance for students in your assigned subjects.
                </div>
                
                <?php if (!$subject_id): ?>
                <!-- Subject Selection -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Select Subject</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($hasSubjects): ?>
                            <?php foreach ($teacherSubjects as $semester): ?>
                                <div class="mb-4">
                                    <h5 class="border-bottom pb-2 text-danger">
                                        <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($semester['semester_name']); ?>
                                    </h5>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Subject Code</th>
                                                    <th>Subject Name</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($semester['subjects'] as $subject): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                    <td>
                                                        <a href="mark_attendance.php?semester_id=<?php echo $semester['semester_id']; ?>&subject_id=<?php echo $subject['subject_id']; ?>" class="btn btn-danger">
                                                            <i class="fas fa-user-check me-1"></i> Mark Attendance
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-exclamation-circle fa-3x text-warning mb-3"></i>
                            <h5>No Subjects Assigned</h5>
                            <p class="text-muted">You haven't been assigned to any subjects yet. Please contact the administrator.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- Attendance Marking for Selected Subject -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-user-check me-2"></i>Mark Attendance for 
                            <?php echo htmlspecialchars($subjectDetails['subject_code'] ?? ''); ?> - 
                            <?php echo htmlspecialchars($subjectDetails['subject_name'] ?? 'Subject'); ?>
                            (<?php echo htmlspecialchars($subjectDetails['semester_name'] ?? 'Semester'); ?>)
                        </h5>
                        <a href="mark_attendance.php" class="btn btn-sm btn-light">
                            <i class="fas fa-arrow-left me-1"></i> Back to Subjects
                        </a>
                    </div>
                    <div class="card-body">
                        <form action="mark_attendance.php?semester_id=<?php echo $semester_id; ?>&subject_id=<?php echo $subject_id; ?>" method="POST" id="markAttendanceForm">
                            <input type="hidden" name="semester_id" value="<?php echo $semester_id; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                            
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="date" class="form-label">Date</label>
                                        <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" 
                                           min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required readonly>
                                        <small class="form-text text-muted">Only today's date is allowed for attendance marking</small>
                                    </div>
                                </div>
                                <div class="col-md-8 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="selectAllStudents">
                                        <label class="form-check-label" for="selectAllStudents">Select All Students</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Present</th>
                                            <th>Roll No</th>
                                            <th>Student Name</th>
                                            <th>Semester</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($students) > 0): ?>
                                            <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input student-checkbox" type="checkbox" name="students[]" value="<?php echo $student['id']; ?>" id="student<?php echo $student['id']; ?>">
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['roll_no']); ?></td>
                                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                                <td><?php echo $student['semester']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center">No students found for this semester</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                                <button type="submit" name="mark_attendance" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Save Attendance
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- View Attendance for Selected Subject -->
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>View Attendance Records</h5>
                    </div>
                    <div class="card-body">
                        <form action="mark_attendance.php?semester_id=<?php echo $semester_id; ?>&subject_id=<?php echo $subject_id; ?>" method="POST" class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="view_date" class="form-label">Select Date</label>
                                <input type="date" class="form-control" id="view_date" name="view_date" value="<?php echo date('Y-m-d'); ?>" 
                                           max="<?php echo date('Y-m-d'); ?>" required>
                                <small class="form-text text-muted">You can view past attendance records</small>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" name="view_attendance" class="btn btn-danger">
                                    <i class="fas fa-search me-2"></i>View Attendance
                                </button>
                            </div>
                        </form>
                        
                        <?php if (isset($_POST['view_attendance'])): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Roll No</th>
                                        <th>Student Name</th>
                                        <th>Status</th>
                                        <th>Marked At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($attendanceData) > 0): ?>
                                        <?php foreach ($attendanceData as $attendance): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($attendance['roll_no']); ?></td>
                                            <td><?php echo htmlspecialchars($attendance['student_name']); ?></td>
                                            <td>
                                                <?php if ($attendance['status'] == 'present'): ?>
                                                    <span class="badge bg-success">Present</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Absent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('h:i A', strtotime($attendance['marked_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center">No attendance records found for the selected date</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Select all students checkbox
        document.getElementById('selectAllStudents')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
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

