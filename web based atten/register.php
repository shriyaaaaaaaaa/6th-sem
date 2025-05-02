<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is already logged in
if (isLoggedIn()) {
    // Redirect based on user role
    redirectBasedOnRole();
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';
    $roll_number = $_POST['roll_number'] ?? '';
    $semester = $_POST['semester'] ?? '';
    
    // For teachers
    $selected_semesters = $_POST['selected_semesters'] ?? [];
    $selected_subjects = $_POST['selected_subjects'] ?? [];

    // Validate inputs
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } elseif ($role === 'student' && (empty($roll_number) || empty($semester))) {
        $error = 'Roll number and semester are required for students';
    } elseif ($role === 'teacher' && (empty($selected_semesters) || empty($selected_subjects))) {
        $error = 'Please select at least one semester and subject';
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Email already exists';
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Insert user - FIXED: Removed status column if it doesn't exist
                $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
                $phone = $_POST['phone'] ?? ''; // Get phone number from form
                $stmt->bind_param("sssss", $name, $email, $phone, $hashed_password, $role);
                $stmt->execute();
                
                $user_id = $conn->insert_id;
                
                // Insert additional details based on role
                if ($role === 'student') {
                    // Update the user record with student-specific fields
                    $stmt = $conn->prepare("UPDATE users SET roll_no = ?, semester = ? WHERE id = ?");
                    $stmt->bind_param("sii", $roll_number, $semester, $user_id);
                    $stmt->execute();
                } elseif ($role === 'teacher') {
                    // Insert teacher's semester and subject preferences
                    $stmt = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
                    
                    foreach ($selected_subjects as $subj_id) {
                        $stmt->bind_param("ii", $user_id, $subj_id);
                        $stmt->execute();
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                $success = 'Registration successful! You can now login.';
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}

// Get all semesters and subjects for teacher registration
$semesters = [];
$subjects = [];

$semester_query = "SELECT id, name FROM semesters ORDER BY name";
$semester_result = $conn->query($semester_query);
if ($semester_result && $semester_result->num_rows > 0) {
    while ($row = $semester_result->fetch_assoc()) {
        $semesters[] = $row;
    }
}

$subject_query = "SELECT id, name, code FROM subjects ORDER BY name";
$subject_result = $conn->query($subject_query);
if ($subject_result && $subject_result->num_rows > 0) {
    while ($row = $subject_result->fetch_assoc()) {
        $subjects[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - BCA Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">Register</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="role" class="form-label">Role</label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="student">Student</option>
                                    <option value="teacher">Teacher</option>
                                </select>
                            </div>
                            
                            <!-- Student-specific fields -->
                            <div id="student-fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="roll_number" class="form-label">Roll Number</label>
                                    <input type="text" class="form-control" id="roll_number" name="roll_number">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="semester" class="form-label">Semester</label>
                                    <select class="form-select" id="semester" name="semester">
                                        <option value="">Select Semester</option>
                                        <?php foreach ($semesters as $sem): ?>
                                            <option value="<?php echo $sem['id']; ?>"><?php echo $sem['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Teacher-specific fields -->
                            <div id="teacher-fields" style="display: none;">
                                <div class="mb-3">
                                    <label for="selected_semesters" class="form-label">Semesters You Teach</label>
                                    <select class="form-select select2-multi" id="selected_semesters" name="selected_semesters[]" multiple>
                                        <?php foreach ($semesters as $sem): ?>
                                            <option value="<?php echo $sem['id']; ?>"><?php echo $sem['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">You can select multiple semesters</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="selected_subjects" class="form-label">Subjects You Teach</label>
                                    <select class="form-select select2-multi" id="selected_subjects" name="selected_subjects[]" multiple>
                                        <?php foreach ($subjects as $subj): ?>
                                            <option value="<?php echo $subj['id']; ?>"><?php echo $subj['name']; ?> (<?php echo $subj['code']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">You can select multiple subjects</small>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger">Register</button>
                            </div>
                        </form>
                        
                        <div class="mt-3 text-center">
                            <p>Already have an account? <a href="index.php">Login</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2-multi').select2({
                width: '100%',
                placeholder: 'Select options'
            });
            
            // Toggle fields based on role selection
            $('#role').change(function() {
                const role = $(this).val();
                
                if (role === 'student') {
                    $('#student-fields').show();
                    $('#teacher-fields').hide();
                } else if (role === 'teacher') {
                    $('#student-fields').hide();
                    $('#teacher-fields').show();
                } else {
                    $('#student-fields').hide();
                    $('#teacher-fields').hide();
                }
            });
        });
    </script>
</body>
</html>

