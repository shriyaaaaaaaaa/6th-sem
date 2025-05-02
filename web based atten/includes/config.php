<?php
// Database connection settings
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'bca_attendance');

// Improved database connection with error handling
try {
    // Create connection
    $conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD);
    
    // Check connection
    if (!$conn) {
        throw new Exception("Connection failed: " . mysqli_connect_error());
    }
    
    // Check if database exists, if not create it
    $db_check = mysqli_query($conn, "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . DB_NAME . "'");
    if (mysqli_num_rows($db_check) == 0) {
        // Database doesn't exist, create it
        $sql = "CREATE DATABASE " . DB_NAME;
        if (mysqli_query($conn, $sql)) {
            echo "<script>console.log('Database created successfully');</script>";
        } else {
            throw new Exception("Error creating database: " . mysqli_error($conn));
        }
    }
    
    // Select the database
    if (!mysqli_select_db($conn, DB_NAME)) {
        throw new Exception("Error selecting database: " . mysqli_error($conn));
    }
    
    // Check if tables exist, if not create them
    createTablesIfNotExist($conn);
    
    // Set character set
    mysqli_set_charset($conn, "utf8mb4");
    
    // Default timezone
    date_default_timezone_set('Asia/Kolkata');
    
    // Configuration settings
    define('SITE_NAME', 'BCA Attendance System');
    define('OTP_VALIDITY', 300); // OTP validity in seconds (5 minutes)
    define('DEFAULT_RADIUS', 100); // Default radius in meters
    
} catch (Exception $e) {
    // Display error message
    die("<div style='color:red; font-family:Arial; padding:20px; margin:20px; border:1px solid red; border-radius:5px;'>
        <h2>Database Connection Error</h2>
        <p>" . $e->getMessage() . "</p>
        <h3>Troubleshooting Steps:</h3>
        <ol>
            <li>Make sure MySQL server is running</li>
            <li>Check if username and password are correct</li>
            <li>Verify that the host/server name is correct</li>
            <li>Ensure you have permissions to create/access the database</li>
        </ol>
        <p>Please fix these issues and refresh the page.</p>
    </div>");
}

// Function to create tables if they don't exist
function createTablesIfNotExist($conn) {
    // Users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        phone VARCHAR(15) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'teacher', 'student') NOT NULL,
        roll_no VARCHAR(20) DEFAULT NULL,
        semester INT DEFAULT NULL,
        parent_email VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating users table: " . mysqli_error($conn));
    }
    
    // Attendance table
    $sql = "CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        teacher_id INT DEFAULT NULL,
        status ENUM('present', 'absent') NOT NULL,
        date TIMESTAMP NOT NULL,
        marked_at TIMESTAMP NOT NULL,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY (student_id, date)
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating attendance table: " . mysqli_error($conn));
    }
    
    // OTP table
    $sql = "CREATE TABLE IF NOT EXISTS otp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        latitude DOUBLE NOT NULL,
        longitude DOUBLE NOT NULL,
        radius INT NOT NULL DEFAULT 100,
        expiry TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating otp table: " . mysqli_error($conn));
    }
    
    // Attendance requests table
    $sql = "CREATE TABLE IF NOT EXISTS attendance_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        date DATE NOT NULL,
        reason TEXT,
        latitude DOUBLE,
        longitude DOUBLE,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY (student_id, date)
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating attendance_requests table: " . mysqli_error($conn));
    }
    
    // Holidays table
    $sql = "CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating holidays table: " . mysqli_error($conn));
    }
    
    // Settings table
    $sql = "CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        distance_threshold INT NOT NULL DEFAULT 100,
        otp_validity_minutes INT NOT NULL DEFAULT 15,
        academic_year_start DATE NOT NULL,
        academic_year_end DATE NOT NULL,
        attendance_start_time TIME NOT NULL DEFAULT '09:00:00',
        attendance_end_time TIME NOT NULL DEFAULT '17:00:00',
        allow_manual_attendance TINYINT(1) NOT NULL DEFAULT 1,
        allow_attendance_requests TINYINT(1) NOT NULL DEFAULT 1,
        email_notifications TINYINT(1) NOT NULL DEFAULT 1,
        sms_notifications TINYINT(1) NOT NULL DEFAULT 0,
        min_attendance_percentage INT NOT NULL DEFAULT 75,
        max_teachers INT NOT NULL DEFAULT 20,
        teacher_registration_code VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating settings table: " . mysqli_error($conn));
    }
    
    // Teacher-Class relationship table
    $sql = "CREATE TABLE IF NOT EXISTS teacher_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        semester INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY (teacher_id, semester)
    )";
    
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Error creating teacher_classes table: " . mysqli_error($conn));
    }
    
    // Check if admin exists, if not create default admin
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
    $row = mysqli_fetch_assoc($result);
    
    if ($row['count'] == 0) {
        // Insert default admin (password: admin123)
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, email, phone, password, role) 
                VALUES ('Admin', 'admin@example.com', '1234567890', '$password', 'admin')";
        
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Error creating default admin: " . mysqli_error($conn));
        }
    }
    
    // Check if settings exist, if not create default settings
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM settings");
    $row = mysqli_fetch_assoc($result);
    
    if ($row['count'] == 0) {
        // Generate a random teacher registration code
        $teacher_code = generateRandomCode(8);
        
        // Insert default settings
        $sql = "INSERT INTO settings (
            distance_threshold, 
            otp_validity_minutes, 
            academic_year_start, 
            academic_year_end,
            attendance_start_time,
            attendance_end_time,
            allow_manual_attendance,
            allow_attendance_requests,
            email_notifications,
            sms_notifications,
            min_attendance_percentage,
            max_teachers,
            teacher_registration_code
        ) VALUES (
            100, 
            15, 
            '" . date('Y') . "-06-01', 
            '" . (date('Y') + 1) . "-05-31',
            '09:00:00',
            '17:00:00',
            1,
            1,
            1,
            0,
            75,
            20,
            '$teacher_code'
        )";
        
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Error creating default settings: " . mysqli_error($conn));
        }
    }
    
    // Insert sample holidays if none exist
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM holidays");
    $row = mysqli_fetch_assoc($result);
    
    if ($row['count'] == 0) {
        $holidays = [
            ['2023-01-26', 'Republic Day'],
            ['2023-08-15', 'Independence Day'],
            ['2023-10-02', 'Gandhi Jayanti'],
            ['2023-12-25', 'Christmas']
        ];
        
        foreach ($holidays as $holiday) {
            $sql = "INSERT INTO holidays (date, name) VALUES ('{$holiday[0]}', '{$holiday[1]}')";
            mysqli_query($conn, $sql);
        }
    }
}

// Function to generate a random code
if (!function_exists('generateRandomCode')) {
    function generateRandomCode($length = 8) {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }
}

