<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

$teacher_id = $_SESSION['user_id'];

// Get filters
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$subject = isset($_GET['subject']) ? $_GET['subject'] : '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01'); // First day of current month
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d'); // Current date
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'summary';

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
$subjectsQuery = "SELECT DISTINCT s.name, s.code, s.id 
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
          if (!empty($row['name'])) {
              $subjects[] = $row;
          }
      }
  }
}

// Base query for attendance data
$baseQuery = "FROM 
              users u
            LEFT JOIN 
              attendance a ON u.id = a.student_id 
              AND a.date BETWEEN ? AND ? 
              AND a.teacher_id = ?";

// Add filters
$whereClause = " WHERE u.role = 'student'";
$params = [$from_date, $to_date, $teacher_id];
$types = "ssi";

if ($semester > 0) {
  $whereClause .= " AND u.semester = ?";
  $params[] = $semester;
  $types .= "i";
}

if (!empty($subject)) {
  $whereClause .= " AND (a.subject_id = ? OR a.subject_id IS NULL)";
  $params[] = $subject;
  $types .= "s";
}

if ($class_id > 0) {
  $whereClause .= " AND (a.class_id = ? OR a.class_id IS NULL)";
  $params[] = $class_id;
  $types .= "i";
}

// Get student-wise attendance summary
$summaryQuery = "SELECT 
                  u.id, 
                  u.name, 
                  u.roll_no, 
                  u.semester,
                  COUNT(a.id) as total_days,
                  SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
                  ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
              $baseQuery
              $whereClause
              GROUP BY u.id 
              ORDER BY u.semester ASC, u.roll_no ASC, u.name ASC";

$stmt = mysqli_prepare($conn, $summaryQuery);
$summaryData = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $summaryData[] = $row;
      }
  }
}

// Get date-wise attendance data
$dateQuery = "SELECT 
              DATE(a.date) as attendance_date,
              s.name as subject_name,
              s.code as subject_code,
              c.name as class_name,
              COUNT(a.id) as total_students,
              SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_students,
              ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
            FROM 
              attendance a
            JOIN 
              users u ON a.student_id = u.id
            JOIN
              subjects s ON a.subject_id = s.id
            JOIN
              classes c ON a.class_id = c.id
            WHERE 
              a.date BETWEEN ? AND ? 
              AND a.teacher_id = ?";

if ($semester > 0) {
  $dateQuery .= " AND u.semester = ?";
}

if (!empty($subject)) {
  $dateQuery .= " AND a.subject_id = ?";
}

if ($class_id > 0) {
  $dateQuery .= " AND a.class_id = ?";
}

$dateQuery .= " GROUP BY DATE(a.date), a.subject_id, a.class_id ORDER BY a.date DESC, s.name ASC";

$stmt = mysqli_prepare($conn, $dateQuery);
$dateData = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $dateData[] = $row;
      }
  }
}

// Get subject-wise attendance data
$subjectQuery = "SELECT 
                  s.id as subject_id,
                  s.name as subject_name,
                  s.code as subject_code,
                  COUNT(DISTINCT a.student_id) as total_students,
                  COUNT(a.id) as total_records,
                  SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                  ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
              FROM 
                  attendance a
              JOIN 
                  users u ON a.student_id = u.id
              JOIN
                  subjects s ON a.subject_id = s.id
              WHERE 
                  a.date BETWEEN ? AND ? 
                  AND a.teacher_id = ?";

if ($semester > 0) {
  $subjectQuery .= " AND u.semester = ?";
}

if ($class_id > 0) {
  $subjectQuery .= " AND a.class_id = ?";
}

$subjectQuery .= " GROUP BY s.id ORDER BY s.name ASC";

$stmt = mysqli_prepare($conn, $subjectQuery);
$subjectData = [];

if ($stmt) {
  // FIX: Use the correct parameter types and values based on the query
  $subjectParams = [$from_date, $to_date, $teacher_id];
  $subjectTypes = "ssi";
  
  if ($semester > 0) {
    $subjectParams[] = $semester;
    $subjectTypes .= "i";
  }
  
  if ($class_id > 0) {
    $subjectParams[] = $class_id;
    $subjectTypes .= "i";
  }
  
  mysqli_stmt_bind_param($stmt, $subjectTypes, ...$subjectParams);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          if (!empty($row['subject_name'])) {
              $subjectData[] = $row;
          }
      }
  }
}

// Get semester-wise attendance data
$semesterQuery = "SELECT 
                  u.semester,
                  COUNT(DISTINCT u.id) as total_students,
                  COUNT(a.id) as total_records,
                  SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                  ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
              $baseQuery
              $whereClause
              GROUP BY u.semester
              ORDER BY u.semester ASC";

$stmt = mysqli_prepare($conn, $semesterQuery);
$semesterData = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $semesterData[] = $row;
      }
  }
}

// Get students with low attendance (below 75%)
$lowAttendanceQuery = "SELECT 
                      u.id, 
                      u.name, 
                      u.roll_no, 
                      u.semester,
                      COUNT(a.id) as total_days,
                      SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
                      ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
                  $baseQuery
                  $whereClause
                  GROUP BY u.id 
                  HAVING attendance_percentage < 75 AND total_days > 0
                  ORDER BY attendance_percentage ASC, u.semester ASC, u.name ASC";

$stmt = mysqli_prepare($conn, $lowAttendanceQuery);
$lowAttendanceData = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $lowAttendanceData[] = $row;
      }
  }
}

// Calculate overall statistics
$totalStudents = count($summaryData);
$totalClasses = count($dateData);
$totalPresent = 0;
$totalAbsent = 0;
$overallPercentage = 0;

foreach ($summaryData as $data) {
  $totalPresent += $data['present_days'];
  $totalAbsent += ($data['total_days'] - $data['present_days']);
}

$totalAttendance = $totalPresent + $totalAbsent;
if ($totalAttendance > 0) {
  $overallPercentage = round(($totalPresent / $totalAttendance) * 100, 2);
}

// Export to CSV if requested
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.csv"');
  
  $output = fopen('php://output', 'w');
  
  // Add headers
  fputcsv($output, ['Student Name', 'Roll No', 'Semester', 'Total Days', 'Present Days', 'Absent Days', 'Attendance Percentage']);
  
  // Add data
  foreach ($summaryData as $data) {
      fputcsv($output, [
          $data['name'],
          $data['roll_no'],
          $data['semester'],
          $data['total_days'],
          $data['present_days'],
          $data['total_days'] - $data['present_days'],
          $data['attendance_percentage'] . '%'
      ]);
  }
  
  fclose($output);
  exit;
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
    <!-- Add Chart.js library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a class="nav-link" href="attendance_records.php">
                            <i class="fas fa-calendar-check me-1"></i>Records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="reports.php">
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
                  <h2><i class="fas fa-chart-bar me-2 text-danger"></i>Attendance Reports</h2>
                  <div class="no-print">
                      <button class="btn btn-outline-danger me-2" onclick="window.print()">
                          <i class="fas fa-print me-2"></i>Print Report
                      </button>
                      <a href="<?php echo $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=csv'; ?>" class="btn btn-outline-success">
                          <i class="fas fa-file-csv me-2"></i>Export CSV
                      </a>
                  </div>
              </div>
              
              <div class="card shadow-sm mb-4 no-print">
                  <div class="card-header bg-danger text-white">
                      <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Reports</h5>
                  </div>
                  <div class="card-body">
                      <form method="GET" class="row g-3">
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
                          <div class="col-md-3">
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
                          <div class="col-md-3">
                              <label for="subject" class="form-label">Subject</label>
                              <select class="form-select" id="subject" name="subject">
                                  <option value="">All Subjects</option>
                                  <?php foreach ($subjects as $sub): ?>
                                  <option value="<?php echo htmlspecialchars($sub['id']); ?>" <?php echo $subject == $sub['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($sub['code'] . ' - ' . $sub['name']); ?>
                                  </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="from_date" class="form-label">From Date</label>
                              <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo $from_date; ?>">
                          </div>
                          <div class="col-md-2">
                              <label for="to_date" class="form-label">To Date</label>
                              <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo $to_date; ?>">
                          </div>
                          <div class="col-md-2">
                              <label for="view_type" class="form-label">View Type</label>
                              <select class="form-select" id="view_type" name="view_type">
                                  <option value="summary" <?php echo $view_type == 'summary' ? 'selected' : ''; ?>>Summary</option>
                                  <option value="detailed" <?php echo $view_type == 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                              </select>
                          </div>
                          <div class="col-md-2 d-flex align-items-end">
                              <button type="submit" class="btn btn-danger w-100">
                                  <i class="fas fa-filter me-2"></i>Apply Filters
                              </button>
                          </div>
                      </form>
                  </div>
              </div>
              
              <!-- Report Header for Print -->
              <div class="d-none d-print-block mb-4">
                  <div class="text-center">
                      <h3>BCA Attendance System</h3>
                      <h4>Attendance Report</h4>
                      <p>
                          <?php if ($semester > 0): ?>Semester: <?php echo $semester; ?><br><?php endif; ?>
                          <?php if (!empty($subject)): ?>Subject: <?php echo htmlspecialchars($subject); ?><br><?php endif; ?>
                          <?php if ($class_id > 0): ?>
                              <?php foreach ($classes as $class): ?>
                                  <?php if ($class['id'] == $class_id): ?>
                                      Class: <?php echo htmlspecialchars($class['name']); ?><br>
                                  <?php endif; ?>
                              <?php endforeach; ?>
                          <?php endif; ?>
                          Period: <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>
                      </p>
                  </div>
                  <hr>
              </div>
              
              <!-- Statistics Cards -->
              <div class="row mb-4">
                  <div class="col-md-3">
                      <div class="card shadow-sm h-100 border-danger">
                          <div class="card-body text-center">
                              <h1 class="display-4 text-danger"><?php echo $totalStudents; ?></h1>
                              <p class="text-muted mb-0">Total Students</p>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="card shadow-sm h-100 border-danger">
                          <div class="card-body text-center">
                              <h1 class="display-4 text-danger"><?php echo $totalClasses; ?></h1>
                              <p class="text-muted mb-0">Total Classes</p>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="card shadow-sm h-100 border-danger">
                          <div class="card-body text-center">
                              <h1 class="display-4 text-danger"><?php echo count($lowAttendanceData); ?></h1>
                              <p class="text-muted mb-0">Low Attendance</p>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="card shadow-sm h-100 border-danger">
                          <div class="card-body text-center">
                              <h1 class="display-4 text-<?php echo $overallPercentage >= 75 ? 'success' : ($overallPercentage >= 50 ? 'warning' : 'danger'); ?>">
                                  <?php echo $overallPercentage; ?>%
                              </h1>
                              <p class="text-muted mb-0">Overall Attendance</p>
                          </div>
                      </div>
                  </div>
              </div>
              
              <!-- Tabs for different report views -->
              <ul class="nav nav-tabs mb-4 no-print" id="reportTabs" role="tablist">
                  <li class="nav-item" role="presentation">
                      <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                          <i class="fas fa-chart-pie me-2 text-dark"></i><span style="color: black;">Overview</span>
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab" aria-controls="students" aria-selected="false">
                          <i class="fas fa-user-graduate me-2 text-dark"></i><span style="color: black;">Students</span>
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="dates-tab" data-bs-toggle="tab" data-bs-target="#dates" type="button" role="tab" aria-controls="dates" aria-selected="false">
                          <i class="fas fa-calendar-alt me-2 text-dark"></i><span style="color: black;">Dates</span>
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="subjects-tab" data-bs-toggle="tab" data-bs-target="#subjects" type="button" role="tab" aria-controls="subjects" aria-selected="false">
                          <i class="fas fa-book me-2 text-dark" ></i><span style="color: black;">Subjects</span>
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="low-attendance-tab" data-bs-toggle="tab" data-bs-target="#low-attendance" type="button" role="tab" aria-controls="low-attendance" aria-selected="false">
                          <i class="fas fa-exclamation-triangle me-2 text-dark"></i><span style="color: black;">Low Attendance</span>
                      </button>
                  </li>
              </ul>
              
              <div class="tab-content" id="reportTabsContent">
                  <!-- Overview Tab -->
                  <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                      <div class="row mb-4">
                          <div class="col-md-6">
                              <div class="card shadow-sm h-100">
                                  <div class="card-header bg-danger text-white">
                                      <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Attendance Overview</h5>
                                  </div>
                                  <div class="card-body">
                                      <canvas id="attendanceOverviewChart" height="250"></canvas>
                                  </div>
                              </div>
                          </div>
                          
                          <div class="col-md-6">
                              <div class="card shadow-sm h-100">
                                  <div class="card-header bg-danger text-white">
                                      <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Semester-wise Attendance</h5>
                                  </div>
                                  <div class="card-body">
                                      <canvas id="semesterAttendanceChart" height="250"></canvas>
                                  </div>
                              </div>
                          </div>
                      </div>
                      
                      <div class="row mb-4">
                          <div class="col-md-12">
                              <div class="card shadow-sm">
                                  <div class="card-header bg-danger text-white">
                                      <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Daily Attendance Trend</h5>
                                  </div>
                                  <div class="card-body">
                                      <canvas id="dailyTrendChart" height="150"></canvas>
                                  </div>
                              </div>
                          </div>
                      </div>
                      
                      <div class="card shadow-sm mb-4">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>Semester-wise Attendance Summary</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($semesterData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Semester</th>
                                              <th>Total Students</th>
                                              <th>Total Records</th>
                                              <th>Present Count</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($semesterData as $data): ?>
                                          <tr>
                                              <td>Semester <?php echo $data['semester']; ?></td>
                                              <td><?php echo $data['total_students']; ?></td>
                                              <td><?php echo $data['total_records']; ?></td>
                                              <td><?php echo $data['present_count']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo $data['attendance_percentage'] >= 75 ? 'success' : ($data['attendance_percentage'] >= 50 ? 'warning' : 'danger'); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['attendance_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['attendance_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['attendance_percentage']; ?>%
                                                      </div>
                                                  </div>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-chart-bar fa-5x text-muted"></i>
                                  <h5 class="mt-3">No data available for the selected filters.</h5>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Students Tab -->
                  <div class="tab-pane fade" id="students" role="tabpanel" aria-labelledby="students-tab">
                      <div class="card shadow-sm mb-4">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Student Attendance Summary</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($summaryData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Name</th>
                                              <th>Roll No</th>
                                              <th>Semester</th>
                                              <th>Total Days</th>
                                              <th>Present Days</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($summaryData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['name']); ?></td>
                                              <td><?php echo htmlspecialchars($data['roll_no']); ?></td>
                                              <td><?php echo htmlspecialchars($data['semester']); ?></td>
                                              <td><?php echo $data['total_days']; ?></td>
                                              <td><?php echo $data['present_days']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo $data['attendance_percentage'] >= 75 ? 'success' : ($data['attendance_percentage'] >= 50 ? 'warning' : 'danger'); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['attendance_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['attendance_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['attendance_percentage']; ?>%
                                                      </div>
                                                  </div>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-user-graduate fa-5x text-muted"></i>
                                  <h5 class="mt-3">No student attendance data available.</h5>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Dates Tab -->
                  <div class="tab-pane fade" id="dates" role="tabpanel" aria-labelledby="dates-tab">
                      <div class="card shadow-sm mb-4">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Date-wise Attendance</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($dateData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Date</th>
                                              <th>Subject</th>
                                              <th>Class</th>
                                              <th>Total Students</th>
                                              <th>Present Students</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($dateData as $data): ?>
                                          <tr>
                                              <td><?php echo date('d M Y', strtotime($data['attendance_date'])); ?></td>
                                              <td><?php echo htmlspecialchars($data['subject_name']); ?></td>
                                              <td><?php echo htmlspecialchars($data['class_name']); ?></td>
                                              <td><?php echo $data['total_students']; ?></td>
                                              <td><?php echo $data['present_students']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo $data['attendance_percentage'] >= 75 ? 'success' : ($data['attendance_percentage'] >= 50 ? 'warning' : 'danger'); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['attendance_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['attendance_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['attendance_percentage']; ?>%
                                                      </div>
                                                  </div>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-calendar-alt fa-5x text-muted"></i>
                                  <h5 class="mt-3">No date-wise attendance data available.</h5>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Subjects Tab -->
                  <div class="tab-pane fade" id="subjects" role="tabpanel" aria-labelledby="subjects-tab">
                      <div class="card shadow-sm mb-4">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-book me-2"></i>Subject-wise Attendance</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($subjectData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Subject</th>
                                              <th>Total Students</th>
                                              <th>Total Records</th>
                                              <th>Present Count</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($subjectData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['subject_name']); ?></td>
                                              <td><?php echo $data['total_students']; ?></td>
                                              <td><?php echo $data['total_records']; ?></td>
                                              <td><?php echo $data['present_count']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo $data['attendance_percentage'] >= 75 ? 'success' : ($data['attendance_percentage'] >= 50 ? 'warning' : 'danger'); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['attendance_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['attendance_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['attendance_percentage']; ?>%
                                                      </div>
                                                  </div>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-book fa-5x text-muted"></i>
                                  <h5 class="mt-3">No subject-wise attendance data available.</h5>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Low Attendance Tab -->
                  <div class="tab-pane fade" id="low-attendance" role="tabpanel" aria-labelledby="low-attendance-tab">
                      <div class="card shadow-sm mb-4">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Students with Low Attendance</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($lowAttendanceData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Name</th>
                                              <th>Roll No</th>
                                              <th>Semester</th>
                                              <th>Total Days</th>
                                              <th>Present Days</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($lowAttendanceData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['name']); ?></td>
                                              <td><?php echo htmlspecialchars($data['roll_no']); ?></td>
                                              <td><?php echo htmlspecialchars($data['semester']); ?></td>
                                              <td><?php echo $data['total_days']; ?></td>
                                              <td><?php echo $data['present_days']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-danger" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['attendance_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['attendance_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['attendance_percentage']; ?>%
                                                      </div>
                                                  </div>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-exclamation-triangle fa-5x text-muted"></i>
                                  <h5 class="mt-3">No students with low attendance.</h5>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Attendance Overview Chart
      const attendanceOverviewChart = document.getElementById('attendanceOverviewChart').getContext('2d');
      
      new Chart(attendanceOverviewChart, {
          type: 'pie',
          data: {
              labels: ['Present', 'Absent'],
              datasets: [{
                  data: [<?php echo $totalPresent; ?>, <?php echo $totalAbsent; ?>],
                  backgroundColor: ['#28a745', '#dc3545'],
                  borderWidth: 1
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                  legend: {
                      position: 'bottom',
                      labels: {
                          font: {
                              size: 14
                          }
                      }
                  },
                  tooltip: {
                      callbacks: {
                          label: function(context) {
                              const total = context.dataset.data.reduce((a, b) => a + b, 0);
                              const value = context.raw;
                              const percentage = Math.round((value / total) * 100);
                              return `${context.label}: ${value} (${percentage}%)`;
                          }
                      }
                  }
              }
          }
      });
      
      // Semester Attendance Chart
      const semesterAttendanceChart = document.getElementById('semesterAttendanceChart').getContext('2d');
      
      const semesterLabels = [];
      const semesterPercentages = [];
      
      <?php foreach ($semesterData as $data): ?>
      semesterLabels.push('Semester <?php echo $data['semester']; ?>');
      semesterPercentages.push(<?php echo $data['attendance_percentage']; ?>);
      <?php endforeach; ?>
      
      new Chart(semesterAttendanceChart, {
          type: 'bar',
          data: {
              labels: semesterLabels,
              datasets: [{
                  label: 'Attendance Percentage',
                  data: semesterPercentages,
                  backgroundColor: '#dc3545',
                  borderWidth: 1
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
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
              },
              plugins: {
                  tooltip: {
                      callbacks: {
                          label: function(context) {
                              return `Attendance: ${context.raw}%`;
                          }
                      }
                  }
              }
          }
      });
      
      // Daily Trend Chart
      const dailyTrendChart = document.getElementById('dailyTrendChart').getContext('2d');
      
      const dates = [];
      const percentages = [];
      
      <?php 
      // Sort date data by date ascending
      usort($dateData, function($a, $b) {
          return strtotime($a['attendance_date']) - strtotime($b['attendance_date']);
      });
      
      foreach (array_slice($dateData, 0, 15) as $data): 
      ?>
      dates.push('<?php echo date('d M', strtotime($data['attendance_date'])); ?>');
      percentages.push(<?php echo $data['attendance_percentage']; ?>);
      <?php endforeach; ?>
      
      new Chart(dailyTrendChart, {
          type: 'line',
          data: {
              labels: dates,
              datasets: [{
                  label: 'Attendance Percentage',
                  data: percentages,
                  borderColor: '#007bff',
                  backgroundColor: 'rgba(0, 123, 255, 0.1)',
                  borderWidth: 2,
                  fill: true,
                  tension: 0.4,
                  pointBackgroundColor: '#007bff',
                  pointBorderColor: '#fff',
                  pointRadius: 5,
                  pointHoverRadius: 7
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
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
              },
              plugins: {
                  tooltip: {
                      callbacks: {
                          label: function(context) {
                              return `Attendance: ${context.raw}%`;
                          }
                      }
                  }
              }
          }
      });
    });
  </script>
</body>
</html>