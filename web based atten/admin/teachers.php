<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Handle teacher actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $teacher_id = intval($_GET['id']);
    
    if ($action === 'delete') {
        // Delete teacher
        $deleteQuery = "DELETE FROM users WHERE id = ? AND role = 'teacher'";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $teacher_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Also delete related records in teacher_subjects table
                $deleteSubjectsQuery = "DELETE FROM teacher_subjects WHERE teacher_id = ?";
                $stmtSubjects = mysqli_prepare($conn, $deleteSubjectsQuery);
                mysqli_stmt_bind_param($stmtSubjects, "i", $teacher_id);
                mysqli_stmt_execute($stmtSubjects);
                
                setMessage('Teacher deleted successfully', 'success');
            } else {
                setMessage('Error deleting teacher: ' . mysqli_error($conn), 'error');
            }
        } else {
            setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
        }
    }
    
    header('Location: teachers.php');
    exit;
}

// Handle add teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $password = $_POST['password'];
    $selected_subjects = isset($_POST['selected_subjects']) ? $_POST['selected_subjects'] : [];
    
    // Validate email
    $emailRegex = '/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/';
    if (!preg_match($emailRegex, $email)) {
        setMessage('Invalid email format', 'error');
        header('Location: teachers.php');
        exit;
    }
    
    // Validate phone
    if (!preg_match('/^\d{10}$/', $phone)) {
        setMessage('Phone number must be 10 digits', 'error');
        header('Location: teachers.php');
        exit;
    }
    
    // Check if email already exists
    $checkQuery = "SELECT * FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $checkQuery);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            setMessage('Email already exists', 'error');
            header('Location: teachers.php');
            exit;
        }
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Insert teacher
        $insertQuery = "INSERT INTO users (name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, 'teacher', 'approved', NOW())";
        $stmt = mysqli_prepare($conn, $insertQuery);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssss", $name, $email, $phone, $hashed_password);
            
            if (mysqli_stmt_execute($stmt)) {
                $teacher_id = mysqli_insert_id($conn);
                
                // If subjects were selected, assign them to the teacher
                if (!empty($selected_subjects)) {
                    $insertSubjectQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id, created_at) VALUES (?, ?, NOW())";
                    $stmtSubject = mysqli_prepare($conn, $insertSubjectQuery);
                    
                    if ($stmtSubject) {
                        mysqli_stmt_bind_param($stmtSubject, "ii", $teacher_id, $subject_id);
                        
                        foreach ($selected_subjects as $subject_id) {
                            mysqli_stmt_execute($stmtSubject);
                        }
                    }
                }
                
                mysqli_commit($conn);
                setMessage('Teacher added successfully', 'success');
            } else {
                throw new Exception(mysqli_error($conn));
            }
        } else {
            throw new Exception(mysqli_error($conn));
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        setMessage('Error adding teacher: ' . $e->getMessage(), 'error');
    }
    
    header('Location: teachers.php');
    exit;
}

// Handle assign subjects (AJAX request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subjects'])) {
    $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;
    $subjects = isset($_POST['subjects']) ? $_POST['subjects'] : [];
    
    // Validate data
    if ($teacher_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
        exit;
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Delete all existing subject assignments for this teacher
        $deleteQuery = "DELETE FROM teacher_subjects WHERE teacher_id = ?";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        mysqli_stmt_bind_param($stmt, "i", $teacher_id);
        mysqli_stmt_execute($stmt);
        
        // If subjects were selected, insert new assignments
        if (!empty($subjects)) {
            $insertQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id, created_at) VALUES (?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $insertQuery);
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
        
        echo json_encode([
            'success' => true, 
            'message' => 'Subjects for ' . htmlspecialchars($teacher['name']) . ' updated successfully',
            'subject_count' => count($subjects)
        ]);
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Error updating teacher subjects: ' . $e->getMessage()]);
        exit;
    }
}

// Get search filter
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// Build query based on filters
$teachersQuery = "SELECT u.*, 
               (SELECT COUNT(*) FROM teacher_subjects WHERE teacher_id = u.id) as subject_count 
               FROM users u 
               WHERE u.role = 'teacher'";

if (!empty($search)) {
    $teachersQuery .= " AND (u.name LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%')";
}

$teachersQuery .= " ORDER BY u.name ASC";
$teachersResult = mysqli_query($conn, $teachersQuery);
$teachers = [];

if ($teachersResult) {
    while ($row = mysqli_fetch_assoc($teachersResult)) {
        $teachers[] = $row;
    }
}

// Get all semesters for the modal
$semestersQuery = "SELECT * FROM semesters ORDER BY id";
$semestersResult = mysqli_query($conn, $semestersQuery);
$semesters = [];

if ($semestersResult) {
    while ($row = mysqli_fetch_assoc($semestersResult)) {
        $semesters[] = $row;
    }
}

// Get all subjects for the add teacher modal
$subjectsQuery = "SELECT s.*, sem.name as semester_name 
                 FROM subjects s 
                 JOIN semesters sem ON s.semester_id = sem.id 
                 ORDER BY sem.name, s.name";
$subjectsResult = mysqli_query($conn, $subjectsQuery);
$subjects = [];

if ($subjectsResult) {
    while ($row = mysqli_fetch_assoc($subjectsResult)) {
        $subjects[] = $row;
    }
}

// Get teacher subjects for hover display
$teacherSubjectsData = [];
foreach ($teachers as $teacher) {
    $teacherId = $teacher['id'];
    $subjectsQuery = "SELECT s.name, s.code, sem.name as semester_name
                     FROM teacher_subjects ts
                     JOIN subjects s ON ts.subject_id = s.id
                     JOIN semesters sem ON s.semester_id = sem.id
                     WHERE ts.teacher_id = ?
                     ORDER BY sem.name, s.name";
    
    $stmt = mysqli_prepare($conn, $subjectsQuery);
    mysqli_stmt_bind_param($stmt, "i", $teacherId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $teacherSubjects = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $teacherSubjects[] = $row;
    }
    
    $teacherSubjectsData[$teacherId] = $teacherSubjects;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Teachers - BCA Attendance System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css"/>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
  /* Custom styles for the subjects container */
  .semester-group {
      margin-bottom: 20px;
      border: 1px solid #dee2e6;
      border-radius: 5px;
      overflow: hidden;
  }
  
  .semester-header {
      background-color: #f8f9fa;
      padding: 10px 15px;
      border-bottom: 1px solid #dee2e6;
      font-weight: bold;
  }
  
  .semester-subjects {
      padding: 15px;
  }
  
  .select-all-container {
      margin-bottom: 10px;
  }
  
  /* Improve Select2 styling */
  .select2-container--bootstrap-5 .select2-selection {
      min-height: 38px;
  }
  
  /* Subject tooltip styling */
  .subject-tooltip {
      position: absolute;
      z-index: 1000;
      background-color: #fff;
      border: 1px solid #ddd;
      border-radius: 4px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      padding: 10px;
      max-width: 300px;
      display: none;
  }
  
  .subject-tooltip h6 {
      margin-bottom: 8px;
      border-bottom: 1px solid #eee;
      padding-bottom: 5px;
  }
  
  .subject-tooltip ul {
      list-style: none;
      padding-left: 0;
      margin-bottom: 0;
  }
  
  .subject-tooltip ul li {
      padding: 3px 0;
      font-size: 0.9rem;
  }
  
  .subject-tooltip .semester-title {
      font-weight: bold;
      margin-top: 8px;
      margin-bottom: 4px;
      color: #dc3545;
  }
  
  .subject-count {
      cursor: pointer;
      position: relative;
  }
  
  .subject-count:hover {
      background-color: #f8f9fa;
  }
  
  /* Loading spinner */
  .spinner-container {
      display: flex;
      justify-content: center;
      align-items: center;
      height: 200px;
  }
  
  /* Modal loading overlay */
  .modal-loading-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(255, 255, 255, 0.8);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 1060;
      border-radius: 0.3rem;
  }
</style>
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
                    <a class="nav-link active" href="teachers.php">
                        <i class="fas fa-chalkboard-teacher me-1"></i>Teachers
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="holidays.php">
                        <i class="fas fa-calendar-alt me-1"></i>Holidays
                    </a>
                </li>
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
                <h2><i class="fas fa-chalkboard-teacher me-2"></i>Manage Teachers</h2>
                <div>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                        <i class="fas fa-plus me-2"></i>Add New Teacher
                    </button>
                </div>
            </div>
            
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-search me-2"></i>Search Teachers</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-10">
                            <input type="text" class="form-control" id="search" name="search" placeholder="Search by name, email or phone" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Teacher List</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($teachers)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Subjects</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td><?php echo $teacher['id']; ?></td>
                                    <td><?php echo htmlspecialchars($teacher['name']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['phone']); ?></td>
                                    <td>
                                        <span class="badge bg-info subject-count" 
                                              data-teacher-id="<?php echo $teacher['id']; ?>"
                                              data-teacher-name="<?php echo htmlspecialchars($teacher['name']); ?>">
                                            <?php echo $teacher['subject_count']; ?>
                                        </span>
                                        
                                        <!-- Subject tooltip container -->
                                        <div id="subject-tooltip-<?php echo $teacher['id']; ?>" class="subject-tooltip">
                                            <h6><?php echo htmlspecialchars($teacher['name']); ?>'s Subjects</h6>
                                            <?php if (!empty($teacherSubjectsData[$teacher['id']])): ?>
                                                <?php 
                                                $currentSemester = '';
                                                foreach ($teacherSubjectsData[$teacher['id']] as $subject): 
                                                    if ($currentSemester != $subject['semester_name']) {
                                                        if ($currentSemester != '') {
                                                            echo '</ul>';
                                                        }
                                                        $currentSemester = $subject['semester_name'];
                                                        echo '<div class="semester-title">' . htmlspecialchars($currentSemester) . '</div>';
                                                        echo '<ul>';
                                                    }
                                                ?>
                                                    <li><?php echo htmlspecialchars($subject['code']); ?> - <?php echo htmlspecialchars($subject['name']); ?></li>
                                                <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p class="text-muted">No subjects assigned</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($teacher['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-primary assign-subjects" 
                                                data-bs-toggle="modal" data-bs-target="#assignSubjectsModal" 
                                                data-teacher-id="<?php echo $teacher['id']; ?>" 
                                                data-teacher-name="<?php echo htmlspecialchars($teacher['name']); ?>">
                                                <i class="fas fa-book"></i> Update
                                            </button>
                                            <a href="teachers.php?action=delete&id=<?php echo $teacher['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this teacher? This action cannot be undone and will remove all subject assignments.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-chalkboard-teacher fa-4x text-muted mb-3"></i>
                        <h5>No Teachers Found</h5>
                        <p class="text-muted">No teachers match your search criteria.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" aria-labelledby="addTeacherModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="addTeacherModalLabel"><i class="fas fa-plus me-2"></i>Add New Teacher</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="teachers.php" method="POST" id="addTeacherForm">
                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="text" class="form-control" id="phone" name="phone" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="selected_subjects" class="form-label">Assign Subjects</label>
                        <select class="form-select select2-multi" id="selected_subjects" name="selected_subjects[]" multiple>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['name']); ?> (<?php echo $subject['code']; ?>) - <?php echo $subject['semester_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">You can select multiple subjects</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addTeacherForm" name="add_teacher" class="btn btn-danger">Add Teacher</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Subjects Modal -->
<div class="modal fade" id="assignSubjectsModal" tabindex="-1" aria-labelledby="assignSubjectsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="assignSubjectsModalLabel"><i class="fas fa-book me-2"></i>Update Teacher Subjects</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body position-relative">
                <div id="modalLoadingOverlay" class="modal-loading-overlay d-none">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                
                <form id="assignSubjectsForm">
                    <input type="hidden" id="teacher_id" name="teacher_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Teacher Name</label>
                        <input type="text" class="form-control" id="teacher_name" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="semester_ids" class="form-label">Select Semesters</label>
                        <select class="form-select select2-multiple" id="semester_ids" name="semester_ids[]" multiple required>
                            <?php
                            // Get all semesters
                            foreach ($semesters as $semester) {
                                echo '<option value="' . $semester['id'] . '">' . htmlspecialchars($semester['name']) . '</option>';
                            }
                            ?>
                        </select>
                        <div class="form-text">Select multiple semesters to view and assign subjects.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Subjects</label>
                        <div id="subjects_container" class="border rounded p-3 bg-light">
                            <div class="text-center text-  class="border rounded p-3 bg-light">
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-info-circle fa-2x mb-2"></i>
                                <p>Please select at least one semester first to view available subjects</p>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="saveSubjectsBtn" class="btn btn-danger">Save Subject Assignments</button>
            </div>
        </div>
    </div>
</div>

<!-- Subject tooltip template for dynamic creation -->
<div id="subject-tooltip-template" class="subject-tooltip d-none">
    <h6 class="teacher-name"></h6>
    <div class="subject-list"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
<script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
    $(document).ready(function() {
        // Initialize Select2 for multiple select
        $('.select2-multiple, .select2-multi').select2({
            theme: 'bootstrap-5',
            placeholder: 'Select options',
            width: '100%'
        });
        
        // Phone number validation
        $('#phone').on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 10) {
                this.value = this.value.slice(0, 10);
            }
        });
        
        // Email validation
        $('#email').on('blur', function() {
            const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;
            if (!emailRegex.test(this.value)) {
                alertify.error('Please enter a valid email address');
                this.focus();
            }
        });
        
        // Handle Assign Subjects button click
        $('.assign-subjects').on('click', function() {
            const teacherId = $(this).data('teacher-id');
            const teacherName = $(this).data('teacher-name');
            
            $('#teacher_id').val(teacherId);
            $('#teacher_name').val(teacherName);
            
            // Reset semester selection
            $('#semester_ids').val(null).trigger('change');
            $('#subjects_container').html(`
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Please select at least one semester first to view available subjects</p>
                </div>
            `);
            
            // Load current subject assignments for this teacher
            loadTeacherSubjects(teacherId);
        });
        
        // Handle semester selection change
        $('#semester_ids').on('change', function() {
            const semesterIds = $(this).val();
            const teacherId = $('#teacher_id').val();
            
            if (semesterIds && semesterIds.length > 0) {
                // Show loading indicator
                $('#subjects_container').html(`
                    <div class="text-center py-4">
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading subjects...</p>
                    </div>
                `);
                
                // Fetch subjects for the selected semesters
                $.ajax({
                    url: 'get_subjects_for_semesters.php',
                    type: 'GET',
                    data: {
                        semester_ids: semesterIds,
                        teacher_id: teacherId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.subjects && data.subjects.length > 0) {
                            let html = '';
                            
                            // Group subjects by semester
                            const semesterGroups = {};
                            
                            // Initialize semester groups
                            data.semesters.forEach(semester => {
                                semesterGroups[semester.id] = {
                                    name: semester.name,
                                    subjects: []
                                };
                            });
                            
                            // Group subjects by semester
                            data.subjects.forEach(subject => {
                                if (semesterGroups[subject.semester_id]) {
                                    semesterGroups[subject.semester_id].subjects.push(subject);
                                }
                            });
                            
                            // Generate HTML for each semester group
                            Object.keys(semesterGroups).forEach(semesterId => {
                                const group = semesterGroups[semesterId];
                                
                                if (group.subjects.length > 0) {
                                    html += `
                                        <div class="semester-group mb-4">
                                            <div class="semester-header">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <h5 class="mb-0">${group.name}</h5>
                                                    <div class="select-all-container">
                                                        <div class="form-check">
                                                            <input class="form-check-input select-all-semester" type="checkbox" 
                                                                id="select_all_${semesterId}" data-semester="${semesterId}">
                                                            <label class="form-check-label" for="select_all_${semesterId}">
                                                                Select All
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="semester-subjects">
                                                <div class="row">
                                    `;
                                    
                                    group.subjects.forEach(subject => {
                                        const isAssigned = data.assigned_subjects.includes(parseInt(subject.id));
                                        
                                        html += `
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input subject-checkbox semester-${semesterId}" 
                                                        type="checkbox" name="subjects[]" 
                                                        value="${subject.id}" id="subject_${subject.id}" 
                                                        ${isAssigned ? 'checked' : ''}>
                                                    <label class="form-check-label" for="subject_${subject.id}">
                                                        ${subject.name} (${subject.code}) - ${subject.credits} credits
                                                    </label>
                                                </div>
                                            </div>
                                        `;
                                    });
                                    
                                    html += `
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }
                            });
                            
                            $('#subjects_container').html(html);
                            
                            // Add event listeners for "Select All" checkboxes
                            $('.select-all-semester').on('change', function() {
                                const semesterId = $(this).data('semester');
                                const isChecked = $(this).prop('checked');
                                $(`.semester-${semesterId}`).prop('checked', isChecked);
                            });
                            
                            // Update "Select All" checkbox when individual checkboxes change
                            $('.subject-checkbox').on('change', function() {
                                const semesterId = $(this).attr('class').split('semester-')[1].split(' ')[0];
                                const totalCheckboxes = $(`.semester-${semesterId}`).length;
                                const checkedCheckboxes = $(`.semester-${semesterId}:checked`).length;
                                $(`#select_all_${semesterId}`).prop('checked', totalCheckboxes === checkedCheckboxes);
                            });
                            
                            // Initialize "Select All" checkboxes based on current state
                            data.semesters.forEach(semester => {
                                const semesterId = semester.id;
                                const totalCheckboxes = $(`.semester-${semesterId}`).length;
                                const checkedCheckboxes = $(`.semester-${semesterId}:checked`).length;
                                $(`#select_all_${semesterId}`).prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
                            });
                            
                        } else {
                            $('#subjects_container').html(`
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                                    <p>No subjects found for the selected semesters</p>
                                </div>
                            `);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error fetching subjects:', error);
                        $('#subjects_container').html(`
                            <div class="text-center text-danger py-4">
                                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                <p>Error loading subjects. Please try again.</p>
                                <p class="small text-muted mt-2">Technical details: ${error}</p>
                            </div>
                        `);
                    }
                });
            } else {
                // Reset subjects container if no semester selected
                $('#subjects_container').html(`
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                        <p>Please select at least one semester first to view available subjects</p>
                    </div>
                `);
            }
        });
        
        // Function to load teacher's current subject assignments
        function loadTeacherSubjects(teacherId) {
            $.ajax({
                url: 'get_teacher_subjects.php',
                type: 'GET',
                data: { teacher_id: teacherId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        // Pre-select the semesters that the teacher already has subjects in
                        const semesterIds = [...new Set(data.subjects.map(subject => subject.semester_id))];
                        $('#semester_ids').val(semesterIds).trigger('change');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading teacher subjects:', error);
                }
            });
        }
        
        // Handle Save Subject Assignments button click
        $('#saveSubjectsBtn').on('click', function() {
            const teacherId = $('#teacher_id').val();
            const teacherName = $('#teacher_name').val();
            
            // Get all selected subjects
            const selectedSubjects = [];
            $('input[name="subjects[]"]:checked').each(function() {
                selectedSubjects.push($(this).val());
            });
            
            // Show loading overlay
            $('#modalLoadingOverlay').removeClass('d-none');
            
            // Send AJAX request to update subjects
            $.ajax({
                url: 'teachers.php',
                type: 'POST',
                data: {
                    update_subjects: 1,
                    teacher_id: teacherId,
                    subjects: selectedSubjects
                },
                dataType: 'json',
                success: function(response) {
                    // Hide loading overlay
                    $('#modalLoadingOverlay').addClass('d-none');
                    
                    if (response.success) {
                        // Close modal
                        $('#assignSubjectsModal').modal('hide');
                        
                        // Show success message
                        alertify.success(response.message);
                        
                        // Update subject count in the table
                        $(`span.subject-count[data-teacher-id="${teacherId}"]`).text(response.subject_count);
                        
                        // Reload the page to refresh the subject tooltips
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        // Show error message
                        alertify.error(response.message);
                    }
                },
                error: function(xhr, status, error) {
                    // Hide loading overlay
                    $('#modalLoadingOverlay').addClass('d-none');
                    
                    console.error('Error updating subjects:', error);
                    alertify.error('Error updating subjects. Please try again.');
                }
            });
        });
        
        // Handle subject count hover to show tooltip
        $('.subject-count').on('mouseenter', function() {
            const teacherId = $(this).data('teacher-id');
            const tooltipId = `#subject-tooltip-${teacherId}`;
            
            // Position the tooltip
            const $tooltip = $(tooltipId);
            const $badge = $(this);
            const badgeOffset = $badge.offset();
            
            $tooltip.css({
                top: badgeOffset.top + $badge.outerHeight() + 5,
                left: badgeOffset.left - ($tooltip.outerWidth() / 2) + ($badge.outerWidth() / 2)
            }).show();
        }).on('mouseleave', function() {
            const teacherId = $(this).data('teacher-id');
            $(`#subject-tooltip-${teacherId}`).hide();
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
