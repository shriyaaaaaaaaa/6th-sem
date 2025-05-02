<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has admin role
requireRole('admin');

// Initialize variables
$message = '';
$message_type = '';
$holiday_id = 0;
$holiday_name = '';
$holiday_date = '';
$holiday_type = 'regular';
$is_recurring = 0;

// Handle form submission for adding/editing holiday
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_holiday') {
    // Validate and sanitize inputs
    $holiday_id = isset($_POST['holiday_id']) ? intval($_POST['holiday_id']) : 0;
    $holiday_name = filter_input(INPUT_POST, 'holiday_name', FILTER_SANITIZE_STRING);
    $holiday_date = filter_input(INPUT_POST, 'holiday_date', FILTER_SANITIZE_STRING);
    $holiday_type = filter_input(INPUT_POST, 'holiday_type', FILTER_SANITIZE_STRING);
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;

    // Validate inputs
    if (empty($holiday_name)) {
        $message = 'Holiday name is required.';
        $message_type = 'error';
    } elseif (empty($holiday_date)) {
        $message = 'Holiday date is required.';
        $message_type = 'error';
    } elseif (!in_array($holiday_type, ['regular', 'exam', 'event'])) {
        $message = 'Invalid holiday type.';
        $message_type = 'error';
    } elseif (strtotime($holiday_date) < strtotime(date('Y-m-d'))) { // Check if the date is in the past
        $message = 'Holiday date cannot be in the past.';
        $message_type = 'error';
    } else {
        if ($holiday_id > 0) {
            // Update existing holiday
            $updateQuery = "UPDATE holidays SET 
                name = ?, 
                date = ?, 
                type = ?,
                is_recurring = ?,
                updated_at = NOW()
                WHERE id = ?";
                
            $stmt = mysqli_prepare($conn, $updateQuery);
            mysqli_stmt_bind_param($stmt, 'sssii', 
                $holiday_name, 
                $holiday_date, 
                $holiday_type,
                $is_recurring,
                $holiday_id
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Holiday updated successfully.';
                $message_type = 'success';
            } else {
                $message = 'Error updating holiday: ' . mysqli_error($conn);
                $message_type = 'error';
            }
        } else {
            // Add new holiday
            $insertQuery = "INSERT INTO holidays (name, date, type, is_recurring, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
                
            $stmt = mysqli_prepare($conn, $insertQuery);
            mysqli_stmt_bind_param($stmt, 'sssi', 
                $holiday_name, 
                $holiday_date, 
                $holiday_type,
                $is_recurring
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Holiday added successfully.';
                $message_type = 'success';
            } else {
                $message = 'Error adding holiday: ' . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
    
    // Set session message
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    
    // Redirect to avoid form resubmission
    header('Location: holidays.php');
    exit;
}

// Handle holiday deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    $deleteQuery = "DELETE FROM holidays WHERE id = ?";
    $stmt = mysqli_prepare($conn, $deleteQuery);
    mysqli_stmt_bind_param($stmt, 'i', $delete_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['message'] = 'Holiday deleted successfully.';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error deleting holiday: ' . mysqli_error($conn);
        $_SESSION['message_type'] = 'error';
    }
    
    // Redirect to avoid repeated deletions on refresh
    header('Location: holidays.php');
    exit;
}

// Handle edit request
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    
    $editQuery = "SELECT * FROM holidays WHERE id = ?";
    $stmt = mysqli_prepare($conn, $editQuery);
    mysqli_stmt_bind_param($stmt, 'i', $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $holiday_id = $row['id'];
        $holiday_name = $row['name'];
        $holiday_date = $row['date'];
        $holiday_type = $row['type'];
        $is_recurring = $row['is_recurring'];
    }
}

// Get all holidays
$currentYear = date('Y');
$holidaysQuery = "SELECT * FROM holidays ORDER BY date ASC";
$holidaysResult = mysqli_query($conn, $holidaysQuery);
$holidays = [];

if ($holidaysResult) {
    while ($row = mysqli_fetch_assoc($holidaysResult)) {
        $holidays[] = $row;
    }
}

// Get holidays for calendar view
$calendarHolidays = [];
foreach ($holidays as $holiday) {
    $holidayDate = new DateTime($holiday['date']);
    $month = $holidayDate->format('n');
    $day = $holidayDate->format('j');
    
    // For recurring holidays, use only month and day
    if ($holiday['is_recurring']) {
        $calendarHolidays[] = [
            'id' => $holiday['id'],
            'title' => $holiday['name'],
            'month' => $month,
            'day' => $day,
            'type' => $holiday['type'],
            'recurring' => true
        ];
    } else {
        // For non-recurring, use full date
        $year = $holidayDate->format('Y');
        $calendarHolidays[] = [
            'id' => $holiday['id'],
            'title' => $holiday['name'],
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'type' => $holiday['type'],
            'recurring' => false
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holidays Management - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .calendar {
            width: 100%;
            border-collapse: collapse;
        }
        .calendar th, .calendar td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: center;
        }
        .calendar th {
            background-color: #f8f9fa;
        }
        .calendar .today {
            background-color: #e2f0ff;
            font-weight: bold;
        }
        .calendar .holiday {
            background-color: #ffebee;
        }
        .calendar .holiday.exam {
            background-color: #fff8e1;
        }
        .calendar .holiday.event {
            background-color: #e8f5e9;
        }
        .holiday-badge {
            display: block;
            font-size: 0.8rem;
            padding: 2px;
            margin-top: 2px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .holiday-badge.regular {
            background-color: #ffcdd2;
            color: #c62828;
        }
        .holiday-badge.exam {
            background-color: #ffe082;
            color: #ff8f00;
        }
        .holiday-badge.event {
            background-color: #c8e6c9;
            color: #2e7d32;
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
                        <a class="nav-link" href="teachers.php">
                            <i class="fas fa-chalkboard-teacher me-1"></i>Teachers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="holidays.php">
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
                    <h2><i class="fas fa-calendar-alt me-2"></i>Holidays Management</h2>
                    <div>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#holidayModal">
                            <i class="fas fa-plus me-2"></i>Add New Holiday
                        </button>
                    </div>
                </div>
                
                <!-- Calendar View -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-calendar me-2"></i>Calendar View</h5>
                        <div>
                            <select id="calendarYear" class="form-select form-select-sm" style="width: auto; display: inline-block;">
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                            <select id="calendarMonth" class="form-select form-select-sm ms-2" style="width: auto; display: inline-block;">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="calendar" id="holidayCalendar">
                                <thead>
                                    <tr>
                                        <th>Sun</th>
                                        <th>Mon</th>
                                        <th>Tue</th>
                                        <th>Wed</th>
                                        <th>Thu</th>
                                        <th>Fri</th>
                                        <th>Sat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Calendar will be generated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            <div class="d-flex flex-wrap gap-3">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-danger me-2">Regular Holiday</span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-warning text-dark me-2">Exam Day</span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-success me-2">Event</span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-secondary me-2"><i class="fas fa-sync-alt"></i></span>
                                    <span>Recurring Yearly</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Holidays List -->
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Holidays List</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($holidays)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Recurring</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($holidays as $holiday): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($holiday['name']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($holiday['date'])); ?></td>
                                        <td>
                                            <?php if ($holiday['type'] == 'regular'): ?>
                                                <span class="badge bg-danger">Regular Holiday</span>
                                            <?php elseif ($holiday['type'] == 'exam'): ?>
                                                <span class="badge bg-warning text-dark">Exam Day</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Event</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($holiday['is_recurring']): ?>
                                                <span class="badge bg-secondary"><i class="fas fa-sync-alt me-1"></i>Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="holidays.php?edit=<?php echo $holiday['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $holiday['id']; ?>)" class="btn btn-sm btn-danger">
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
                            <h5>No Holidays Found</h5>
                            <p class="text-muted">No holidays have been added yet.</p>
                            <button type="button" class="btn btn-danger mt-3" data-bs-toggle="modal" data-bs-target="#holidayModal">
                                <i class="fas fa-plus me-2"></i>Add New Holiday
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Holiday Modal -->
    <div class="modal fade" id="holidayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="holidayModalLabel">
                        <?php echo $holiday_id > 0 ? 'Edit Holiday' : 'Add New Holiday'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="holidays.php">
                    <input type="hidden" name="action" value="save_holiday">
                    <input type="hidden" name="holiday_id" value="<?php echo $holiday_id; ?>">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="holiday_name" class="form-label">Holiday Name</label>
                            <input type="text" class="form-control" id="holiday_name" name="holiday_name" value="<?php echo htmlspecialchars($holiday_name); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="holiday_date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="holiday_date" name="holiday_date" value="<?php echo $holiday_date; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="holiday_type" class="form-label">Type</label>
                            <select class="form-select" id="holiday_type" name="holiday_type" required>
                                <option value="regular" <?php echo $holiday_type == 'regular' ? 'selected' : ''; ?>>Regular Holiday</option>
                                <option value="exam" <?php echo $holiday_type == 'exam' ? 'selected' : ''; ?>>Exam Day</option>
                                <option value="event" <?php echo $holiday_type == 'event' ? 'selected' : ''; ?>>Event</option>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring" <?php echo $is_recurring ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_recurring">
                                Recurring Yearly
                            </label>
                            <div class="form-text">If checked, this holiday will repeat every year on the same date.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Save Holiday</button>
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
        // Open modal if edit parameter is present
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($holiday_id > 0): ?>
            var holidayModal = new bootstrap.Modal(document.getElementById('holidayModal'));
            holidayModal.show();
            <?php endif; ?>
            
            // Generate calendar
            generateCalendar();
            
            // Add event listeners for calendar navigation
            document.getElementById('calendarYear').addEventListener('change', generateCalendar);
            document.getElementById('calendarMonth').addEventListener('change', generateCalendar);
        });
        
        // Confirm delete
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this holiday?')) {
                window.location.href = 'holidays.php?delete=' + id;
            }
        }
        
        // Generate calendar
        function generateCalendar() {
            const year = parseInt(document.getElementById('calendarYear').value);
            const month = parseInt(document.getElementById('calendarMonth').value);
            const today = new Date();
            
            // Get first day of month and number of days
            const firstDay = new Date(year, month - 1, 1);
            const lastDay = new Date(year, month, 0);
            const daysInMonth = lastDay.getDate();
            const startingDay = firstDay.getDay(); // 0 = Sunday
            
            // Get holidays for this month
            const holidays = <?php echo json_encode($calendarHolidays); ?>;
            const monthHolidays = holidays.filter(h => {
                if (h.recurring) {
                    return h.month === month;
                } else {
                    return h.year === year && h.month === month;
                }
            });
            
            // Generate calendar HTML
            let calendarHTML = '';
            let day = 1;
            
            // Create calendar rows
            for (let i = 0; i < 6; i++) {
                // Break if we've already used all days of the month
                if (day > daysInMonth) break;
                
                calendarHTML += '<tr>';
                
                // Create calendar cells for each day of the week
                for (let j = 0; j < 7; j++) {
                    if ((i === 0 && j < startingDay) || day > daysInMonth) {
                        // Empty cell
                        calendarHTML += '<td></td>';
                    } else {
                        // Check if this day is a holiday
                        const dayHolidays = monthHolidays.filter(h => h.day === day);
                        const isHoliday = dayHolidays.length > 0;
                        const isToday = today.getDate() === day && today.getMonth() === month - 1 && today.getFullYear() === year;
                        
                        // Create cell with appropriate classes
                        let cellClass = '';
                        if (isToday) cellClass += ' today';
                        if (isHoliday) cellClass += ' holiday';
                        
                        calendarHTML += `<td class="${cellClass}">`;
                        calendarHTML += `<div>${day}</div>`;
                        
                        // Add holiday badges
                        if (isHoliday) {
                            dayHolidays.forEach(holiday => {
                                const recurringIcon = holiday.recurring ? '<i class="fas fa-sync-alt me-1"></i>' : '';
                                calendarHTML += `<span class="holiday-badge ${holiday.type}" title="${holiday.title}">${recurringIcon}${holiday.title}</span>`;
                            });
                        }
                        
                        calendarHTML += '</td>';
                        day++;
                    }
                }
                
                calendarHTML += '</tr>';
            }
            
            // Update calendar
            document.querySelector('#holidayCalendar tbody').innerHTML = calendarHTML;
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

