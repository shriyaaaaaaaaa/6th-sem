<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

$teacher_id = $_SESSION['user_id'];

// Get filters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Fetch all attendance records without filters
$recordsQuery = "SELECT a.*, u.name as student_name, u.roll_no, u.semester, 
                c.name as class_name, s.name as subject_name, s.code as subject_code
                FROM attendance a 
                JOIN users u ON a.student_id = u.id 
                JOIN classes c ON a.class_id = c.id
                JOIN subjects s ON a.subject_id = s.id
                WHERE a.teacher_id = ? AND u.role = 'student'
                ORDER BY a.date DESC, u.name ASC";

$stmt = mysqli_prepare($conn, $recordsQuery);
$records = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $teacher_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $records[] = $row;
        }
    }
}

// Get filters from the request
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Linear search function
function filterRecords($records, $filters) {
    $filtered = [];
    foreach ($records as $record) {
        $match = true;

        // Apply filters
        if ($filters['student_id'] > 0 && $record['student_id'] != $filters['student_id']) {
            $match = false;
        }
        if ($filters['semester'] > 0 && $record['semester'] != $filters['semester']) {
            $match = false;
        }
        if ($filters['class_id'] > 0 && $record['class_id'] != $filters['class_id']) {
            $match = false;
        }
        if ($filters['subject_id'] > 0 && $record['subject_id'] != $filters['subject_id']) {
            $match = false;
        }
        if (!empty($filters['date']) && $record['date'] != $filters['date']) {
            $match = false;
        }
        if (!empty($filters['status']) && $record['status'] != $filters['status']) {
            $match = false;
        }
        if ($filters['month'] > 0 && intval(date('m', strtotime($record['date']))) != $filters['month']) {
            $match = false;
        }
        if ($filters['year'] > 0 && intval(date('Y', strtotime($record['date']))) != $filters['year']) {
            $match = false;
        }

        if ($match) {
            $filtered[] = $record;
        }
    }
    return $filtered;
}

// Apply linear search to filter records
$filters = [
    'student_id' => $student_id,
    'semester' => $semester,
    'class_id' => $class_id,
    'subject_id' => $subject_id,
    'date' => $date,
    'status' => $status,
    'month' => $month,
    'year' => $year,
];
$filteredRecords = filterRecords($records, $filters);


// Get all students for filter
$studentsQuery = "SELECT DISTINCT u.id, u.name, u.roll_no 
                FROM users u
                JOIN attendance a ON u.id = a.student_id
                WHERE a.teacher_id = ? AND u.role = 'student'
                ORDER BY u.name ASC";
$stmt = mysqli_prepare($conn, $studentsQuery);
$students = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, "i", $teacher_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $students[] = $row;
      }
  }
}

// Get all semesters for filter
$semestersQuery = "SELECT DISTINCT u.semester 
                 FROM users u
                 JOIN attendance a ON u.id = a.student_id
                 WHERE a.teacher_id = ? AND u.role = 'student'
                 ORDER BY u.semester ASC";
$stmt = mysqli_prepare($conn, $semestersQuery);
$semesters = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, "i", $teacher_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $semesters[] = $row['semester'];
      }
  }
}

// Get all classes for filter
$classesQuery = "SELECT DISTINCT c.id, c.name 
               FROM classes c
               JOIN attendance a ON c.id = a.class_id
               WHERE a.teacher_id = ?
               ORDER BY c.name ASC";
$stmt = mysqli_prepare($conn, $classesQuery);
$classes = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, "i", $teacher_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $classes[] = $row;
      }
  }
}

// Get all subjects for filter
$subjectsQuery = "SELECT DISTINCT s.id, s.name, s.code 
                FROM subjects s
                JOIN attendance a ON s.id = a.subject_id
                WHERE a.teacher_id = ?
                ORDER BY s.name ASC";
$stmt = mysqli_prepare($conn, $subjectsQuery);
$subjects = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, "i", $teacher_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $subjects[] = $row;
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
                        <a class="nav-link active" href="attendance_records.php">
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
                  <h2><i class="fas fa-calendar-check me-2 text-danger"></i>Attendance Records</h2>
                  <div>
                      <span class="text-dark"><?php echo date('l, F d, Y'); ?></span>
                  </div>
              </div>
              
              <div class="card shadow-sm mb-4">
                  <div class="card-header bg-danger text-white">
                      <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Records</h5>
                  </div>
                  <div class="card-body">
                      <form method="GET" class="row g-3">
                          <div class="col-md-3">
                              <label for="student_id" class="form-label">Student</label>
                              <select class="form-select" id="student_id" name="student_id">
                                  <option value="0">All Students</option>
                                  <?php foreach ($students as $student): ?>
                                  <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($student['name'] . ' (' . $student['roll_no'] . ')'); ?>
                                  </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
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
                          <div class="col-md-2">
                              <label for="class_id" class="form-label">Class</label>
                              <select class="form-select" id="class_id" name="class_id">
                                  <option value="0">All Classes</option>
                                  <?php foreach ($classes as $class): ?>
                                  <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($class['name']); ?>
                                  </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="subject_id" class="form-label">Subject</label>
                              <select class="form-select" id="subject_id" name="subject_id">
                                  <option value="0">All Subjects</option>
                                  <?php foreach ($subjects as $subject): ?>
                                  <option value="<?php echo $subject['id']; ?>" <?php echo $subject_id == $subject['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['name']); ?>
                                  </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="month" class="form-label">Month</label>
                              <select class="form-select" id="month" name="month">
                                  <?php for ($i = 1; $i <= 12; $i++): ?>
                                  <option value="<?php echo $i; ?>" <?php echo $month == $i ? 'selected' : ''; ?>>
                                      <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                  </option>
                                  <?php endfor; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="year" class="form-label">Year</label>
                              <select class="form-select" id="year" name="year">
                                  <?php for ($i = date('Y') - 2; $i <= date('Y'); $i++): ?>
                                  <option value="<?php echo $i; ?>" <?php echo $year == $i ? 'selected' : ''; ?>>
                                      <?php echo $i; ?>
                                  </option>
                                  <?php endfor; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="status" class="form-label">Status</label>
                              <select class="form-select" id="status" name="status">
                                  <option value="">All</option>
                                  <option value="present" <?php echo $status == 'present' ? 'selected' : ''; ?>>Present</option>
                                  <option value="absent" <?php echo $status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="date" class="form-label">Specific Date</label>
                              <input type="date" class="form-control" id="date" name="date" value="<?php echo $date; ?>">
                          </div>
                          <div class="col-md-2 d-flex align-items-end">
                              <button type="submit" class="btn btn-danger w-100">
                                  <i class="fas fa-filter me-2"></i>Apply Filters
                              </button>
                          </div>
                      </form>
                  </div>
              </div>
              
              <div class="card shadow-sm">
                  <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                      <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Attendance Records</h5>
                      <div>
                          <a href="reports.php" class="btn btn-sm btn-dark">
                              <i class="fas fa-chart-bar me-1"></i>View Reports
                          </a>
                          <a href="mark_attendance.php" class="btn btn-sm btn-light">
                              <i class="fas fa-user-check me-1"></i>Mark Attendance
                          </a>
                      </div>
                  </div>
                  <div class="card-body">
                      <?php if (!empty($records)): ?>
                      <div class="table-responsive">
                          <table class="table table-hover">
                              <thead>
                                  <tr>
                                      <th>Date</th>
                                      <th>Student Name</th>
                                      <th>Roll No</th>
                                      <th>Class</th>
                                      <th>Subject</th>
                                      <th>Status</th>
                                      <th>Marked At</th>
                                      <th>Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($records as $record): ?>
                                  <tr>
                                      <td><?php echo date('d M Y', strtotime($record['date'])); ?></td>
                                      <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                      <td><?php echo htmlspecialchars($record['roll_no']); ?></td>
                                      <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                      <td><?php echo htmlspecialchars($record['subject_code'] . ' - ' . $record['subject_name']); ?></td>
                                      <td>
                                          <?php if ($record['status'] == 'present'): ?>
                                              <span class="badge bg-success">Present</span>
                                          <?php else: ?>
                                              <span class="badge bg-danger">Absent</span>
                                          <?php endif; ?>
                                      </td>
                                      <td><?php echo date('h:i A', strtotime($record['marked_at'])); ?></td>
                                      <td>
                                          <a href="mark_attendance.php?edit=1&id=<?php echo $record['id']; ?>" class="btn btn-sm btn-outline-danger">
                                              <i class="fas fa-edit"></i> Edit
                                          </a>
                                      </td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                      <div class="text-center py-5">
                          <i class="fas fa-calendar-times fa-4x text-danger mb-3"></i>
                          <h5>No Records Found</h5>
                          <p class="text-muted">No attendance records found for the selected filters.</p>
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
  <script>
      // Clear date field when month/year is selected
      document.getElementById('month').addEventListener('change', function() {
          document.getElementById('date').value = '';
      });
      
      document.getElementById('year').addEventListener('change', function() {
          document.getElementById('date').value = '';
      });
      
      // Clear month/year when specific date is selected
      document.getElementById('date').addEventListener('change', function() {
          if (this.value) {
              document.getElementById('month').selectedIndex = 0;
              document.getElementById('year').selectedIndex = 0;
          }
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