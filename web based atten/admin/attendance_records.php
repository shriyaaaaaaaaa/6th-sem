<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Get filters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

// Handle record deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Delete the attendance record
        $deleteQuery = "DELETE FROM attendance WHERE id = ?";
        $stmt = mysqli_prepare($conn, $deleteQuery);
        mysqli_stmt_bind_param($stmt, 'i', $delete_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Error deleting record: " . mysqli_error($conn));
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        $_SESSION['message'] = 'Attendance record deleted successfully.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = 'error';
    }
    
    // Redirect to avoid repeated deletions on refresh
    header('Location: attendance_records.php');
    exit;
}

// Handle record update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_record') {
    $record_id = isset($_POST['record_id']) ? intval($_POST['record_id']) : 0;
    $new_status = isset($_POST['status']) ? $_POST['status'] : '';
    
    if ($record_id > 0 && in_array($new_status, ['present', 'absent'])) {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update the attendance record
            $updateQuery = "UPDATE attendance SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $updateQuery);
            mysqli_stmt_bind_param($stmt, 'si', $new_status, $record_id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error updating record: " . mysqli_error($conn));
            }
            
            // Commit transaction
            mysqli_commit($conn);
            
            $_SESSION['message'] = 'Attendance record updated successfully.';
            $_SESSION['message_type'] = 'success';
        } catch (Exception $e) {
            // Rollback transaction on error
            mysqli_rollback($conn);
            
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'error';
        }
    } else {
        $_SESSION['message'] = 'Invalid record ID or status.';
        $_SESSION['message_type'] = 'error';
    }
    
    // Redirect to avoid form resubmission
    header('Location: attendance_records.php');
    exit;
}

// Build query based on filters - FIXED QUERY with subject information
$recordsQuery = "SELECT a.*, 
                     s.name as student_name, s.roll_no, s.semester,
                     t.name as teacher_name,
                     sub.name as subject_name
              FROM attendance a 
              JOIN users s ON a.student_id = s.id 
              LEFT JOIN users t ON a.teacher_id = t.id
              LEFT JOIN subjects sub ON a.subject_id = sub.id
              WHERE 1=1";

$params = [];
$types = "";

if ($student_id > 0) {
  $recordsQuery .= " AND a.student_id = ?";
  $params[] = $student_id;
  $types .= "i";
}

if ($teacher_id > 0) {
  $recordsQuery .= " AND a.teacher_id = ?";
  $params[] = $teacher_id;
  $types .= "i";
}

if ($semester > 0) {
  $recordsQuery .= " AND s.semester = ?";
  $params[] = $semester;
  $types .= "i";
}

if ($subject_id > 0) {
  $recordsQuery .= " AND a.subject_id = ?";
  $params[] = $subject_id;
  $types .= "i";
}

if (!empty($date)) {
  $recordsQuery .= " AND DATE(a.date) = ?";
  $params[] = $date;
  $types .= "s";
} else {
  $recordsQuery .= " AND MONTH(a.date) = ? AND YEAR(a.date) = ?";
  $params[] = $month;
  $params[] = $year;
  $types .= "ii";
}

if (!empty($status)) {
  $recordsQuery .= " AND a.status = ?";
  $params[] = $status;
  $types .= "s";
}

$recordsQuery .= " ORDER BY a.date DESC, s.name ASC";

$stmt = mysqli_prepare($conn, $recordsQuery);
$records = [];

if ($stmt) {
  if (!empty($params)) {
      mysqli_stmt_bind_param($stmt, $types, ...$params);
  }
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  
  if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
          $records[] = $row;
      }
  }
}

// Get all students for filter
$studentsQuery = "SELECT id, name, roll_no FROM users WHERE role = 'student' ORDER BY name ASC";
$studentsResult = mysqli_query($conn, $studentsQuery);
$students = [];

if ($studentsResult) {
  while ($row = mysqli_fetch_assoc($studentsResult)) {
      $students[] = $row;
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
$subjectsQuery = "SELECT id, name FROM subjects ORDER BY name ASC";
$subjectsResult = mysqli_query($conn, $subjectsQuery);
$subjects = [];

if ($subjectsResult) {
  while ($row = mysqli_fetch_assoc($subjectsResult)) {
      $subjects[] = $row;
  }
}

// Get all semesters for filter
$semestersQuery = "SELECT DISTINCT semester FROM users WHERE role = 'student' ORDER BY semester ASC";
$semestersResult = mysqli_query($conn, $semestersQuery);
$semesters = [];

if ($semestersResult) {
  while ($row = mysqli_fetch_assoc($semestersResult)) {
      $semesters[] = $row['semester'];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance Records - BCA Attendance System</title>
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
                      <a class="nav-link active" href="attendance_records.php">
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
                  <h2><i class="fas fa-calendar-check me-2"></i>Attendance Records</h2>
                  <div>
                      <a href="reports.php" class="btn btn-danger">
                          <i class="fas fa-chart-bar me-2"></i>View Reports
                      </a>
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
                          <div class="col-md-3">
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
                          <div class="col-md-3">
                              <label for="subject_id" class="form-label">Subject</label>
                              <select class="form-select" id="subject_id" name="subject_id">
                                  <option value="0">All Subjects</option>
                                  <?php foreach ($subjects as $subject): ?>
                                  <option value="<?php echo $subject['id']; ?>" <?php echo $subject_id == $subject['id'] ? 'selected' : ''; ?>>
                                      <?php echo htmlspecialchars($subject['name']); ?>
                                  </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="col-md-3">
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
                          <div class="col-md-3">
                              <label for="status" class="form-label">Status</label>
                              <select class="form-select" id="status" name="status">
                                  <option value="">All</option>
                                  <option value="present" <?php echo $status == 'present' ? 'selected' : ''; ?>>Present</option>
                                  <option value="absent" <?php echo $status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                              </select>
                          </div>
                          <div class="col-md-3">
                              <label for="date" class="form-label">Specific Date (Optional)</label>
                              <input type="date" class="form-control" id="date" name="date" value="<?php echo $date; ?>">
                          </div>
                          <div class="col-md-2 d-flex align-items-end">
                              <button type="submit" class="btn btn-danger me-2">
                                  <i class="fas fa-filter me-2"></i>Apply Filter
                              </button>
                          </div>
                          <div class="col-md-12 mt-2">
                              <a href="attendance_records.php" class="btn btn-outline-secondary">
                                  <i class="fas fa-redo me-2"></i>Reset Filters
                              </a>
                          </div>
                      </form>
                  </div>
              </div>
              
              <div class="card shadow-sm">
                  <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                      <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Attendance Records</h5>
                      <div>
                          <button class="btn btn-sm btn-light" onclick="exportToCSV()">
                              <i class="fas fa-download me-1"></i>Export to CSV
                          </button>
                      </div>
                  </div>
                  <div class="card-body">
                      <?php if (!empty($records)): ?>
                      <div class="table-responsive">
                          <table class="table table-hover" id="attendanceTable">
                              <thead>
                                  <tr>
                                      <th>Date</th>
                                      <th>Student Name</th>
                                      <th>Roll No</th>
                                      <th>Semester</th>
                                      <th>Subject</th>
                                      <th>Status</th>
                                      <th>Marked At</th>
                                      <th>Marked By</th>
                                      <th>Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($records as $record): ?>
                                  <tr>
                                      <td><?php echo date('d M Y', strtotime($record['date'])); ?></td>
                                      <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                      <td><?php echo htmlspecialchars($record['roll_no']); ?></td>
                                      <td><?php echo htmlspecialchars($record['semester']); ?></td>
                                      <td><?php echo htmlspecialchars($record['subject_name'] ?? 'N/A'); ?></td>
                                      <td>
                                          <?php if ($record['status'] == 'present'): ?>
                                              <span class="badge bg-success">Present</span>
                                          <?php else: ?>
                                              <span class="badge bg-danger">Absent</span>
                                          <?php endif; ?>
                                      </td>
                                      <td><?php echo date('h:i A', strtotime($record['marked_at'])); ?></td>
                                      <td><?php echo htmlspecialchars($record['teacher_name'] ?? 'System'); ?></td>
                                      <td>
                                          <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal" 
                                              data-id="<?php echo $record['id']; ?>" 
                                              data-student="<?php echo htmlspecialchars($record['student_name']); ?>" 
                                              data-date="<?php echo date('d M Y', strtotime($record['date'])); ?>"
                                              data-status="<?php echo $record['status']; ?>">
                                              <i class="fas fa-edit"></i>
                                          </button>
                                          <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $record['id']; ?>)" class="btn btn-sm btn-danger">
                                              <i class="fas fa-trash"></i>
                                          </a>
                                      </td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                      <div class="text-center py-5">
                          <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                          <h5>No Records Found</h5>
                          <p class="text-muted">No attendance records found for the selected filters.</p>
                      </div>
                      <?php endif; ?>
                  </div>
              </div>
          </div>
      </div>
  </div>
  
  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
          <div class="modal-content">
              <div class="modal-header bg-danger text-white">
                  <h5 class="modal-title">Edit Attendance Record</h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <form method="POST" action="attendance_records.php">
                  <input type="hidden" name="action" value="update_record">
                  <input type="hidden" name="record_id" id="record_id">
                  
                  <div class="modal-body">
                      <div class="mb-3">
                          <label class="form-label">Student</label>
                          <input type="text" class="form-control" id="student_name" readonly>
                      </div>
                      <div class="mb-3">
                          <label class="form-label">Date</label>
                          <input type="text" class="form-control" id="record_date" readonly>
                      </div>
                      <div class="mb-3">
                          <label for="status" class="form-label">Status</label>
                          <select class="form-select" id="edit_status" name="status" required>
                              <option value="present">Present</option>
                              <option value="absent">Absent</option>
                          </select>
                      </div>
                  </div>
                  <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-danger">Update Record</button>
                  </div>
              </form>
          </div>
      </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
  <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
  <script src="../assets/js/main.js"></script>
  <script>
      // Export table to CSV
      function exportToCSV() {
          const table = document.getElementById('attendanceTable');
          if (!table) return;
          
          let csv = [];
          const rows = table.querySelectorAll('tr');
          
          for (let i = 0; i < rows.length; i++) {
              const row = [], cols = rows[i].querySelectorAll('td, th');
              
              for (let j = 0; j < cols.length; j++) {
                  // Skip the actions column
                  if (i === 0 && cols[j].textContent.trim() === 'Actions') continue;
                  if (j === cols.length - 1 && i > 0) continue;
                  
                  // Get the text content and clean it
                  let data = cols[j].textContent.replace(/(\r\n|\n|\r)/gm, '').trim();
                  // Escape double quotes
                  data = data.replace(/"/g, '""');
                  // Add the data to the row
                  row.push('"' + data + '"');
              }
              csv.push(row.join(','));
          }
          
          // Create a CSV file
          const csvString = csv.join('\n');
          const filename = 'attendance_records_<?php echo date('Y-m-d'); ?>.csv';
          
          // Create a download link and trigger it
          const link = document.createElement('a');
          link.style.display = 'none';
          link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvString));
          link.setAttribute('download', filename);
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
      }
      
      // Disable month/year when specific date is selected
      document.getElementById('date').addEventListener('change', function() {
          const monthSelect = document.getElementById('month');
          const yearSelect = document.getElementById('year');
          
          if (this.value) {
              monthSelect.disabled = true;
              yearSelect.disabled = true;
          } else {
              monthSelect.disabled = false;
              yearSelect.disabled = false;
          }
      });
      
      // Initialize on page load
      document.addEventListener('DOMContentLoaded', function() {
          const dateInput = document.getElementById('date');
          const monthSelect = document.getElementById('month');
          const yearSelect = document.getElementById('year');
          
          if (dateInput.value) {
              monthSelect.disabled = true;
              yearSelect.disabled = true;
          }
          
          // Setup edit modal
          const editModal = document.getElementById('editModal');
          if (editModal) {
              editModal.addEventListener('show.bs.modal', function(event) {
                  const button = event.relatedTarget;
                  const id = button.getAttribute('data-id');
                  const student = button.getAttribute('data-student');
                  const date = button.getAttribute('data-date');
                  const status = button.getAttribute('data-status');
                  
                  document.getElementById('record_id').value = id;
                  document.getElementById('student_name').value = student;
                  document.getElementById('record_date').value = date;
                  document.getElementById('edit_status').value = status;
              });
          }
      });
      
      // Confirm delete
      function confirmDelete(id) {
          if (confirm('Are you sure you want to delete this attendance record? This action cannot be undone.')) {
              window.location.href = 'attendance_records.php?delete=' + id;
          }
      }
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

