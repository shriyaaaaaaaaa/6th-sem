<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has student role
requireRole('student');

// Get student ID
$student_id = $_SESSION['user_id'];

// Set content type to JSON
header('Content-Type: application/json');

// Get pending attendance requests
$pendingRequestsQuery = "SELECT ar.*, c.name as class_name, s.name as subject_name 
                       FROM attendance_requests ar 
                       JOIN classes c ON ar.class_id = c.id 
                       JOIN subjects s ON ar.subject_id = s.id 
                       WHERE ar.student_id = ? AND ar.status = 'pending'";
$stmt = mysqli_prepare($conn, $pendingRequestsQuery);
$pendingRequests = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Format the data for the calendar
        $pendingRequests[] = [
            'id' => $row['id'],
            'title' => $row['class_name'] . ' - ' . $row['subject_name'],
            'start' => $row['date'],
            'className' => 'bg-warning',
            'extendedProps' => [
                'status' => 'pending',
                'reason' => $row['reason'],
                'requested_at' => $row['created_at'],
                'class_name' => $row['class_name'],
                'subject_name' => $row['subject_name']
            ]
        ];
    }
}

// Get approved attendance requests
$approvedRequestsQuery = "SELECT ar.*, c.name as class_name, s.name as subject_name 
                        FROM attendance_requests ar 
                        JOIN classes c ON ar.class_id = c.id 
                        JOIN subjects s ON ar.subject_id = s.id 
                        WHERE ar.student_id = ? AND ar.status = 'approved'";
$stmt = mysqli_prepare($conn, $approvedRequestsQuery);
$approvedRequests = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Format the data for the calendar
        $approvedRequests[] = [
            'id' => $row['id'],
            'title' => $row['class_name'] . ' - ' . $row['subject_name'],
            'start' => $row['date'],
              => $row['class_name'] . ' - ' . $row['subject_name'],
            'start' => $row['date'],
            'className' => 'bg-success',
            'extendedProps' => [
                'status' => 'approved',
                'reason' => $row['reason'],
                'requested_at' => $row['created_at'],
                'approved_at' => $row['updated_at'],
                'class_name' => $row['class_name'],
                'subject_name' => $row['subject_name']
            ]
        ];
    }
}

// Get rejected attendance requests
$rejectedRequestsQuery = "SELECT ar.*, c.name as class_name, s.name as subject_name 
                        FROM attendance_requests ar 
                        JOIN classes c ON ar.class_id = c.id 
                        JOIN subjects s ON ar.subject_id = s.id 
                        WHERE ar.student_id = ? AND ar.status = 'rejected'";
$stmt = mysqli_prepare($conn, $rejectedRequestsQuery);
$rejectedRequests = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Format the data for the calendar
        $rejectedRequests[] = [
            'id' => $row['id'],
            'title' => $row['class_name'] . ' - ' . $row['subject_name'],
            'start' => $row['date'],
            'className' => 'bg-danger',
            'extendedProps' => [
                'status' => 'rejected',
                'reason' => $row['reason'],
                'rejection_reason' => $row['rejection_reason'],
                'requested_at' => $row['created_at'],
                'rejected_at' => $row['updated_at'],
                'class_name' => $row['class_name'],
                'subject_name' => $row['subject_name']
            ]
        ];
    }
}

// Get marked attendance
$attendanceQuery = "SELECT a.*, c.name as class_name, s.name as subject_name 
                  FROM attendance a 
                  JOIN classes c ON a.class_id = c.id 
                  JOIN subjects s ON a.subject_id = s.id 
                  WHERE a.student_id = ? AND a.status = 'present'";
$stmt = mysqli_prepare($conn, $attendanceQuery);
$attendance = [];

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Format the data for the calendar
        $attendance[] = [
            'id' => 'att_' . $row['id'],
            'title' => $row['class_name'] . ' - ' . $row['subject_name'],
            'start' => $row['date'],
            'className' => 'bg-primary',
            'extendedProps' => [
                'status' => 'present',
                'marked_at' => $row['marked_at'],
                'class_name' => $row['class_name'],
                'subject_name' => $row['subject_name']
            ]
        ];
    }
}

// Get holidays
$holidaysQuery = "SELECT * FROM holidays";
$holidaysResult = mysqli_query($conn, $holidaysQuery);
$holidays = [];

if ($holidaysResult) {
    while ($row = mysqli_fetch_assoc($holidaysResult)) {
        $holidays[] = [
            'id' => 'holiday_' . $row['id'],
            'title' => $row['name'],
            'start' => $row['date'],
            'className' => 'bg-info',
            'extendedProps' => [
                'type' => 'holiday',
                'description' => $row['description']
            ]
        ];
    }
}

// Combine all events
$events = array_merge($pendingRequests, $approvedRequests, $rejectedRequests, $attendance, $holidays);

// Return JSON response
echo json_encode($events);
?>

```js file="assets/js/student-calendar.js"
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the calendar
    const calendarEl = document.getElementById('studentCalendar');
    
    if (!calendarEl) return;
    
    // Get current date
    const currentDate = new Date();
    
    // Initialize FullCalendar
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listMonth'
        },
        themeSystem: 'bootstrap5',
        selectable: true,
        selectMirror: true,
        navLinks: true,
        editable: false,
        dayMaxEvents: true,
        weekends: true,
        events: function(info, successCallback, failureCallback) {
            // Fetch events from the server
            fetch('get_pending_requests.php')
                .then(response => response.json())
                .then(data => {
                    successCallback(data);
                })
                .catch(error => {
                    console.error('Error fetching calendar events:', error);
                    alertify.error('Failed to load calendar events. Please try again.');
                    failureCallback(error);
                });
        },
        eventClick: function(info) {
            // Show event details when clicked
            const event = info.event;
            const props = event.extendedProps;
            
            let modalTitle = '';
            let modalBody = '';
            
            if (props.type === 'holiday') {
                // Holiday event
                modalTitle = 'Holiday: ' + event.title;
                modalBody = `
                    <p><strong>Date:</strong> ${formatDate(event.start)}</p>
                    <p><strong>Description:</strong> ${props.description || 'No description available'}</p>
                `;
            } else if (props.status === 'pending') {
                // Pending attendance request
                modalTitle = 'Pending Attendance Request';
                modalBody = `
                    <p><strong>Class:</strong> ${props.class_name}</p>
                    <p><strong>Subject:</strong> ${props.subject_name}</p>
                    <p><strong>Date:</strong> ${formatDate(event.start)}</p>
                    <p><strong>Status:</strong> <span class="badge bg-warning">Pending</span></p>
                    <p><strong>Reason:</strong> ${props.reason}</p>
                    <p><strong>Requested:</strong> ${formatDateTime(props.requested_at)}</p>
                    <div class="d-grid gap-2 mt-3">
                        <button type="button" class="btn btn-danger" onclick="cancelRequest(${event.id})">
                            <i class="fas fa-times-circle me-2"></i>Cancel Request
                        </button>
                    </div>
                `;
            } else if (props.status === 'approved') {
                // Approved attendance request
                modalTitle = 'Approved Attendance Request';
                modalBody = `
                    <p><strong>Class:</strong> ${props.class_name}</p>
                    <p><strong>Subject:</strong> ${props.subject_name}</p>
                    <p><strong>Date:</strong> ${formatDate(event.start)}</p>
                    <p><strong>Status:</strong> <span class="badge bg-success">Approved</span></p>
                    <p><strong>Reason:</strong> ${props.reason}</p>
                    <p><strong>Requested:</strong> ${formatDateTime(props.requested_at)}</p>
                    <p><strong>Approved:</strong> ${formatDateTime(props.approved_at)}</p>
                `;
            } else if (props.status === 'rejected') {
                // Rejected attendance request
                modalTitle = 'Rejected Attendance Request';
                modalBody = `
                    <p><strong>Class:</strong> ${props.class_name}</p>
                    <p><strong>Subject:</strong> ${props.subject_name}</p>
                    <p><strong>Date:</strong> ${formatDate(event.start)}</p>
                    <p><strong>Status:</strong> <span class="badge bg-danger">Rejected</span></p>
                    <p><strong>Reason:</strong> ${props.reason}</p>
                    <p><strong>Rejection Reason:</strong> ${props.rejection_reason || 'No reason provided'}</p>
                    <p><strong>Requested:</strong> ${formatDateTime(props.requested_at)}</p>
                    <p><strong>Rejected:</strong> ${formatDateTime(props.rejected_at)}</p>
                `;
            } else if (props.status === 'present') {
                // Marked attendance
                modalTitle = 'Attendance Record';
                modalBody = `
                    <p><strong>Class:</strong> ${props.class_name}</p>
                    <p><strong>Subject:</strong> ${props.subject_name}</p>
                    <p><strong>Date:</strong> ${formatDate(event.start)}</p>
                    <p><strong>Status:</strong> <span class="badge bg-primary">Present</span></p>
                    <p><strong>Marked At:</strong> ${formatDateTime(props.marked_at)}</p>
                `;
            }
            
            // Show modal with event details
            document.getElementById('eventModalTitle').innerHTML = modalTitle;
            document.getElementById('eventModalBody').innerHTML = modalBody;
            
            const eventModal = new bootstrap.Modal(document.getElementById('eventModal'));
            eventModal.show();
        },
        dateClick: function(info) {
            // Handle date click to request attendance for past dates
            const clickedDate = new Date(info.dateStr);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            // Only allow requesting attendance for past dates (not future or today)
            if (clickedDate &lt; today) {
                // Check if it's a weekend
                const dayOfWeek = clickedDate.getDay();
                if (dayOfWeek === 0 || dayOfWeek === 6) {
                    alertify.error('Cannot request attendance for weekends.');
                    return;
                }
                
                // Check if it's a holiday
                const events = calendar.getEvents();
                const isHoliday = events.some(event => {
                    return event.extendedProps.type === 'holiday' && 
                           event.start.toDateString() === clickedDate.toDateString();
                });
                
                if (isHoliday) {
                    alertify.error('Cannot request attendance for holidays.');
                    return;
                }
                
                // Check if attendance is already marked or requested
                const hasEvent = events.some(event => {
                    return event.start.toDateString() === clickedDate.toDateString() && 
                           (event.extendedProps.status === 'present' || 
                            event.extendedProps.status === 'pending' || 
                            event.extendedProps.status === 'approved');
                });
                
                if (hasEvent) {
                    alertify.error('Attendance is already marked or requested for this date.');
                    return;
                }
                
                // Show attendance request form
                document.getElementById('requestDate').value = info.dateStr;
                document.getElementById('requestDateDisplay').textContent = formatDate(clickedDate);
                
                const requestModal = new bootstrap.Modal(document.getElementById('requestAttendanceModal'));
                requestModal.show();
            }
        }
    });
    
    calendar.render();
    
    // Handle attendance request form submission
    const requestForm = document.getElementById('attendanceRequestForm');
    if (requestForm) {
        requestForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(requestForm);
            
            fetch('request_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertify.success(data.message);
                    
                    // Close the modal
                    const requestModal = bootstrap.Modal.getInstance(document.getElementById('requestAttendanceModal'));
                    requestModal.hide();
                    
                    // Refresh the calendar
                    calendar.refetchEvents();
                    
                    // Reset the form
                    requestForm.reset();
                } else {
                    alertify.error(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alertify.error('An error occurred. Please try again.');
            });
        });
    }
});

// Format date (YYYY-MM-DD to DD Mon YYYY)
function formatDate(date) {
    if (!date) return '';
    
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(date).toLocaleDateString('en-US', options);
}

// Format date and time
function formatDateTime(dateTime) {
    if (!dateTime) return '';
    
    const options = { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return new Date(dateTime).toLocaleString('en-US', options);
}

// Cancel attendance request
function cancelRequest(requestId) {
    if (confirm('Are you sure you want to cancel this attendance request?')) {
        fetch('cancel_request.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'request_id=' + requestId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alertify.success(data.message);
                
                // Close the modal
                const eventModal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                eventModal.hide();
                
                // Refresh the calendar
                const calendar = document.querySelector('.fc').FullCalendar;
                calendar.refetchEvents();
            } else {
                alertify.error(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alertify.error('An error occurred. Please try again.');
        });
    }
}

