<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has teacher role
requireRole('teacher');

$teacher_id = $_SESSION['user_id'];

// Get classes taught by this teacher
$classesQuery = "SELECT c.id, c.name, s.id as semester_id, s.name as semester_name
                FROM classes c
                JOIN teacher_classes tc ON c.id = tc.class_id
                JOIN semesters s ON c.semester_id = s.id
                WHERE tc.teacher_id = ?
                ORDER BY s.id, c.name";
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

// Handle request approval/rejection
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action == 'approve' || $action == 'reject') {
        $status = ($action == 'approve') ? 'approved' : 'rejected';
        
        // Update request status
        $updateQuery = "UPDATE attendance_requests SET status = ?, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $updateQuery);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $status, $request_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // If approved, also mark attendance as present
                if ($status == 'approved') {
                    // Get request details
                    $requestQuery = "SELECT * FROM attendance_requests WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $requestQuery);
                    mysqli_stmt_bind_param($stmt, "i", $request_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($row = mysqli_fetch_assoc($result)) {
                        $student_id = $row['student_id'];
                        $date = $row['date'];
                        $class_id = $row['class_id'];
                        $subject_id = $row['subject_id'];
                        
                        // Check if attendance record already exists
                        $checkQuery = "SELECT id FROM attendance 
                                       WHERE student_id = ? AND date = ? AND class_id = ? AND subject_id = ?";
                        $stmt = mysqli_prepare($conn, $checkQuery);
                        mysqli_stmt_bind_param($stmt, "isii", $student_id, $date, $class_id, $subject_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        
                        if (mysqli_num_rows($result) > 0) {
                            // Update existing record
                            $attendance_id = mysqli_fetch_assoc($result)['id'];
                            $updateAttendanceQuery = "UPDATE attendance SET status = 'present', teacher_id = ?, marked_at = NOW() 
                                                      WHERE id = ?";
                            $stmt = mysqli_prepare($conn, $updateAttendanceQuery);
                            mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $attendance_id);
                            mysqli_stmt_execute($stmt);
                        } else {
                            // Insert new attendance record
                            $insertAttendanceQuery = "INSERT INTO attendance 
                                                      (student_id, date, status, teacher_id, class_id, subject_id, marked_at) 
                                                      VALUES (?, ?, 'present', ?, ?, ?, NOW())";
                            $stmt = mysqli_prepare($conn, $insertAttendanceQuery);
                            mysqli_stmt_bind_param($stmt, "isiii", $student_id, $date, $teacher_id, $class_id, $subject_id);
                            mysqli_stmt_execute($stmt);
                        }
                    }
                } elseif ($status == 'rejected') {
                    // If rejected, mark attendance as absent
                    $requestQuery = "SELECT * FROM attendance_requests WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $requestQuery);
                    mysqli_stmt_bind_param($stmt, "i", $request_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($row = mysqli_fetch_assoc($result)) {
                        $student_id = $row['student_id'];
                        $date = $row['date'];
                        $class_id = $row['class_id'];
                        $subject_id = $row['subject_id'];
                        
                        // Check if attendance record already exists
                        $checkQuery = "SELECT id FROM attendance 
                                       WHERE student_id = ? AND date = ? AND class_id = ? AND subject_id = ?";
                        $stmt = mysqli_prepare($conn, $checkQuery);
                        mysqli_stmt_bind_param($stmt, "isii", $student_id, $date, $class_id, $subject_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        
                        if (mysqli_num_rows($result) > 0) {
                            // Update existing record to absent
                            $attendance_id = mysqli_fetch_assoc($result)['id'];
                            $updateAttendanceQuery = "UPDATE attendance SET status = 'absent', teacher_id = ?, marked_at = NOW() 
                                                      WHERE id = ?";
                            $stmt = mysqli_prepare($conn, $updateAttendanceQuery);
                            mysqli_stmt_bind_param($stmt, "ii", $teacher_id, $attendance_id);
                            mysqli_stmt_execute($stmt);
                        } else {
                            // Insert new attendance record as absent
                            $insertAttendanceQuery = "INSERT INTO attendance 
                                                      (student_id, date, status, teacher_id, class_id, subject_id, marked_at) 
                                                      VALUES (?, ?, 'absent', ?, ?, ?, NOW())";
                            $stmt = mysqli_prepare($conn, $insertAttendanceQuery);
                            mysqli_stmt_bind_param($stmt, "isiii", $student_id, $date, $teacher_id, $class_id, $subject_id);
                            mysqli_stmt_execute($stmt);
                        }
                    }
                }
            
        
    

              setMessage(ucfirst($action) . 'd attendance request successfully', 'success');
          } else {
              setMessage('Error updating request: ' . mysqli_error($conn), 'error');
          }
      } else {
          setMessage('Error preparing statement: ' . mysqli_error($conn), 'error');
      }
  }
  
  header('Location: attendance_requests.php');
  exit;
}

// Filter by status
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';

// Get pending requests for students in teacher's classes
$requestsQuery = "SELECT ar.*, u.name, u.roll_no, u.semester, 
               c.name as class_name, s.name as subject_name, s.code as subject_code
               FROM attendance_requests ar 
               JOIN users u ON ar.student_id = u.id 
               JOIN classes c ON ar.class_id = c.id
               JOIN subjects s ON ar.subject_id = s.id
               JOIN teacher_classes tc ON (ar.class_id = tc.class_id AND ar.subject_id = tc.subject_id)
               WHERE tc.teacher_id = ? ";

if ($status_filter != 'all') {
  $requestsQuery .= "AND ar.status = '$status_filter' ";
}

$requestsQuery .= "ORDER BY ar.date DESC, ar.created_at DESC";

$stmt = mysqli_prepare($conn, $requestsQuery);
$requests = [];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, "i", $teacher_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $requests[] = $row;
      }
  }
}

// Count requests by status
$countQuery = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN ar.status = 'pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN ar.status = 'approved' THEN 1 ELSE 0 END) as approved,
              SUM(CASE WHEN ar.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM attendance_requests ar 
            JOIN users u ON ar.student_id = u.id 
            JOIN teacher_classes tc ON (ar.class_id = tc.class_id AND ar.subject_id = tc.subject_id)
            WHERE tc.teacher_id = ?";

$stmt = mysqli_prepare($conn, $countQuery);
$counts = [
  'total' => 0,
  'pending' => 0,
  'approved' => 0,
  'rejected' => 0
];

if ($stmt) {
  mysqli_stmt_bind_param($stmt, "i", $teacher_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result && $row = mysqli_fetch_assoc($result)) {
      $counts = $row;
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
                        <a class="nav-link active" href="attendance_requests.php">
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
                  <h2><i class="fas fa-clipboard-list me-2 text-danger"></i>Attendance Requests</h2>
                  <div>
                      <span class="text-light"><?php echo date('l, F d, Y'); ?></span>
                  </div>
              </div>
              
              <!-- Request Statistics -->
              <div class="row mb-4">
                  <div class="col-md-3">
                      <div class="card shadow-sm text-center">
                          <div class="card-body">
                              <h5 class="card-title text-primary">Total Requests</h5>
                              <h2 class="display-4 text-primary"><?php echo $counts['total'] ?? 0; ?></h2>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="card shadow-sm text-center">
                          <div class="card-body">
                              <h5 class="card-title text-warning">Pending</h5>
                              <h2 class="display-4 text-warning"><?php echo $counts['pending'] ?? 0; ?></h2>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="card shadow-sm text-center">
                          <div class="card-body">
                              <h5 class="card-title text-success">Approved</h5>
                              <h2 class="display-4 text-success"><?php echo $counts['approved'] ?? 0; ?></h2>
                          </div>
                      </div>
                  </div>
                  <div class="col-md-3">
                      <div class="card shadow-sm text-center">
                          <div class="card-body">
                              <h5 class="card-title text-danger">Rejected</h5>
                              <h2 class="display-4 text-danger"><?php echo $counts['rejected'] ?? 0; ?></h2>
                          </div>
                      </div>
                  </div>
              </div>
              
              <div class="card shadow-sm mb-4">
                  <div class="card-header bg-danger text-white">
                      <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Requests</h5>
                  </div>
                  <div class="card-body">
                      <div class="btn-group" role="group">
                          <a href="attendance_requests.php?status=pending" class="btn btn-outline-warning <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">
                              <i class="fas fa-clock me-2"></i>Pending
                          </a>
                          <a href="attendance_requests.php?status=approved" class="btn btn-outline-success <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">
                              <i class="fas fa-check me-2"></i>Approved
                          </a>
                          <a href="attendance_requests.php?status=rejected" class="btn btn-outline-danger <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">
                              <i class="fas fa-times me-2"></i>Rejected
                          </a>
                          <a href="attendance_requests.php?status=all" class="btn btn-outline-primary <?php echo $status_filter == 'all' ? 'active' : ''; ?>">
                              <i class="fas fa-list me-2"></i>All
                          </a>
                      </div>
                  </div>
              </div>
              
              <div class="card shadow-sm">
                  <div class="card-header bg-danger text-white">
                      <h5 class="mb-0">
                          <i class="fas fa-clipboard-list me-2"></i>
                          <?php 
                          if ($status_filter == 'pending') echo 'Pending';
                          elseif ($status_filter == 'approved') echo 'Approved';
                          elseif ($status_filter == 'rejected') echo 'Rejected';
                          else echo 'All';
                          ?> Attendance Requests
                      </h5>
                  </div>
                  <div class="card-body">
                      <?php if (!empty($requests)): ?>
                      <div class="table-responsive">
                          <table class="table table-hover">
                              <thead>
                                  <tr>
                                      <th>Student</th>
                                      <th>Roll No</th>
                                      <th>Semester</th>
                                      <th>Class</th>
                                      <th>Subject</th>
                                      <th>Date</th>
                                      <th>Reason</th>
                                      <th>Requested On</th>
                                      <th>Status</th>
                                      <th>Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($requests as $request): ?>
                                  <tr>
                                      <td><?php echo htmlspecialchars($request['name']); ?></td>
                                      <td><?php echo htmlspecialchars($request['roll_no']); ?></td>
                                      <td><?php echo $request['semester']; ?></td>
                                      <td><?php echo htmlspecialchars($request['class_name']); ?></td>
                                      <td><?php echo htmlspecialchars($request['subject_code'] . ' - ' . $request['subject_name']); ?></td>
                                      <td><?php echo date('d M Y', strtotime($request['date'])); ?></td>
                                      <td><?php echo htmlspecialchars($request['reason'] ?: 'Not provided'); ?></td>
                                      <td><?php echo date('d M Y h:i A', strtotime($request['created_at'])); ?></td>
                                      <td>
                                          <?php if ($request['status'] == 'pending'): ?>
                                              <span class="badge bg-warning">Pending</span>
                                          <?php elseif ($request['status'] == 'approved'): ?>
                                              <span class="badge bg-success">Approved</span>
                                          <?php else: ?>
                                              <span class="badge bg-danger">Rejected</span>
                                          <?php endif; ?>
                                      </td>
                                      <td>
                                          <?php if ($request['status'] == 'pending'): ?>
                                              <div class="btn-group" role="group">
                                                  <a href="attendance_requests.php?action=approve&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Are you sure you want to approve this request?')">
                                                      <i class="fas fa-check"></i> Approve
                                                  </a>
                                                  <a href="attendance_requests.php?action=reject&id=<?php echo $request['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to reject this request?')">
                                                      <i class="fas fa-times"></i> Reject
                                                  </a>
                                              </div>
                                          <?php else: ?>
                                              <span class="text-muted">No actions</span>
                                          <?php endif; ?>
                                      </td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                      <div class="text-center py-5">
                          <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                          <h5>No Requests Found</h5>
                          <p class="text-muted">There are no <?php echo $status_filter != 'all' ? $status_filter : ''; ?> attendance requests at this time.</p>
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

