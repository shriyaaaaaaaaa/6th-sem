<?php
session_start();
require_once 'config.php';

// Handle logout
if (isset($_GET['logout'])) {
  // Unset all session variables
  $_SESSION = array();
  
  // Destroy the session
  session_destroy();
  
  // Set success message
  session_start();
  $_SESSION['message'] = "You have been logged out successfully.";
  $_SESSION['message_type'] = "success";
  
  // Redirect to login page
  header("Location: ../index.php");
  exit();
}

// Handle login
if (isset($_POST['login'])) {
  $email = $_POST['email'];
  $password = $_POST['password'];
  $role = $_POST['role'];
  
  // Validate inputs
  if (empty($email) || empty($password) || empty($role)) {
      $_SESSION['message'] = "All fields are required.";
      $_SESSION['message_type'] = "error";
      header("Location: ../index.php");
      exit();
  }
  
  // Prepare and execute the SQL statement
  $stmt = $conn->prepare("SELECT id, name, email, password, role, status FROM users WHERE email = ? AND role = ?");
  $stmt->bind_param("ss", $email, $role);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result->num_rows === 1) {
      $user = $result->fetch_assoc();
      
      // Removed the teacher approval check
      // Now teachers can log in regardless of their status
      
      // Verify the password
      if (password_verify($password, $user['password'])) {
          // Password is correct, create session
          $_SESSION['user_id'] = $user['id'];
          $_SESSION['name'] = $user['name'];
          $_SESSION['email'] = $user['email'];
          $_SESSION['role'] = $user['role'];
          
          // Set success message
          $_SESSION['message'] = "Login successful. Welcome, " . $user['name'] . "!";
          $_SESSION['message_type'] = "success";
          
          // Redirect based on role
          switch ($user['role']) {
              case 'admin':
                  header("Location: ../admin/dashboard.php");
                  break;
              case 'teacher':
                  header("Location: ../teacher/dashboard.php");
                  break;
              case 'student':
                  header("Location: ../student/dashboard.php");
                  break;
              default:
                  header("Location: ../index.php");
                  break;
          }
          exit();
      } else {
          $_SESSION['message'] = "Invalid password.";
          $_SESSION['message_type'] = "error";
          header("Location: ../index.php");
          exit();
      }
  } else {
      $_SESSION['message'] = "User not found with the provided email and role.";
      $_SESSION['message_type'] = "error";
      header("Location: ../index.php");
      exit();
  }
}

// Handle registration
if (isset($_POST['register'])) {
  $name = $_POST['name'];
  $email = $_POST['email'];
  $phone = $_POST['phone'] ?? '';
  $password = $_POST['password'];
  $confirm_password = $_POST['confirm_password'];
  $role = $_POST['role'];
  $roll_no = $_POST['roll_no'] ?? null;
  $semester = $_POST['semester'] ?? null;
  $parent_email = $_POST['parent_email'] ?? null;
  
  // For teacher role
  $teacher_code = $_POST['teacher_code'] ?? '';
  $classes = isset($_POST['classes']) ? $_POST['classes'] : [];
  
  // Validate inputs
  if (empty($name) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
      $_SESSION['message'] = "All fields are required.";
      $_SESSION['message_type'] = "error";
      header("Location: ../register.php");
      exit();
  }
  
  // Validate password match
  if ($password !== $confirm_password) {
      $_SESSION['message'] = "Passwords do not match.";
      $_SESSION['message_type'] = "error";
      header("Location: ../register.php");
      exit();
  }
  
  // Validate student-specific fields
  if ($role === 'student' && (empty($roll_no) || empty($semester) || empty($parent_email))) {
      $_SESSION['message'] = "Roll number, semester, and parent email are required for student registration.";
      $_SESSION['message_type'] = "error";
      header("Location: ../register.php");
      exit();
  }
  
  // Validate teacher-specific fields
  if ($role === 'teacher') {
      if (empty($teacher_code)) {
          $_SESSION['message'] = "Teacher registration code is required.";
          $_SESSION['message_type'] = "error";
          header("Location: ../register.php");
          exit();
      }
      
      // Verify teacher registration code
      if (!verifyTeacherCode($teacher_code)) {
          $_SESSION['message'] = "Invalid teacher registration code.";
          $_SESSION['message_type'] = "error";
          header("Location: ../register.php");
          exit();
      }
      
      if (empty($classes)) {
          $_SESSION['message'] = "Please select at least one class.";
          $_SESSION['message_type'] = "error";
          header("Location: ../register.php");
          exit();
      }
  }
  
  // Register the user
  $result = registerUser($name, $email, $phone, $password, $role, $roll_no, $semester, $parent_email, $classes);
  
  if ($result['success']) {
      $_SESSION['message'] = $result['message'];
      $_SESSION['message_type'] = "success";
      header("Location: ../index.php");
      exit();
  } else {
      $_SESSION['message'] = $result['message'];
      $_SESSION['message_type'] = "error";
      header("Location: ../register.php");
      exit();
  }
}

// Function to register a new user
function registerUser($name, $email, $phone, $password, $role, $roll_no = null, $semester = null, $parent_email = null, $classes = []) {
  global $conn;
  
  // Check if email already exists
  $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result->num_rows > 0) {
      return ["success" => false, "message" => "Email already exists. Please use a different email."];
  }
  
  // For teacher role, check if max teachers limit is reached
  if ($role === 'teacher') {
      // Get current teacher count
      $teacherCount = getTeacherCount();
      
      // Get max teachers setting
      $settingsQuery = "SELECT max_teachers FROM settings LIMIT 1";
      $settingsResult = mysqli_query($conn, $settingsQuery);
      $settings = mysqli_fetch_assoc($settingsResult);
      $maxTeachers = $settings['max_teachers'];
      
      if ($teacherCount >= $maxTeachers) {
          return ["success" => false, "message" => "Maximum number of teachers ($maxTeachers) already registered. Please contact the administrator."];
      }
  }
  
  // Hash the password
  $hashed_password = password_hash($password, PASSWORD_DEFAULT);
  
  // Set status to approved for all users (removed approval requirement for teachers)
  $status = 'approved';
  
  // Prepare and execute the SQL statement
  $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("ssssss", $name, $email, $phone, $hashed_password, $role, $status);
  
  if ($stmt->execute()) {
      $user_id = $stmt->insert_id;
      
      // If student role, add to students table
      if ($role === 'student') {
          $stmt = $conn->prepare("INSERT INTO students (user_id, roll_number, semester, parent_email) VALUES (?, ?, ?, ?)");
          $stmt->bind_param("isss", $user_id, $roll_no, $semester, $parent_email);
          $stmt->execute();
      }
      
      // If teacher role and classes are provided, add them to teacher_classes table
      if ($role === 'teacher' && !empty($classes)) {
          foreach ($classes as $class) {
              $stmt = $conn->prepare("INSERT INTO teacher_classes (teacher_id, class_id) VALUES (?, ?)");
              $stmt->bind_param("ii", $user_id, $class);
              $stmt->execute();
          }
      }
      
      return ["success" => true, "message" => "Registration successful. You can now login.", "user_id" => $user_id];
  } else {
      return ["success" => false, "message" => "Registration failed: " . $stmt->error];
  }
}

// Function to verify teacher registration code
function verifyTeacherCode($code) {
  global $conn;
  
  // Get the teacher registration code from settings
  $query = "SELECT teacher_registration_code FROM settings LIMIT 1";
  $result = mysqli_query($conn, $query);
  
  if ($result && mysqli_num_rows($result) > 0) {
      $settings = mysqli_fetch_assoc($result);
      $validCode = $settings['teacher_registration_code'];
      
      return $code === $validCode;
  }
  
  return false;
}

// Function to get the current number of registered teachers
function getTeacherCount() {
  global $conn;
  
  $query = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher'";
  $result = mysqli_query($conn, $query);
  
  if ($result && mysqli_num_rows($result) > 0) {
      $row = mysqli_fetch_assoc($result);
      return $row['count'];
  }
  
  return 0;
}
?>

