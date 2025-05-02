<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Get student data
$student_id = $_SESSION['user_id'];
$student = getStudentData($conn, $student_id);

// Get teachers for this student's semester
$teachersBySubject = getTeachersBySemester($conn, $student['semester']);

// Get holidays for date validation
$holidays = getHolidays($conn);
$holidaysJson = json_encode($holidays);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleAttendanceRequest($conn, $student_id, $_POST, $holidays);
}

function getStudentData($conn, $student_id) {
    $studentQuery = "SELECT * FROM users WHERE id = ? AND role = 'student'";
    $stmt = mysqli_prepare($conn, $studentQuery);
    $student = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $student_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $student = mysqli_fetch_assoc($result) ?: [];
    } else {
        setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
    }

    return $student;
}

function getTeachersBySemester($conn, $semester_id) {
    $teacherQuery = "SELECT u.id, u.name, u.email, ts.subject_id, s.name as subject_name, s.code as subject_code
                     FROM users u 
                     JOIN teacher_subjects ts ON u.id = ts.teacher_id
                     JOIN subjects s ON ts.subject_id = s.id
                     WHERE s.semester_id = ? AND u.role = 'teacher'
                     ORDER BY s.name, u.name";
    $stmt = mysqli_prepare($conn, $teacherQuery);
    $teachers = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $semester_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $teachers[] = $row;
        }
    }

    // Group teachers by subject
    return groupTeachersBySubject($teachers);
}

function groupTeachersBySubject($teachers) {
    $teachersBySubject = [];
    foreach ($teachers as $teacher) {
        $subjectId = $teacher['subject_id'];
        if (!isset($teachersBySubject[$subjectId])) {
            $teachersBySubject[$subjectId] = [
                'subject_name' => $teacher['subject_name'],
                'subject_code' => $teacher['subject_code'],
                'teachers' => []
            ];
        }
        $teachersBySubject[$subjectId]['teachers'][] = [
            'id' => $teacher['id'],
            'name' => $teacher['name'],
            'email' => $teacher['email'],
        ];
    }
    return $teachersBySubject;
}

function handleAttendanceRequest($conn, $student_id, $postData, $holidays) {
    $teacher_id = $postData['teacher_id'] ?? '';
    $subject_id = $postData['subject_id'] ?? '';
    $date = $postData['date'] ?? '';
    $reason = $postData['reason'] ?? '';
    $latitude = $postData['latitude'] ?? '';
    $longitude = $postData['longitude'] ?? '';

    // Validate inputs
    if (empty($teacher_id) || empty($subject_id) || empty($date) || empty($reason)) {
        setMessage('All fields are required', 'error');
        return;
    }

    $class_id = getClassId($conn, $subject_id);
    if ($class_id === null) {
        $class_id = createDefaultClass($conn, $subject_id);
    }

    if (isAttendanceMarked($conn, $student_id, $class_id, $subject_id, $date)) {
        setMessage('Attendance is already marked for this date', 'error');
        return;
    }

    if (isRequestExists($conn, $student_id, $class_id, $subject_id, $date)) {
        setMessage('An attendance request already exists for this date', 'error');
        return;
    }

    // Insert attendance request
    insertAttendanceRequest($conn, $student_id, $teacher_id, $class_id, $subject_id, $date, $reason, $latitude, $longitude);
}

function getClassId($conn, $subject_id) {
    $classQuery = "SELECT c.id FROM classes c WHERE c.subject_id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $classQuery);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    $classResult = mysqli_stmt_get_result($stmt);
    
    if ($classRow = mysqli_fetch_assoc($classResult)) {
        return $classRow['id'];
    }
    return null;
}

function createDefaultClass($conn, $subject_id) {
    $semester_id = getSemesterId($conn, $subject_id);
    $class_name = getSubjectDetails($conn, $subject_id)['code'] . " Class";

    $insertClassQuery = "INSERT INTO classes (name, semester_id, subject_id, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $insertClassQuery);
    mysqli_stmt_bind_param($stmt, "sii", $class_name, $semester_id, $subject_id);
    mysqli_stmt_execute($stmt);
    
    return mysqli_insert_id($conn);
}

function getSemesterId($conn, $subject_id) {
    $semesterQuery = "SELECT semester_id FROM subjects WHERE id = ?";
    $stmt = mysqli_prepare($conn, $semesterQuery);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    $semResult = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($semResult)['semester_id'];
}

function getSubjectDetails($conn, $subject_id) {
    $subjectQuery = "SELECT name, code FROM subjects WHERE id = ?";
    $stmt = mysqli_prepare($conn, $subjectQuery);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function isAttendanceMarked($conn, $student_id, $class_id, $subject_id, $date) {
    $attendanceQuery = "SELECT * FROM attendance WHERE student_id = ? AND class_id = ? AND subject_id = ? AND date = ?";
    $stmt = mysqli_prepare($conn, $attendanceQuery);
    mysqli_stmt_bind_param($stmt, "iiis", $student_id, $class_id, $subject_id, $date);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

function isRequestExists($conn, $student_id, $class_id, $subject_id, $date) {
    $requestQuery = "SELECT * FROM attendance_requests WHERE student_id = ? AND class_id = ? AND subject_id = ? AND date = ?";
    $stmt = mysqli_prepare($conn, $requestQuery);
    mysqli_stmt_bind_param($stmt, "iiis", $student_id, $class_id, $subject_id, $date);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

function insertAttendanceRequest($conn, $student_id, $teacher_id, $class_id, $subject_id, $date, $reason, $latitude, $longitude) {
    $insertQuery = "INSERT INTO attendance_requests (student_id, teacher_id, class_id, subject_id, date, reason, status, latitude, longitude, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $insertQuery);
    mysqli_stmt_bind_param($stmt, "iiiissdd", $student_id, $teacher_id, $class_id, $subject_id, $date, $reason, $latitude, $longitude);
    
    if (mysqli_stmt_execute($stmt)) {
        setMessage('Attendance request submitted successfully', 'success');
        header('Location: requests.php');
        exit;
    } else {
        setMessage('Failed to submit attendance request: ' . mysqli_error($conn), 'error');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Attendance - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .teacher-card {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .teacher-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .teacher-card.selected {
            border-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }
        .teacher-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9ecef;
        }
        .subject-section {
            margin-bottom: 30px;
            border-radius: 10px;
            overflow: hidden;
        }
        .subject-header {
            background-color: #dc3545;
            color: white;
            padding: 10px 15px;
            font-weight: bold;
        }
        .subject-body {
            padding: 15px;
            background-color: #f8f9fa;
        }
        .date-warning {
            display: none;
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
    </style>
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
                        <a class="nav-link active" href="requests.php">
                            <i class="fas fa-clipboard-list me-1"></i> My Requests
                        </a>
                    </li>
                </ul>
                <div class="d-flex">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['name'] ?? 'Student'); ?>
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
    <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
        <div class="position-sticky pt-3">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php" aria-label="Dashboard" style="color: black;">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="attendance.php" aria-label="Dashboard" style="color: black;">
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

    <div class="col-md-9 col-lg-10 ms-auto py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-clipboard-list me-2"></i>Request Attendance</h2>
            <div>
                <a href="requests.php" class="btn btn-outline-danger">
                    <i class="fas fa-list me-2"></i>View My Requests
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Submit Attendance Request</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="requestForm">
                            <div class="mb-3">
                                <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date" name="date" required max="<?php echo date('Y-m-d'); ?>">
                                <div class="form-text">Select a past date for which you want to request attendance.</div>
                                <div id="dateWarning" class="date-warning">
                                    <i class="fas fa-exclamation-circle me-1"></i>
                                    <span id="dateWarningText"></span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select" id="subject_id" name="subject_id" required>
                                    <option value="">-- Select Subject --</option>
                                    <?php foreach ($teachersBySubject as $subjectId => $subject): ?>
                                        <option value="<?php echo $subjectId; ?>">
                                            <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the subject for which you missed attendance.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Select Teacher <span class="text-danger">*</span></label>
                                <div id="teacherContainer" class="row g-3">
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            Please select a subject first to see available teachers.
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" id="teacher_id" name="teacher_id" required>
                            </div>

                            <div class="mb-3">
                                <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="reason" name="reason" rows="3" required></textarea>
                                <div class="form-text">Provide a valid reason for your absence or why you couldn't mark attendance.</div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="shareLocation" checked>
                                    <label class="form-check-label" for="shareLocation">
                                        Share my current location
                                    </label>
                                    <div class="form-text">Your current location will be shared with the teacher for verification.</div>
                                </div>
                            </div>

                            <input type="hidden" id="latitude" name="latitude">
                            <input type="hidden" id="longitude" name="longitude">

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger" id="submitBtn">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Information</h5>
                    </div>
                    <div class="card-body">
                        <h6><i class="fas fa-user-graduate me-2"></i>Student Details</h6>
                        <p>
                            <strong>Name:</strong> <?php echo htmlspecialchars($student['name']); ?><br>
                            <strong>Roll No:</strong> <?php echo htmlspecialchars($student['roll_no']); ?><br>
                            <strong>Semester:</strong> <?php echo $student['semester']; ?>
                        </p>

                        <hr>

                        <h6><i class="fas fa-chalkboard-teacher me-2"></i>Your Subjects</h6>
                        <?php if (!empty($teachersBySubject)): ?>
                            <ul class="list-group">
                                <?php foreach ($teachersBySubject as $subject): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($subject['subject_code']); ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                        </div>
                                        <span class="badge bg-danger rounded-pill"><?php echo count($subject['teachers']); ?> <?php echo count($subject['teachers']) == 1 ? 'teacher' : 'teachers'; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted">No subjects assigned to your semester.</p>
                        <?php endif; ?>

                        <hr>

                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Note:</strong> Attendance requests are subject to teacher approval. Provide accurate information to increase chances of approval.
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>How It Works</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li>Select the date for which you need attendance</li>
                            <li>Choose the subject and teacher</li>
                            <li>Provide a valid reason for your request</li>
                            <li>Submit the request</li>
                            <li>Your teacher will review and approve/reject</li>
                            <li>Check the status in "My Requests" page</li>
                        </ol>

                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb me-2"></i>
                            <strong>Tip:</strong> You can only request attendance for past dates, not future dates.
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
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('requestForm');
            const dateInput = document.getElementById('date');
            const subjectSelect = document.getElementById('subject_id');
            const teacherContainer = document.getElementById('teacherContainer');
            const teacherIdInput = document.getElementById('teacher_id');
            const latitudeInput = document.getElementById('latitude');
            const longitudeInput = document.getElementById('longitude');
            const shareLocationCheckbox = document.getElementById('shareLocation');
            const submitBtn = document.getElementById('submitBtn');
            const dateWarning = document.getElementById('dateWarning');
            const dateWarningText = document.getElementById('dateWarningText');

            // Set max date to today
            dateInput.max = new Date().toISOString().split('T')[0];

            // Calculate date 30 days ago
            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
            dateInput.min = thirtyDaysAgo.toISOString().split('T')[0];

            // Get location when page loads
            if (shareLocationCheckbox.checked) {
                getLocation(function(locationData) {
                    if (locationData.success) {
                        latitudeInput.value = locationData.latitude;
                        longitudeInput.value = locationData.longitude;
                    }
                });
            }

            // Update location when checkbox changes
            shareLocationCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    getLocation(function(locationData) {
                        if (locationData.success) {
                            latitudeInput.value = locationData.latitude;
                            longitudeInput.value = locationData.longitude;
                        }
                    });
                } else {
                    latitudeInput.value = '';
                    longitudeInput.value = '';
                }
            });

            // Date validation
            dateInput.addEventListener('change', function() {
                validateDate(this.value);
            });

            function validateDate(date) {
                if (!date) return;

                const selectedDate = new Date(date);
                selectedDate.setHours(0, 0, 0, 0);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                // Check if date is in the future
                if (selectedDate > today) {
                    dateWarning.style.display = 'block';
                    dateWarningText.textContent = 'Cannot request attendance for future dates';
                    return false;
                }

                // Check if date is too old
                if (selectedDate < thirtyDaysAgo) {
                    dateWarning.style.display = 'block';
                    dateWarningText.textContent = 'Cannot request attendance for dates older than 30 days';
                    return false;
                }

                // Check if date is a holiday
                const holidays = <?php echo $holidaysJson; ?>;
                if (holidays[date]) {
                    dateWarning.style.display = 'block';
                    dateWarningText.textContent = 'Cannot request attendance for a holiday: ' + holidays[date];
                    return false;
                }

                // Check if date is a weekend
                const dayOfWeek = selectedDate.getDay();
                if (dayOfWeek === 0 || dayOfWeek === 6) { // 0 = Sunday, 6 = Saturday
                    dateWarning.style.display = 'block';
                    dateWarningText.textContent = 'Cannot request attendance for weekends';
                    return false;
                }

                // Date is valid
                dateWarning.style.display = 'none';
                return true;
            }

            // Load teachers when subject changes
            subjectSelect.addEventListener('change', function() {
                const subjectId = this.value;
                teacherIdInput.value = ''; // Clear selected teacher

                if (!subjectId) {
                    teacherContainer.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Please select a subject first to see available teachers.
                            </div>
                        </div>
                    `;
                    return;
                }

                // Get teachers for this subject
                const teachersBySubject = <?php echo json_encode($teachersBySubject); ?>;
                const subject = teachersBySubject[subjectId];

                if (!subject || !subject.teachers || subject.teachers.length === 0) {
                    teacherContainer.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No teachers found for this subject.
                            </div>
                        </div>
                    `;
                    return;
                }

                // Display teachers
                let teachersHtml = '';
                subject.teachers.forEach(teacher => {
                  

                    teachersHtml += `
                        <div class="col-md-6">
                            <div class="card teacher-card" data-teacher-id="${teacher.id}">
                                <div class="card-body d-flex align-items-center">
                                    <i class="fas fa-chalkboard-teacher me-1"></i>
                                    <div>
                                        <h6 class="mb-1">${teacher.name}</h6>
                                        <p class="text-muted mb-0 small">${teacher.email}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });

                teacherContainer.innerHTML = teachersHtml;

                // Add click event to teacher cards
                document.querySelectorAll('.teacher-card').forEach(card => {
                    card.addEventListener('click', function() {
                        // Remove selected class from all cards
                        document.querySelectorAll('.teacher-card').forEach(c => {
                            c.classList.remove('selected');
                        });

                        // Add selected class to clicked card
                        this.classList.add('selected');

                        // Set teacher ID
                        teacherIdInput.value = this.getAttribute('data-teacher-id');
                    });
                });
            });

            // Form validation
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const date = dateInput.value;
                const subjectId = subjectSelect.value;
                const teacherId = teacherIdInput.value;
                const reason = document.getElementById('reason').value;

                // Validate date
                if (!validateDate(date)) {
                    alertify.error(dateWarningText.textContent);
                    dateInput.focus();
                    return false;
                }

                // Validate subject
                if (!subjectId) {
                    alertify.error('Please select a subject');
                    subjectSelect.focus();
                    return false;
                }

                // Validate teacher
                if (!teacherId) {
                    alertify.error('Please select a teacher');
                    return false;
                }

                // Validate reason
                if (reason.length < 10) {
                    alertify.error('Please provide a more detailed reason (at least 10 characters)');
                    document.getElementById('reason').focus();
                    return false;
                }

                // Disable submit button to prevent double submission
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

                // If location is not available, try to get it again
                if (shareLocationCheckbox.checked && (!latitudeInput.value || !longitudeInput.value)) {
                    getLocation(function(locationData) {
                        if (locationData.success) {
                            latitudeInput.value = locationData.latitude;
                            longitudeInput.value = locationData.longitude;
                        }
                        form.submit();
                    });
                } else {
                    form.submit();
                }
            });

            // Get location function
            function getLocation(callback) {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            if (callback) {
                                callback({
                                    success: true,
                                    latitude: position.coords.latitude,
                                    longitude: position.coords.longitude
                                });
                            }
                        },
                        function(error) {
                            console.error('Error getting location:', error);
                            if (callback) {
                                callback({
                                    success: false,
                                    error: error.message
                                });
                            }
                        },
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                    );
                } else {
                    console.error('Geolocation is not supported by this browser.');
                    if (callback) {
                        callback({
                            success: false,
                            error: 'Geolocation is not supported by this browser.'
                        });
                    }
                }
            }
        });

        <?php
        if (isset($_SESSION['message'])) {
            echo "alertify.notify('" . addslashes($_SESSION['message']) . "', '" . addslashes($_SESSION['message_type']) . "', 5);";
            unset($_SESSION['message']);
            unset($_SESSION['message_type']);
        }
        ?>
    </script>
</body>
</html>