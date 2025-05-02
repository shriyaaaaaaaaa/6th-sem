<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: admin/dashboard.php");
            break;
        case 'teacher':
            header("Location: teacher/dashboard.php");
            break;
        case 'student':
            header("Location: student/dashboard.php");
            break;
        default:
            // Do nothing, stay on login page
            break;
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BCA Attendance System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
  <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
  <div class="container">
      <div class="row justify-content-center mt-5">
          <div class="col-md-6">
              <div class="card border-danger shadow">
                  <div class="card-header bg-danger text-white">
                      <h3 class="text-center mb-0">BCA Attendance System</h3>
                  </div>
                  <div class="card-body">
                      <form id="loginForm" method="POST" action="includes/auth.php">
                          <div class="mb-3">
                              <label for="email" class="form-label">Email address</label>
                              <input type="email" class="form-control" id="email" name="email" placeholder="Enter email" required>
                          </div>
                          <div class="mb-3">
                              <label for="password" class="form-label">Password</label>
                              <div class="input-group">
                                  <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                                  <span class="input-group-text" id="togglePassword" style="cursor: pointer;">
                                      <i class="fas fa-eye-slash" id="toggleIcon"></i>
                                  </span>
                              </div>
                          </div>
                          <div class="mb-3">
                              <select class="form-select" name="role" required >
                                  <option value="" disabled>Select Role</option>
                                  <option value="admin" >Admin</option>
                                  <option value="teacher">Teacher</option>
                                  <option value="student" selected>Student</option>
                                  
                              </select>
                          </div>
                          <div class="d-grid gap-2">
                              <button type="submit" name="login" class="btn btn-danger">Login</button>
                          </div>
                      </form>
                      <div class="text-center mt-3">
                          <a href="register.php" class="text-danger">Register</a>
                      </div>
                  </div>
              </div>
              
              <!-- Connection Status -->
              <div class="mt-3">
                  <?php if (isset($conn) && $conn): ?>
                      <!-- <div class="alert alert-success">
                          <i class="fas fa-check-circle me-2"></i> 
                          Database connection established successfully.
                      </div> -->
                  <?php else: ?>
                      <div class="alert alert-danger">
                          <i class="fas fa-exclamation-circle me-2"></i> Database connection failed. Please check your configuration.
                      </div>
                  <?php endif; ?>
              </div>
          </div>
      </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
  <script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script>
    // Show/hide password functionality
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        }
    });
  </script>
  <?php
  if (isset($_SESSION['message'])) {
      echo "<script>
          alertify.notify('" . $_SESSION['message'] . "', '" . $_SESSION['message_type'] . "', 5);
      </script>";
      unset($_SESSION['message']);
      unset($_SESSION['message_type']);
  }
  ?>
</body>
</html>