<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get filters
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
$subject = isset($_GET['subject']) ? $_GET['subject'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01'); // First day of current month
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d'); // Current date
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'summary';

// Get all semesters for filter
$semestersQuery = "SELECT DISTINCT semester FROM users WHERE role = 'student' ORDER BY semester ASC";
$semestersResult = mysqli_query($conn, $semestersQuery);
$semesters = [];

if ($semestersResult) {
  while ($row = mysqli_fetch_assoc($semestersResult)) {
      $semesters[] = $row['semester'];
  }
}

// Get all teachers for filter
$teachersQuery = "SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name ASC";
$teachersResult = mysqli_query($conn, $teachersQuery);
$teachers = [];

if ($teachersResult) {
  while ($row = mysqli_fetch_assoc($teachersResult)) {
      $teachers[] = $row;
  }
}

// Get all subjects for filter
$subjectsQuery = "SELECT DISTINCT subject FROM attendance WHERE subject IS NOT NULL AND subject != '' ORDER BY subject ASC";
$subjectsResult = mysqli_query($conn, $subjectsQuery);
$subjects = [];

if ($subjectsResult) {
  while ($row = mysqli_fetch_assoc($subjectsResult)) {
      if (!empty($row['subject'])) {
          $subjects[] = $row['subject'];
      }
  }
}

// Debug query execution
function debugQuery($query, $params = [], $types = "") {
    global $conn;
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return "Query preparation failed: " . mysqli_error($conn);
    }
    
    if (!empty($params) && !empty($types)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        return "Query execution failed: " . mysqli_stmt_error($stmt);
    }
    
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        return "Result fetching failed: " . mysqli_stmt_error($stmt);
    }
    
    return "Query executed successfully";
}

// Base query for attendance data
$baseQuery = "FROM 
              users u
            LEFT JOIN 
              attendance a ON u.id = a.student_id 
              AND a.date BETWEEN ? AND ?";

// Add filters
$whereClause = " WHERE u.role = 'student'";
$params = [$from_date, $to_date];
$types = "ss";

if ($semester > 0) {
  $whereClause .= " AND u.semester = ?";
  $params[] = $semester;
  $types .= "i";
}

if ($teacher_id > 0) {
  $whereClause .= " AND (a.teacher_id = ? OR a.teacher_id IS NULL)";
  $params[] = $teacher_id;
  $types .= "i";
}

if (!empty($subject)) {
  $whereClause .= " AND (a.subject = ? OR a.subject IS NULL)";
  $params[] = $subject;
  $types .= "s";
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

// Debug the query
// echo "<!-- Summary Query: " . $summaryQuery . " -->";
// echo "<!-- Params: " . implode(", ", $params) . " -->";
// echo "<!-- Types: " . $types . " -->";

$stmt = mysqli_prepare($conn, $summaryQuery);
$summaryData = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  if (mysqli_stmt_execute($stmt)) {
      $result = mysqli_stmt_get_result($stmt);
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $summaryData[] = $row;
          }
      } else {
          // echo "<!-- Result error: " . mysqli_stmt_error($stmt) . " -->";
      }
  } else {
      // echo "<!-- Execution error: " . mysqli_stmt_error($stmt) . " -->";
  }
} else {
  // echo "<!-- Prepare error: " . mysqli_error($conn) . " -->";
}

// Get date-wise attendance data
$dateQuery = "SELECT 
              DATE(a.date) as attendance_date,
              a.subject,
              COUNT(a.id) as total_students,
              SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_students,
              ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
            FROM 
              attendance a
            JOIN 
              users u ON a.student_id = u.id
            WHERE 
              a.date BETWEEN ? AND ?";

if ($semester > 0) {
  $dateQuery .= " AND u.semester = ?";
}

if ($teacher_id > 0) {
  $dateQuery .= " AND a.teacher_id = ?";
}

if (!empty($subject)) {
  $dateQuery .= " AND a.subject = ?";
}

$dateQuery .= " GROUP BY DATE(a.date), a.subject ORDER BY a.date DESC, a.subject ASC";

$stmt = mysqli_prepare($conn, $dateQuery);
$dateData = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, $types, ...$params);
  if (mysqli_stmt_execute($stmt)) {
      $result = mysqli_stmt_get_result($stmt);
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $dateData[] = $row;
          }
      }
  }
}

// Get teacher-wise attendance data
$teacherQuery = "SELECT 
                  t.id,
                  t.name as teacher_name,
                  COUNT(DISTINCT a.student_id) as students_marked,
                  COUNT(a.id) as total_records,
                  SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                  ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as present_percentage
              FROM 
                  users t
              LEFT JOIN 
                  attendance a ON t.id = a.teacher_id AND a.date BETWEEN ? AND ?
              WHERE 
                  t.role = 'teacher'";

if ($semester > 0) {
  $teacherQuery .= " AND EXISTS (SELECT 1 FROM attendance a2 JOIN users u ON a2.student_id = u.id WHERE a2.teacher_id = t.id AND u.semester = ?)";
}

if (!empty($subject)) {
  $teacherQuery .= " AND (a.subject = ? OR a.subject IS NULL)";
}

$teacherQuery .= " GROUP BY t.id ORDER BY total_records DESC";

$stmt = mysqli_prepare($conn, $teacherQuery);
$teacherData = [];

if ($stmt) {
  if ($semester > 0 && !empty($subject)) {
      mysqli_stmt_bind_param($stmt, "ssis", $from_date, $to_date, $semester, $subject);
  } else if ($semester > 0) {
      mysqli_stmt_bind_param($stmt, "ssi", $from_date, $to_date, $semester);
  } else if (!empty($subject)) {
      mysqli_stmt_bind_param($stmt, "sss", $from_date, $to_date, $subject);
  } else {
      mysqli_stmt_bind_param($stmt, "ss", $from_date, $to_date);
  }
  
  if (mysqli_stmt_execute($stmt)) {
      $result = mysqli_stmt_get_result($stmt);
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $teacherData[] = $row;
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
  if (mysqli_stmt_execute($stmt)) {
      $result = mysqli_stmt_get_result($stmt);
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $semesterData[] = $row;
          }
      }
  }
}

// Get subject-wise attendance data
$subjectQuery = "SELECT 
                  a.subject,
                  COUNT(DISTINCT a.student_id) as total_students,
                  COUNT(a.id) as total_records,
                  SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                  ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
              FROM 
                  attendance a
              JOIN 
                  users u ON a.student_id = u.id
              WHERE 
                  a.date BETWEEN ? AND ?
                  AND a.subject IS NOT NULL 
                  AND a.subject != ''";

if ($semester > 0) {
  $subjectQuery .= " AND u.semester = ?";
}

if ($teacher_id > 0) {
  $subjectQuery .= " AND a.teacher_id = ?";
}

$subjectQuery .= " GROUP BY a.subject ORDER BY attendance_percentage DESC";

$stmt = mysqli_prepare($conn, $subjectQuery);
$subjectData = [];

if ($stmt) {
  if ($semester > 0 && $teacher_id > 0) {
      mysqli_stmt_bind_param($stmt, "ssii", $from_date, $to_date, $semester, $teacher_id);
  } else if ($teacher_id > 0) {
      mysqli_stmt_bind_param($stmt, "ssi", $from_date, $to_date, $teacher_id);
  } else if ($semester > 0) {
      mysqli_stmt_bind_param($stmt, "ssi", $from_date, $to_date, $semester);
  } else {
      mysqli_stmt_bind_param($stmt, "ss", $from_date, $to_date);
  }
  
  if (mysqli_stmt_execute($stmt)) {
      $result = mysqli_stmt_get_result($stmt);
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              if (!empty($row['subject'])) {
                  $subjectData[] = $row;
              }
          }
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
  if (mysqli_stmt_execute($stmt)) {
      $result = mysqli_stmt_get_result($stmt);
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $lowAttendanceData[] = $row;
          }
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

// Helper function to get CSS class based on percentage
function getPercentageClass($percentage) {
    if ($percentage >= 75) return 'success';
    if ($percentage >= 50) return 'warning';
    return 'danger';
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
  <title>Attendance Reports - BCA Attendance System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
  <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
  <link rel="stylesheet" href="../assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
      @media print {
          .no-print {
              display: none !important;
          }
          .card {
              break-inside: avoid;
          }
          .sidebar {
              display: none !important;
          }
          .container-fluid {
              width: 100% !important;
              padding: 0 !important;
          }
          .col-md-9, .col-lg-10 {
              width: 100% !important;
              flex: 0 0 100% !important;
              max-width: 100% !important;
              margin-left: 0 !important;
          }
      }
      .nav-tabs .nav-link.active {
          background-color: #dc3545;
          color: white;
          border-color: #dc3545;
      }
      .nav-tabs .nav-link {
          color: #dc3545;
      }
      .dashboard-stat {
          border-radius: 10px;
          padding: 20px;
          margin-bottom: 20px;
          box-shadow: 0 4px 6px rgba(0,0,0,0.1);
          transition: all 0.3s ease;
      }
      .dashboard-stat:hover {
          transform: translateY(-5px);
          box-shadow: 0 8px 15px rgba(0,0,0,0.1);
      }
      .stat-icon {
          font-size: 2.5rem;
          margin-bottom: 15px;
      }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-dark no-print">
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
                      <a class="nav-link active" href="reports.php">
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
                        </li>
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
                  <h2><i class="fas fa-chart-bar me-2"></i>Attendance Reports</h2>
                  <div class="no-print">
                      <button class="btn btn-outline-secondary me-2" onclick="window.print()">
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
                          <div class="col-md-2">
                              <label for="teacher_id" class="form-label">Teacher</label>
                              <select class="form-select" id="teacher_id" name="teacher_id">
                                  <option value="0">All Teachers</option>
                                  <?php foreach ($teachers as $teacher): ?>
                                  <option value="<?php echo $teacher['id']; ?>" <?php echo $teacher_id == $teacher['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($teacher['name']); ?>
                                  </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="col-md-2">
                              <label for="subject" class="form-label">Subject</label>
                              <select class="form-select" id="subject" name="subject">
                                  <option value="">All Subjects</option>
                                  <?php foreach ($subjects as $sub): ?>
                                  <option value="<?php echo htmlspecialchars($sub); ?>" <?php echo $subject == $sub ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($sub); ?>
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
                          <div class="col-md-1">
                              <label for="view_type" class="form-label">View</label>
                              <select class="form-select" id="view_type" name="view_type">
                                  <option value="summary" <?php echo $view_type == 'summary' ? 'selected' : ''; ?>>Summary</option>
                                  <option value="detailed" <?php echo $view_type == 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                              </select>
                          </div>
                          <div class="col-md-1 d-flex align-items-end">
                              <button type="submit" class="btn btn-danger w-100">
                                  <i class="fas fa-filter"></i>
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
                          <?php if ($teacher_id > 0): ?>
                              Teacher: <?php 
                                  foreach ($teachers as $t) {
                                      if ($t['id'] == $teacher_id) {
                                          echo htmlspecialchars($t['name']);
                                          break;
                                      }
                                  }
                              ?><br>
                          <?php endif; ?>
                          <?php if (!empty($subject)): ?>Subject: <?php echo htmlspecialchars($subject); ?><br><?php endif; ?>
                          Period: <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>
                      </p>
                  </div>
                  <hr>
              </div>
              
              <!-- Statistics Cards -->
              <div class="row mb-4">
                  <div class="col-md-3">
                      <div class="dashboard-stat bg-light">
                          <div class="text-center">
                              <div class="stat-icon text-danger">
                                  <i class="fas fa-user-graduate"></i>
                              </div>
                              <h2 class="display-4 text-danger"><?php echo $totalStudents; ?></h2>
                              <p class="text-muted mb-0">Total Students</p>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="dashboard-stat bg-light">
                          <div class="text-center">
                              <div class="stat-icon text-danger">
                                  <i class="fas fa-calendar-day"></i>
                              </div>
                              <h2 class="display-4 text-danger"><?php echo $totalClasses; ?></h2>
                              <p class="text-muted mb-0">Total Classes</p>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="dashboard-stat bg-light">
                          <div class="text-center">
                              <div class="stat-icon text-danger">
                                  <i class="fas fa-exclamation-triangle"></i>
                              </div>
                              <h2 class="display-4 text-danger"><?php echo count($lowAttendanceData); ?></h2>
                              <p class="text-muted mb-0">Low Attendance</p>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="dashboard-stat bg-light">
                          <div class="text-center">
                              <div class="stat-icon text-<?php echo getPercentageClass($overallPercentage); ?>">
                                  <i class="fas fa-chart-pie"></i>
                              </div>
                              <h2 class="display-4 text-<?php echo getPercentageClass($overallPercentage); ?>">
                                  <?php echo $overallPercentage; ?>%
                              </h2>
                              <p class="text-muted mb-0">Overall Attendance</p>
                          </div>
                      </div>
                  </div>
              </div>
              
              <!-- Tabs for different report views -->
              <ul class="nav nav-tabs mb-4 no-print" id="reportTabs" role="tablist">
                  <li class="nav-item" role="presentation">
                      <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                          <i class="fas fa-chart-pie me-2"></i>Overview
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab" aria-controls="students" aria-selected="false">
                          <i class="fas fa-user-graduate me-2"></i>Students
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="teachers-tab" data-bs-toggle="tab" data-bs-target="#teachers" type="button" role="tab" aria-controls="teachers" aria-selected="false">
                          <i class="fas fa-chalkboard-teacher me-2"></i>Teachers
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="dates-tab" data-bs-toggle="tab" data-bs-target="#dates" type="button" role="tab" aria-controls="dates" aria-selected="false">
                          <i class="fas fa-calendar-alt me-2"></i>Dates
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="subjects-tab" data-bs-toggle="tab" data-bs-target="#subjects" type="button" role="tab" aria-controls="subjects" aria-selected="false">
                          <i class="fas fa-book me-2"></i>Subjects
                      </button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link" id="low-attendance-tab" data-bs-toggle="tab" data-bs-target="#low-attendance" type="button" role="tab" aria-controls="low-attendance" aria-selected="false">
                          <i class="fas fa-exclamation-triangle me-2"></i>Low Attendance
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
                                      <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Overall Attendance</h5>
                                  </div>
                                  <div class="card-body">
                                      <canvas id="overallAttendanceChart" height="250"></canvas>
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
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['attendance_percentage']); ?>" 
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
                                  <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                                  <h5>No Data Available</h5>
                                  <p class="text-muted">No attendance data available for the selected filters.</p>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                      
                      <?php if (!empty($teacherData)): ?>
                      <div class="card shadow-sm mb-4">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Teacher-wise Attendance Summary</h5>
                          </div>
                          <div class="card-body">
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Teacher Name</th>
                                              <th>Students Marked</th>
                                              <th>Total Records</th>
                                              <th>Present Count</th>
                                              <th>Present Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($teacherData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['teacher_name']); ?></td>
                                              <td><?php echo $data['students_marked']; ?></td>
                                              <td><?php echo $data['total_records']; ?></td>
                                              <td><?php echo $data['present_count']; ?></td>
                                              <td>
                                                  <?php if ($data['total_records'] > 0): ?>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['present_percentage']); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['present_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['present_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['present_percentage']; ?>%
                                                      </div>
                                                  </div>
                                                  <?php else: ?>
                                                  <span class="text-muted">No data</span>
                                                  <?php endif; ?>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                          </div>
                      </div>
                      <?php endif; ?>
                      
                      <?php if (!empty($subjectData)): ?>
                      <div class="card shadow-sm">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-book me-2"></i>Subject-wise Attendance Summary</h5>
                          </div>
                          <div class="card-body">
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
                                              <td><?php echo htmlspecialchars($data['subject']); ?></td>
                                              <td><?php echo $data['total_students']; ?></td>
                                              <td><?php echo $data['total_records']; ?></td>
                                              <td><?php echo $data['present_count']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['attendance_percentage']); ?>" 
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
                          </div>
                      </div>
                      <?php endif; ?>
                  </div>
                  
                  <!-- Students Tab -->
                  <div class="tab-pane fade" id="students" role="tabpanel" aria-labelledby="students-tab">
                      <div class="card shadow-sm">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Student-wise Attendance</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($summaryData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Student Name</th>
                                              <th>Roll No</th>
                                              <th>Semester</th>
                                              <th>Total Days</th>
                                              <th>Present Days</th>
                                              <th>Absent Days</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($summaryData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['name']); ?></td>
                                              <td><?php echo htmlspecialchars($data['roll_no']); ?></td>
                                              <td><?php echo $data['semester']; ?></td>
                                              <td><?php echo $data['total_days']; ?></td>
                                              <td><?php echo $data['present_days']; ?></td>
                                              <td><?php echo $data['total_days'] - $data['present_days']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['attendance_percentage']); ?>" 
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
                                  <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
                                  <h5>No Data Available</h5>
                                  <p class="text-muted">No attendance data available for the selected filters.</p>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Teachers Tab -->
                  <div class="tab-pane fade" id="teachers" role="tabpanel" aria-labelledby="teachers-tab">
                      <div class="card shadow-sm">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Teacher-wise Attendance</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($teacherData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Teacher Name</th>
                                              <th>Students Marked</th>
                                              <th>Total Records</th>
                                              <th>Present Count</th>
                                              <th>Present Percentage</th>
                                              <th>Action</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($teacherData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['teacher_name']); ?></td>
                                              <td><?php echo $data['students_marked']; ?></td>
                                              <td><?php echo $data['total_records']; ?></td>
                                              <td><?php echo $data['present_count']; ?></td>
                                              <td>
                                                  <?php if ($data['total_records'] > 0): ?>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['present_percentage']); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['present_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['present_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['present_percentage']; ?>%
                                                      </div>
                                                  </div>
                                                  <?php else: ?>
                                                  <span class="text-muted">No data</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td>
                                                  <a href="reports.php?teacher_id=<?php echo $data['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn btn-sm btn-outline-danger">
                                                      <i class="fas fa-filter me-1"></i>Filter
                                                  </a>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-chalkboard-teacher fa-4x text-muted mb-3"></i>
                                  <h5>No Data Available</h5>
                                  <p class="text-muted">No teacher data available for the selected filters.</p>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Dates Tab -->
                  <div class="tab-pane fade" id="dates" role="tabpanel" aria-labelledby="dates-tab">
                      <div class="card shadow-sm">
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
                                              <th>Total Students</th>
                                              <th>Present Students</th>
                                              <th>Absent Students</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($dateData as $data): ?>
                                          <tr>
                                              <td><?php echo date('d M Y', strtotime($data['attendance_date'])); ?></td>
                                              <td><?php echo htmlspecialchars($data['subject'] ?? 'N/A'); ?></td>
                                              <td><?php echo $data['total_students']; ?></td>
                                              <td><?php echo $data['present_students']; ?></td>
                                              <td><?php echo $data['total_students'] - $data['present_students']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['attendance_percentage']); ?>" 
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
                                  <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                                  <h5>No Data Available</h5>
                                  <p class="text-muted">No attendance data available for the selected filters.</p>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Subjects Tab -->
                  <div class="tab-pane fade" id="subjects" role="tabpanel" aria-labelledby="subjects-tab">
                      <div class="card shadow-sm">
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
                                              <th>Action</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($subjectData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['subject']); ?></td>
                                              <td><?php echo $data['total_students']; ?></td>
                                              <td><?php echo $data['total_records']; ?></td>
                                              <td><?php echo $data['present_count']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['attendance_percentage']); ?>" 
                                                           role="progressbar" 
                                                           style="width: <?php echo $data['attendance_percentage']; ?>%;" 
                                                           aria-valuenow="<?php echo $data['attendance_percentage']; ?>" 
                                                           aria-valuemin="0" 
                                                           aria-valuemax="100">
                                                          <?php echo $data['attendance_percentage']; ?>%
                                                      </div>
                                                  </div>
                                              </td>
                                              <td>
                                                  <a href="reports.php?subject=<?php echo urlencode($data['subject']); ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn btn-sm btn-outline-danger">
                                                      <i class="fas fa-filter me-1"></i>Filter
                                                  </a>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                      </tbody>
                                  </table>
                              </div>
                              <?php else: ?>
                              <div class="text-center py-5">
                                  <i class="fas fa-book fa-4x text-muted mb-3"></i>
                                  <h5>No Data Available</h5>
                                  <p class="text-muted">No subject data available for the selected filters.</p>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  
                  <!-- Low Attendance Tab -->
                  <div class="tab-pane fade" id="low-attendance" role="tabpanel" aria-labelledby="low-attendance-tab">
                      <div class="card shadow-sm">
                          <div class="card-header bg-danger text-white">
                              <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Students with Low Attendance (Below 75%)</h5>
                          </div>
                          <div class="card-body">
                              <?php if (!empty($lowAttendanceData)): ?>
                              <div class="table-responsive">
                                  <table class="table table-hover">
                                      <thead>
                                          <tr>
                                              <th>Student Name</th>
                                              <th>Roll No</th>
                                              <th>Semester</th>
                                              <th>Total Days</th>
                                              <th>Present Days</th>
                                              <th>Absent Days</th>
                                              <th>Attendance Percentage</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($lowAttendanceData as $data): ?>
                                          <tr>
                                              <td><?php echo htmlspecialchars($data['name']); ?></td>
                                              <td><?php echo htmlspecialchars($data['roll_no']); ?></td>
                                              <td><?php echo $data['semester']; ?></td>
                                              <td><?php echo $data['total_days']; ?></td>
                                              <td><?php echo $data['present_days']; ?></td>
                                              <td><?php echo $data['total_days'] - $data['present_days']; ?></td>
                                              <td>
                                                  <div class="progress" style="height: 20px;">
                                                      <div class="progress-bar bg-<?php echo getPercentageClass($data['attendance_percentage']); ?>" 
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
                                  <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                  <h5>Good News!</h5>
                                  <p class="text-muted">No students with attendance below 75% for the selected filters.</p>
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
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
  <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
  <script src="../assets/js/main.js"></script>
  <script>
      // Prepare data for charts
      document.addEventListener('DOMContentLoaded', function() {
          // Overall Attendance Chart
          const overallCtx = document.getElementById('overallAttendanceChart').getContext('2d');
          
          <?php
          // Calculate total present and absent
          $totalPresent = 0;
          $totalAbsent = 0;
          
          foreach ($summaryData as $data) {
              $totalPresent += $data['present_days'];
              $totalAbsent += ($data['total_days'] - $data['present_days']);
          }
          ?>
          
          const overallChart = new Chart(overallCtx, {
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
                          position: 'bottom'
                      }
                  }
              }
          });
          
          // Semester Attendance Chart
          const semesterCtx = document.getElementById('semesterAttendanceChart').getContext('2d');
          
          const semesterLabels = [];
          const semesterPercentages = [];
          
          <?php foreach ($semesterData as $data): ?>
          semesterLabels.push('Semester <?php echo $data['semester']; ?>');
          semesterPercentages.push(<?php echo $data['attendance_percentage']; ?>);
          <?php endforeach; ?>
          
          const semesterChart = new Chart(semesterCtx, {
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
                  }
              }
          });
          
          // Daily Trend Chart
          const trendCtx = document.getElementById('dailyTrendChart').getContext('2d');
          
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
          
          const trendChart = new Chart(trendCtx, {
              type: 'line',
              data: {
                  labels: dates,
                  datasets: [{
                      label: 'Attendance Percentage',
                      data: percentages,
                      borderColor: '#dc3545',
                      backgroundColor: 'rgba(220, 53, 69, 0.1)',
                      borderWidth: 2,
                      fill: true,
                      tension: 0.4
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
                  }
              }
          });
      });
      
      // Form validation
      document.addEventListener('DOMContentLoaded', function() {
          const form = document.querySelector('form');
          const fromDateInput = document.getElementById('from_date');
          const toDateInput = document.getElementById('to_date');
          
          form.addEventListener('submit', function(e) {
              const fromDate = new Date(fromDateInput.value);
              const toDate = new Date(toDateInput.value);
              
              if (fromDate > toDate) {
                  e.preventDefault();
                  alertify.error('From date cannot be greater than To date');
              }
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

