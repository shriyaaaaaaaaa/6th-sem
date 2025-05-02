-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 19, 2025 at 06:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bca_attendance`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`id`, `user_id`, `description`, `created_at`) VALUES
(1, 46, 'login', '2025-03-23 04:32:10'),
(2, 46, 'login', '2025-03-23 04:32:17'),
(3, 46, 'login', '2025-03-23 04:32:26'),
(4, 18, 'login', '2025-03-23 04:33:02'),
(5, 18, 'login', '2025-03-23 04:33:13'),
(6, 1, '46', '2025-03-23 04:33:45'),
(7, 1, '18', '2025-03-23 04:34:06'),
(8, 1, '52', '2025-03-23 04:34:15'),
(9, 1, '46', '2025-03-25 08:32:25'),
(10, 1, '46', '2025-03-25 08:40:47'),
(11, 1, '46', '2025-03-25 08:41:04'),
(12, 1, '46', '2025-03-25 08:52:08'),
(13, 1, '46', '2025-03-25 09:09:08'),
(14, 1, '46', '2025-03-25 09:09:16'),
(15, 1, '46', '2025-03-26 06:37:11'),
(16, 1, '46', '2025-03-26 06:37:20'),
(17, 1, '46', '2025-03-26 06:38:51'),
(18, 46, 'otp_generated', '2025-03-26 06:40:28'),
(19, 1, '46', '2025-03-26 06:52:08'),
(20, 46, 'Generated OTP', '2025-03-26 06:54:53'),
(21, 46, 'Generated OTP', '2025-03-26 06:55:04'),
(22, 46, 'Generated OTP', '2025-03-28 10:59:17'),
(23, 46, 'Generated OTP', '2025-03-28 11:18:53'),
(24, 46, 'Generated OTP', '2025-03-28 11:19:09'),
(25, 46, 'Generated OTP', '2025-03-28 11:24:53'),
(26, 46, 'Generated OTP', '2025-03-29 00:09:27'),
(27, 46, 'Generated OTP', '2025-03-29 00:31:53'),
(28, 46, 'Generated OTP', '2025-03-29 00:32:11'),
(29, 46, 'Generated OTP', '2025-03-29 00:32:30');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'absent',
  `date` date NOT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject` varchar(255) DEFAULT '',
  `marked_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `teacher_id`, `class_id`, `subject_id`, `status`, `date`, `marked_at`, `created_at`, `subject`, `marked_by`) VALUES
(36, 58, 59, 30, 72, 'present', '2025-04-06', '2025-04-06 11:36:52', '2025-04-06 10:07:06', '', NULL),
(37, 60, 61, 31, 95, 'present', '2025-04-06', '2025-04-06 10:11:43', '2025-04-06 10:11:43', '', NULL),
(38, 58, 61, 32, 68, 'present', '2025-04-06', '2025-04-06 10:11:53', '2025-04-06 10:11:53', '', NULL),
(40, 58, 59, 30, 72, 'present', '2025-04-12', '2025-04-12 05:34:34', '2025-04-12 04:49:28', '', NULL),
(42, 58, 59, 30, 72, 'present', '2025-04-13', '2025-04-13 09:49:39', '2025-04-13 08:27:28', '', NULL),
(45, 58, 59, 30, 72, 'present', '2025-04-14', '2025-04-14 03:07:53', '2025-04-14 03:07:53', '', NULL),
(46, 58, 18, NULL, NULL, 'present', '2025-04-09', '2025-04-19 02:35:08', '2025-04-19 02:35:08', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_requests`
--

CREATE TABLE `attendance_requests` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `latitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_requests`
--

INSERT INTO `attendance_requests` (`id`, `student_id`, `teacher_id`, `class_id`, `subject_id`, `date`, `reason`, `latitude`, `longitude`, `status`, `created_at`, `updated_at`) VALUES
(11, 58, 59, 30, 72, '2025-04-09', 'VHFTFGFGFT', 27.673055126294337, 85.32189603608245, 'approved', '2025-04-14 03:02:36', '2025-04-19 02:35:08'),
(12, 58, 59, 30, 72, '2025-04-03', 'dfdttfygugyugi', 27.6955136, 85.3049344, 'rejected', '2025-04-19 02:40:01', '2025-04-19 02:47:36'),
(13, 58, 59, 30, 72, '2025-04-18', 'fdghkjhldhfggh', 27.6955136, 85.3049344, 'pending', '2025-04-19 03:41:29', '2025-04-19 03:41:29');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `capacity` int(11) DEFAULT 60,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject_id` int(11) DEFAULT NULL,
  `semester` varchar(255) DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `name`, `semester_id`, `room_number`, `capacity`, `is_active`, `created_at`, `subject_id`, `semester`, `code`) VALUES
(30, 'CSC 103 Class', 1, NULL, 60, 1, '2025-04-06 10:06:54', 72, NULL, NULL),
(31, 'CSC 602 Class', 6, NULL, 60, 1, '2025-04-06 10:11:43', 95, NULL, 'CSC 602'),
(32, 'CSC 101 Class', 1, NULL, 60, 1, '2025-04-06 10:11:53', 68, NULL, 'CSC 101');

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` varchar(255) DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `holidays`
--

INSERT INTO `holidays` (`id`, `date`, `name`, `created_at`, `type`, `is_recurring`, `updated_at`) VALUES
(10, '2025-04-05', 'Saturday - April 5, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:00'),
(11, '2025-04-12', 'Saturday - April 12, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:18'),
(12, '2025-04-19', 'Saturday - April 19, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:22'),
(13, '2025-04-26', 'Saturday - April 26, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:28'),
(14, '2025-05-03', 'Saturday - May 3, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:35'),
(15, '2025-05-10', 'Saturday - May 10, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:39'),
(16, '2025-05-17', 'Saturday - May 17, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:44'),
(17, '2025-05-24', 'Saturday - May 24, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:48'),
(18, '2025-05-31', 'Saturday - May 31, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:17:51'),
(19, '2025-06-07', 'Saturday - June 7, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:18:09'),
(20, '2025-06-14', 'Saturday - June 14, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:18:18'),
(21, '2025-06-21', 'Saturday - June 21, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:18:14'),
(22, '2025-06-28', 'Saturday - June 28, 2025', '2025-04-06 10:24:38', 'regular', 0, '2025-04-13 09:18:23');

-- --------------------------------------------------------

--
-- Table structure for table `otp`
--

CREATE TABLE `otp` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `radius` int(11) NOT NULL DEFAULT 100,
  `expiry` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `duration` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `otp`
--

INSERT INTO `otp` (`id`, `teacher_id`, `class_id`, `subject_id`, `otp_code`, `latitude`, `longitude`, `radius`, `expiry`, `created_at`, `duration`) VALUES
(126, 59, 30, 72, '424993', 27.6692992, 85.3213184, 100, '2025-04-06 10:01:54', '2025-04-06 10:06:54', 10),
(127, 59, 30, 72, '673494', 22, 4, 1000, '2025-04-06 11:23:15', '2025-04-06 11:28:15', 10),
(128, 59, 30, 72, '928961', 27.7118976, 85.2819968, 100, '2025-04-07 17:28:41', '2025-04-07 17:33:41', 10),
(130, 59, 30, 72, '607831', 27.6900363, 85.334596, 100, '2025-04-08 04:02:17', '2025-04-08 03:57:17', 5),
(131, 59, 30, 72, '813080', 27.6692992, 85.3245952, 100, '2025-04-12 04:51:25', '2025-04-12 04:46:25', 5),
(132, 59, 30, 72, '632803', 27.67306, 85.3220576, 35, '2025-04-12 05:01:32', '2025-04-12 04:56:32', 5),
(133, 59, 30, 72, '555492', 27.6730615, 85.3220562, 66, '2025-04-12 05:12:47', '2025-04-12 05:07:47', 5),
(135, 59, 30, 72, '378862', 27.6730475, 85.3220465, 100, '2025-04-13 08:31:31', '2025-04-13 08:26:31', 5),
(137, 59, 30, 72, '682334', 27.673055, 85.3220541, 55, '2025-04-14 03:12:32', '2025-04-14 03:07:32', 5),
(138, 59, 30, 72, '224804', 27.6896472, 85.3349923, 43, '2025-04-18 01:56:11', '2025-04-18 01:51:11', 5);

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`id`, `name`, `is_active`, `created_at`) VALUES
(1, 'Semester 1', 1, '2025-03-15 03:38:53'),
(2, 'Semester 2', 1, '2025-03-15 03:38:53'),
(3, 'Semester 3', 1, '2025-03-15 03:38:53'),
(4, 'Semester 4', 1, '2025-03-15 03:38:53'),
(5, 'Semester 5', 1, '2025-03-15 03:38:53'),
(6, 'Semester 6', 1, '2025-03-15 03:38:53'),
(7, 'Semester 7', 1, '2025-03-15 03:38:53'),
(8, 'Semester 8', 1, '2025-03-15 03:38:53');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `distance_threshold` int(11) NOT NULL DEFAULT 100,
  `otp_validity_minutes` int(11) NOT NULL DEFAULT 15,
  `academic_year_start` date NOT NULL,
  `academic_year_end` date NOT NULL,
  `attendance_start_time` time NOT NULL DEFAULT '09:00:00',
  `attendance_end_time` time NOT NULL DEFAULT '17:00:00',
  `allow_manual_attendance` tinyint(1) NOT NULL DEFAULT 1,
  `allow_attendance_requests` tinyint(1) NOT NULL DEFAULT 1,
  `email_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `sms_notifications` tinyint(1) NOT NULL DEFAULT 0,
  `min_attendance_percentage` int(11) NOT NULL DEFAULT 75,
  `max_teachers` int(11) NOT NULL DEFAULT 20,
  `teacher_registration_code` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `distance_threshold`, `otp_validity_minutes`, `academic_year_start`, `academic_year_end`, `attendance_start_time`, `attendance_end_time`, `allow_manual_attendance`, `allow_attendance_requests`, `email_notifications`, `sms_notifications`, `min_attendance_percentage`, `max_teachers`, `teacher_registration_code`, `created_at`, `updated_at`) VALUES
(1, 10, 5, '2022-01-01', '2022-12-31', '08:00:00', '17:00:00', 1, 0, 0, 0, 80, 3, 'J4DOI87F', '2025-03-15 03:38:53', '2025-04-19 03:51:51');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `roll_number` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone_number` varchar(255) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `roll_number`, `name`, `email`, `phone_number`, `user_id`, `semester`, `semester_id`, `created_at`) VALUES
(1, 'BCA0401', '', '', '', 44, 9, NULL, '2025-03-20 05:45:57');

-- --------------------------------------------------------

--
-- Table structure for table `student_locations`
--

CREATE TABLE `student_locations` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `last_updated` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `credits` int(11) NOT NULL DEFAULT 4,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `code`, `name`, `semester_id`, `credits`, `is_active`, `created_at`) VALUES
(68, 'CSC 101', 'Computer Fundamentals and Applications', 1, 3, 1, '2025-04-06 09:59:20'),
(69, 'ENG 101', 'Society and Technology', 1, 3, 1, '2025-04-06 09:59:20'),
(70, 'ENG 102', 'English I', 1, 3, 1, '2025-04-06 09:59:20'),
(71, 'MTH 101', 'Mathematics I', 1, 3, 1, '2025-04-06 09:59:20'),
(72, 'CSC 103', 'Digital Logic', 1, 3, 1, '2025-04-06 09:59:20'),
(73, 'CSC 201', 'C Programming', 2, 3, 1, '2025-04-06 09:59:20'),
(74, 'ACC 201', 'Financial Accounting', 2, 3, 1, '2025-04-06 09:59:20'),
(75, 'ENG 201', 'English II', 2, 3, 1, '2025-04-06 09:59:20'),
(76, 'MTH 201', 'Mathematics II', 2, 3, 1, '2025-04-06 09:59:20'),
(77, 'CSC 202', 'Microprocessor and Computer Architecture', 2, 3, 1, '2025-04-06 09:59:20'),
(78, 'CSC 301', 'Data Structures and Algorithms', 3, 3, 1, '2025-04-06 09:59:20'),
(79, 'STA 301', 'Probability and Statistics', 3, 3, 1, '2025-04-06 09:59:20'),
(80, 'CSC 302', 'System Analysis and Design', 3, 3, 1, '2025-04-06 09:59:20'),
(81, 'CSC 303', 'Object-Oriented Programming in Java', 3, 3, 1, '2025-04-06 09:59:20'),
(82, 'CSC 304', 'Web Technology', 3, 3, 1, '2025-04-06 09:59:20'),
(83, 'CSC 401', 'Operating System', 4, 3, 1, '2025-04-06 09:59:20'),
(84, 'MTH 301', 'Numerical Methods', 4, 3, 1, '2025-04-06 09:59:20'),
(85, 'CSC 402', 'Software Engineering', 4, 3, 1, '2025-04-06 09:59:20'),
(86, 'CSC 403', 'Scripting Language', 4, 3, 1, '2025-04-06 09:59:20'),
(87, 'CSC 404', 'Database Management System', 4, 3, 1, '2025-04-06 09:59:20'),
(88, 'CSC 405', 'Project I', 4, 3, 1, '2025-04-06 09:59:20'),
(89, 'CSC 501', 'Management Information Systems and E-Business', 5, 3, 1, '2025-04-06 09:59:20'),
(90, 'CSC 502', 'DotNet Technology', 5, 3, 1, '2025-04-06 09:59:20'),
(91, 'CSC 503', 'Computer Networking', 5, 3, 1, '2025-04-06 09:59:20'),
(92, 'MGT 501', 'Introduction to Management', 5, 3, 1, '2025-04-06 09:59:20'),
(93, 'CSC 504', 'Computer Graphics and Animation', 5, 3, 1, '2025-04-06 09:59:20'),
(94, 'CSC 601', 'Mobile Programming', 6, 3, 1, '2025-04-06 09:59:20'),
(95, 'CSC 602', 'Distributed System', 6, 3, 1, '2025-04-06 09:59:20'),
(96, 'ECO 601', 'Applied Economics', 6, 3, 1, '2025-04-06 09:59:20'),
(97, 'CSC 603', 'Advanced Java Programming', 6, 3, 1, '2025-04-06 09:59:20'),
(98, 'CSC 604', 'Network Programming', 6, 3, 1, '2025-04-06 09:59:20'),
(99, 'CSC 605', 'Project II', 6, 3, 1, '2025-04-06 09:59:20'),
(100, 'CSC 701', 'Cyber Law and Professional Ethics', 7, 3, 1, '2025-04-06 09:59:20'),
(101, 'CSC 702', 'Cloud Computing', 7, 3, 1, '2025-04-06 09:59:20'),
(102, 'CSC 703', 'Internship', 7, 3, 1, '2025-04-06 09:59:20'),
(103, 'ELE 701', 'Elective I', 7, 3, 1, '2025-04-06 09:59:20'),
(104, 'ELE 702', 'Elective II', 7, 3, 1, '2025-04-06 09:59:20'),
(105, 'CSC 801', 'Operations Research', 8, 3, 1, '2025-04-06 09:59:20'),
(106, 'CSC 802', 'Project III', 8, 3, 1, '2025-04-06 09:59:20'),
(107, 'ELE 801', 'Elective III', 8, 3, 1, '2025-04-06 09:59:20'),
(108, 'ELE 802', 'Elective IV', 8, 3, 1, '2025-04-06 09:59:20');

-- --------------------------------------------------------

--
-- Table structure for table `teacher_classes`
--

CREATE TABLE `teacher_classes` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `semester` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teacher_classes`
--

INSERT INTO `teacher_classes` (`id`, `teacher_id`, `class_id`, `subject_id`, `created_at`, `semester`) VALUES
(18, 59, 30, 72, '2025-04-06 10:06:54', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `teacher_subjects`
--

CREATE TABLE `teacher_subjects` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teacher_subjects`
--

INSERT INTO `teacher_subjects` (`id`, `teacher_id`, `subject_id`, `created_at`) VALUES
(73, 61, 68, '2025-04-06 10:11:02'),
(74, 61, 95, '2025-04-06 10:11:02'),
(75, 59, 72, '2025-04-13 08:43:50'),
(76, 63, 68, '2025-04-16 01:48:57');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','teacher','student') NOT NULL,
  `status` enum('pending','active','inactive') NOT NULL DEFAULT 'active',
  `roll_no` varchar(20) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `parent_email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `device_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password`, `role`, `status`, `roll_no`, `semester`, `parent_email`, `created_at`, `updated_at`, `device_token`) VALUES
(18, 'Rajesh', 'qwe@gmail.com', '9804678456', '$2y$10$rZosEl3US2gjACO7BC3LceTbjthJhtc/ui.xiXWFo6hCp2EULosaS', 'admin', 'active', NULL, NULL, NULL, '2025-03-15 06:31:52', '2025-03-19 06:20:09', NULL),
(58, 'Rajesh Koirala', 'rajesh123@gmail.com', '9807654320', '$2y$10$1hLyoCxchRb/bdxUXiAOG.0q2iB06Rbw6vYMsy0ExMMs1BJINa5aS', 'student', 'active', 'BCA0123', 1, '', '2025-04-06 10:03:51', '2025-04-19 01:38:28', NULL),
(59, 'TeacherRajesh', 'Rteacher@gmail.com', '9807654321', '$2y$10$gvl81wPzuEsOBuImvyFkq.vKlTEpIzXBpJGSPAvvDIQeCbUhmQDba', 'teacher', 'active', NULL, NULL, NULL, '2025-04-06 10:05:52', '2025-04-06 10:05:52', NULL),
(60, 'Roshan Joshi', 'Roshan@gmail.com', '9807654322', '$2y$10$Ir0yuT4WmDMIN6zF4Jig.uvmBd6c58QSksYRb6AE51zi/aPIoqH/C', 'student', 'active', 'BCA0201', 6, NULL, '2025-04-06 10:09:11', '2025-04-06 10:09:11', NULL),
(61, 'TeacherRoshan', 'Roshant@gmail.com', '9807654325', '$2y$10$IYeZLmIUBcP6d.wkunV1eevXFMcxt/cZZaH999mzAoqz6rTo3Nvom', 'teacher', 'active', NULL, NULL, NULL, '2025-04-06 10:11:02', '2025-04-06 10:11:02', NULL),
(62, 'safal', 'safal@gmail.com', '9812586255', '$2y$10$UuaHo0bqBzR4cAEHGK5svuM6z1weSpFHfTozyVL5SxVirnNvcrZV.', 'student', 'active', '212', 7, NULL, '2025-04-16 01:44:27', '2025-04-16 01:44:27', NULL),
(63, 'Biswant', 'yourname@gmail.com', '9812586255', '$2y$10$iaZslCugnoGTYbeQCe12pecF97SNZMelJQpUgVtoix8nR8r6RJcpe', 'teacher', 'active', NULL, NULL, NULL, '2025-04-16 01:48:57', '2025-04-16 01:48:57', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`,`class_id`,`subject_id`,`date`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `attendance_requests`
--
ALTER TABLE `attendance_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`,`class_id`,`subject_id`,`date`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `fk_attendance_requests_teacher` (`teacher_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`,`semester_id`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`);

--
-- Indexes for table `otp`
--
ALTER TABLE `otp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_locations`
--
ALTER TABLE `student_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `teacher_id` (`teacher_id`,`class_id`,`subject_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `teacher_id` (`teacher_id`,`subject_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `attendance_requests`
--
ALTER TABLE `attendance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `otp`
--
ALTER TABLE `otp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_locations`
--
ALTER TABLE `student_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `attendance_ibfk_4` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance_requests`
--
ALTER TABLE `attendance_requests`
  ADD CONSTRAINT `attendance_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_requests_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_requests_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_requests_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otp`
--
ALTER TABLE `otp`
  ADD CONSTRAINT `otp_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `otp_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `otp_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_locations`
--
ALTER TABLE `student_locations`
  ADD CONSTRAINT `student_locations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  ADD CONSTRAINT `teacher_classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teacher_classes_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teacher_classes_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teacher_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
