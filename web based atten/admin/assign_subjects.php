<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get all teachers
$teachersQuery = "SELECT id, name, email FROM users WHERE role = 'teacher' ORDER BY name";
$teachersResult = mysqli_query($conn, $teachersQuery);
$teachers = [];

if ($teachersResult) {
    while ($row = mysqli_fetch_assoc($teachersResult)) {
        $teachers[] = $row;
    }
}

// Get all semesters
$semestersQuery = "SELECT * FROM semesters ORDER BY id";
$semestersResult = mysqli_query($conn, $semestersQuery);
$semesters = [];

if ($semestersResult) {
    while ($row = mysqli_fetch_assoc($semestersResult)) {
        $semesters[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_subjects'])) {
    $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;
    $semester_id = isset($_POST['semester_id']) ? intval($_POST['semester_id']) : 0;
    $subjects = isset($_POST['subjects']) ? $_POST['subjects'] : [];
    
    // Validate data
    if ($teacher_id <= 0 || $semester_id <= 0) {
        setMessage('Invalid teacher or semester selected', 'error');
        header('Location: assign_subjects.php');
        exit;
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // First, remove existing subject assignments for this teacher and semester
        $deleteQuery = "DELETE FROM teacher_subjects WHERE teacher_id = ? AND subject_id IN (
            SELECT id FROM subjects WHERE semester_id = ?
        )";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $semester_id);
        mysqli_stmt_execute($stmt);
        
        // If subjects were selected, insert new assignments
        if (!empty($subjects)) {
            // Prepare the insert statement
            $insertQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id, created_at) VALUES (?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $insertQuery);
            
            // Bind parameters and execute for each subject
            mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $subject_id);
            
            foreach ($subjects as $subject_id) {
                $subject_id = intval($subject_id);
                mysqli_stmt_execute($stmt);
            }
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        // Get teacher name for the success message
        $teacherQuery = "SELECT name FROM users WHERE id = ? AND role = 'teacher'";
        $stmt = mysqli_prepare($conn, $teacherQuery);
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $teacher = mysqli_fetch_assoc($result);
        
        // Get semester name for the success message
        $semesterQuery = "SELECT name FROM semesters WHERE id = ?";
        $stmt = mysqli_prepare($conn, $semesterQuery);
        mysqli_stmt_bind_param($stmt, "i", $semester_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $semester = mysqli_fetch_assoc($result);
        
        // Set success message
        $message = 'Subjects for ' . htmlspecialchars($teacher['name']) . ' in ' . htmlspecialchars($semester['name']) . ' updated successfully';
        setMessage($message, 'success');
        
        // Log the activity
        $activity = 'Updated subject assignments for teacher ID ' . $teacher_id . ' in semester ID ' . $semester_id;
        logActivity($_SESSION['user_id'], $activity);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        setMessage('Error updating teacher subjects: ' . $e->getMessage(), 'error');
    }
    
    // Redirect back to assign subjects page
    header('Location: assign_subjects.php');
    exit;
}

// Get current assignments if teacher and semester are selected via GET
$selectedTeacher = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
$selectedSemester = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
$assignedSubjects = [];

if ($selectedTeacher > 0 && $selectedSemester > 0) {
    // Get assigned subjects for the selected teacher and semester
    $assignedQuery = "SELECT ts.subject_id 
                     FROM teacher_subjects ts
                     JOIN subjects s ON ts.subject_id = s.id
                     WHERE ts.teacher_id = ? AND s.semester_id = ?";
    
    $stmt = mysqli_prepare($conn, $assignedQuery);
    mysqli_stmt_bind_param($stmt, "ii", $selectedTeacher, $selectedSemester);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $assignedSubjects[] = $row['subject_id'];
    }
    
    // Get subjects for selected semester
    $subjectsQuery = "SELECT id, name, code, credits 
                     FROM subjects 
                     WHERE semester_id = ? 
                     ORDER BY name";
    
    $stmt = mysqli_prepare($conn, $subjectsQuery);
    mysqli_stmt_bind_param($stmt, "i", $selectedSemester);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $semesterSubjects = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $semesterSubjects[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Subjects - BCA Attendance System</title>
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
                        <a class="nav-link active" href="assign_subjects.php">
                            <i class="fas fa-book me-1"></i>Assign Subjects
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
            <div class="col-md-3 col-lg-2 sidebar p-0">
                <div class="d-flex flex-column p-3">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="students.php" class="nav-link">
                        <i class="fas fa-user-graduate me-2"></i>Students
                    </a>
                    <a href="teachers.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher me-2"></i>Teachers
                    </a>
                    <a href="assign_subjects.php" class="nav-link active">
                        <i class="fas fa-book me-2"></i>Assign Subjects
                    </a>
                    <a href="attendance_requests.php" class="nav-link">
                        <i class="fas fa-clipboard-check me-2"></i>Attendance Requests
                    </a>
                    <a href="attendance_records.php" class="nav-link">
                        <i class="fas fa-calendar-check me-2"></i>Attendance Records
                    </a>
                    <a href="reports.php" class="nav-link">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a href="settings.php" class="nav-link">
                        <i class="fas fa-cog me-2"></i>Settings
                    </a>
                </div>
            </div>
            
            <div class="col-md-9 col-lg-10 ms-auto py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-book me-2"></i>Assign Subjects to Teachers</h2>
                </div>
                
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-search me-2"></i>Select Teacher and Semester</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-5">
                                <label for="teacher_id" class="form-label">Teacher</label>
                                <select class="form-select" id="teacher_id" name="teacher_id" required>
                                    <option value="">-- Select Teacher --</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>" <?php echo ($selectedTeacher == $teacher['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($teacher['name']); ?> (<?php echo htmlspecialchars($teacher['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="semester_id" class="form-label">Semester</label>
                                <select class="form-select" id="semester_id" name="semester_id" required>
                                    <option value="">-- Select Semester --</option>
                                    <?php foreach ($semesters as $semester): ?>
                                        <option value="<?php echo $semester['id']; ?>" <?php echo ($selectedSemester == $semester['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($semester['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="fas fa-search me-2"></i>Load Subjects
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if ($selectedTeacher > 0 && $selectedSemester > 0): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list-alt me-2"></i>Assign Subjects for 
                            <?php 
                            $teacherName = '';
                            foreach ($teachers as $t) {
                                if ($t['id'] == $selectedTeacher) {
                                    $teacherName = $t['name'];
                                    break;
                                }
                            }
                            
                            $semesterName = '';
                            foreach ($semesters as $s) {
                                if ($s['id'] == $selectedSemester) {
                                    $semesterName = $s['name'];
                                    break;
                                }
                            }
                            
                            echo htmlspecialchars($teacherName) . ' - ' . htmlspecialchars($semesterName); 
                            ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($semesterSubjects)): ?>
                        <form action="assign_subjects.php" method="POST">
                            <input type="hidden" name="teacher_id" value="<?php echo $selectedTeacher; ?>">
                            <input type="hidden" name="semester_id" value="<?php echo $selectedSemester; ?>">
                            
                            <div class="row mb-4">
                                <?php foreach ($semesterSubjects as $subject): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="subjects[]" 
                                            value="<?php echo $subject['id']; ?>" id="subject_<?php echo $subject['id']; ?>" 
                                            <?php echo in_array($subject['id'], $assignedSubjects) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="subject_<?php echo $subject['id']; ?>">
                                            <?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>) - <?php echo $subject['credits']; ?> credits
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="d-flex justify-content-end">
                                <button type="submit" name="assign_subjects" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Save Subject Assignments
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-circle fa-4x text-muted mb-3"></i>
                            <h5>No Subjects Found</h5>
                            <p class="text-muted">No subjects are available for the selected semester.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Current Teacher-Subject Assignments</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Teacher</th>
                                        <th>Subject</th>
                                        <th>Semester</th>
                                        <th>Assigned On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Get current assignments
                                    $assignmentsQuery = "SELECT ts.id, u.name as teacher_name, s.name as subject_name, 
                                                        s.code as subject_code, sem.name as semester_name, 
                                                        ts.created_at, u.id as teacher_id, sem.id as semester_id
                                                    FROM teacher_subjects ts
                                                    JOIN users u ON ts.teacher_id = u.id
                                                    JOIN subjects s ON ts.subject_id = s.id
                                                    JOIN semesters sem ON s.semester_id = sem.id
                                                    ORDER BY u.name, sem.name, s.name";
                                    
                                    $assignmentsResult = mysqli_query($conn, $assignmentsQuery);
                                    
                                    if ($assignmentsResult && mysqli_num_rows($assignmentsResult) > 0):
                                        while ($assignment = mysqli_fetch_assoc($assignmentsResult)):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assignment['teacher_name']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['subject_name']); ?> (<?php echo htmlspecialchars($assignment['subject_code']); ?>)</td>
                                        <td><?php echo htmlspecialchars($assignment['semester_name']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($assignment['created_at'])); ?></td>
                                        <td>
                                            <a href="assign_subjects.php?teacher_id=<?php echo $assignment['teacher_id']; ?>&semester_id=<?php echo $assignment['semester_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <i class="fas fa-info-circle fa-2x text-muted mb-2"></i>
                                            <p class="mb-0">No teacher-subject assignments found.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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