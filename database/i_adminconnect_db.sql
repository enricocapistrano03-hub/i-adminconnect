-- phpMyAdmin SQL Dump
-- Database: `i_adminconnect_db`
-- Project: i-AdminConnect (A Web-Based Administrative Services and Records Tracking System)
-- Authors: Enrico C. Capistrano, Edwin E. Cruz, Redje L. Manlapaz, Erginia Fatima G. Diansuy, Junver M. Santos

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";

CREATE DATABASE IF NOT EXISTS `i_adminconnect_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `i_adminconnect_db`;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `role` enum('student','teacher','admin') NOT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `status` enum('Active','Inactive','Dropped') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Users (Passwords are 'password123' or plain for easy capstone demo)
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `id_number`, `department`, `section`, `year_level`, `course`, `status`) VALUES
(1, 'maria.reyes', 'password123', 'Maria Reyes', 'maria.reyes@school.edu.ph', 'teacher', 'T-2022-0194', 'Faculty of Science & Tech', NULL, NULL, NULL, 'Active'),
(2, 'juan.delacruz', 'password123', 'Juan Dela Cruz', 'juan.delacruz@student.edu.ph', 'student', 'S-2023-0041', 'BS Computer Science', 'BSCS 3-A', '3rd Year', 'BS Computer Science', 'Active'),
(3, 'carlo.bautista', 'password123', 'Carlo Bautista', 'carlo.bautista@school.edu.ph', 'teacher', 'T-2020-0082', 'College of Education', NULL, NULL, NULL, 'Active'),
(4, 'sunshine.morales', 'password123', 'Sunshine Morales', 'sunshine.morales@student.edu.ph', 'student', 'S-2024-0012', 'BS Information Technology', 'BSIT 2-B', '2nd Year', 'BS Information Technology', 'Active'),
(5, 'admin', 'admin123', 'System Administrator', 'admin@adminconnect.edu.ph', 'admin', 'ADM-001', 'Office of the Registrar', NULL, NULL, NULL, 'Active');

-- --------------------------------------------------------
-- Table structure for table `request_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `request_types`;
CREATE TABLE `request_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `target_role` enum('student','teacher','both') NOT NULL,
  `requirements` text NOT NULL,
  `fee` decimal(10,2) DEFAULT 0.00,
  `is_free` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `request_types` (`id`, `code`, `name`, `target_role`, `requirements`, `fee`, `is_free`) VALUES
(1, 'COE', 'Certificate of Employment (COE)', 'teacher', '["Valid Government or Employee ID", "Request letter from Department Head", "Service record (if applicable)"]', 0.00, 1),
(2, 'LC', 'Leave Credits', 'teacher', '["Approved Leave Application Form", "Valid Faculty ID"]', 0.00, 1),
(3, 'SR', 'Service Record', 'teacher', '["Appointment papers", "Valid Government ID"]', 0.00, 1),
(4, 'CR', 'Certificate of Rating', 'teacher', '["Faculty Evaluation Summary", "Valid School ID"]', 0.00, 1),
(5, 'F137', 'Form 137 (Transcript of Records)', 'student', '["Valid School ID or Government-issued ID", "Request letter addressed to the Registrar"]', 0.00, 1),
(6, 'COG', 'Certificate of Grades', 'student', '["Previous Semester Grade Slip", "Valid School ID"]', 0.00, 1),
(7, 'COE_ST', 'Certificate of Enrollment', 'student', '["Current Semester Registration Card (COR)", "Valid School ID"]', 0.00, 1),
(8, 'GMC', 'Good Moral Certificate', 'student', '["Clearance Form from Guidance Office", "Valid School ID"]', 0.00, 1);

-- --------------------------------------------------------
-- Table structure for table `requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `request_type_id` int(11) NOT NULL,
  `purpose` text NOT NULL,
  `additional_notes` text DEFAULT NULL,
  `attached_filename` varchar(255) DEFAULT NULL,
  `file_size_kb` varchar(50) DEFAULT NULL,
  `file_verified` tinyint(1) DEFAULT 1,
  `status` enum('Pending','Under Review','Processing','Ready for Release','Released','Returned for Revision','Rejected') NOT NULL DEFAULT 'Pending',
  `current_stage` int(11) NOT NULL DEFAULT 1,
  `stage1_time` varchar(100) DEFAULT NULL,
  `stage2_time` varchar(100) DEFAULT NULL,
  `stage3_time` varchar(100) DEFAULT NULL,
  `stage4_time` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_number` (`tracking_number`),
  KEY `user_id` (`user_id`),
  KEY `request_type_id` (`request_type_id`),
  CONSTRAINT `fk_req_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_req_type` FOREIGN KEY (`request_type_id`) REFERENCES `request_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Requests from PPT Slides
INSERT INTO `requests` (`id`, `tracking_number`, `user_id`, `request_type_id`, `purpose`, `additional_notes`, `attached_filename`, `file_size_kb`, `file_verified`, `status`, `current_stage`, `stage1_time`, `stage2_time`, `stage3_time`, `stage4_time`, `remarks`, `created_at`) VALUES
(1, 'RQ-001', 1, 1, 'For Visa Application at the US Embassy', 'Urgent processing requested', 'COE_Request_Letter_MariaReyes.pdf', '130 KB', 1, 'Ready for Release', 4, 'July 20, 2026 9:00 AM', 'July 21, 2026 10:30 AM', 'July 22, 2026 11:00 AM', 'July 23, 2026 2:10 PM', 'Document is ready for release. Please proceed to the office.', '2026-07-20 01:00:00'),
(2, 'RQ-010', 1, 4, 'Faculty Promotion and Salary Increment', '', 'Rating_Doc_2026.pdf', '115 KB', 1, 'Processing', 3, 'July 21, 2026 10:15 AM', 'July 21, 2026 2:30 PM', 'July 22, 2026 9:00 AM', NULL, 'Document being reviewed and printed by Registrar.', '2026-07-21 02:15:00'),
(3, 'RQ-012', 1, 3, 'GSIS Loan Application Requirement', '', 'GSIS_Requirement_MR.pdf', '180 KB', 0, 'Rejected', 2, 'July 21, 2026 2:00 PM', 'July 22, 2026 9:15 AM', NULL, NULL, 'Incomplete attachments. Please resubmit updated appointment document.', '2026-07-21 06:00:00'),
(4, 'RQ-015', 1, 2, 'Maternity/Vacation leave credits validation', '', 'Leave_Credits_Doc.pdf', '95 KB', 1, 'Pending', 1, 'July 25, 2026 8:30 AM', NULL, NULL, NULL, 'Request submitted. Awaiting administrative review.', '2026-07-25 00:30:00'),
(5, 'RQ-014', 2, 5, 'For college application at University of the Philippines', 'Direct delivery to UP Registrar if possible', 'Form 137_Request_Letter_Juandelacruz.pdf', '113 KB', 1, 'Pending', 1, 'July 25, 2026 3:45 PM', NULL, NULL, NULL, 'Request submitted and queued for evaluation.', '2026-07-25 07:45:00'),
(6, 'RQ-012-ST', 2, 7, 'Internship requirement for company', '', 'Enrollment_Cert_Doc.pdf', '120 KB', 0, 'Rejected', 2, 'July 21, 2026 1:20 PM', 'July 22, 2026 10:00 AM', NULL, NULL, 'Invalid COR submitted. Please provide registration form with university stamp.', '2026-07-21 05:20:00'),
(7, 'RQ-010-ST', 2, 8, 'Scholarship application requirement', '', 'GoodMoral_Request.pdf', '105 KB', 1, 'Ready for Release', 4, 'July 20, 2026 11:00 AM', 'July 21, 2026 9:00 AM', 'July 21, 2026 1:00 PM', 'July 22, 2026 3:30 PM', 'Good Moral certificate ready. Claim at Guidance & Registrar window 2.', '2026-07-20 03:00:00');

-- --------------------------------------------------------
-- Table structure for table `notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `request_id` varchar(50) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `location` varchar(150) DEFAULT 'Registrar''s Office',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `notifications` (`id`, `user_id`, `request_id`, `title`, `message`, `location`, `is_read`, `created_at`) VALUES
(1, 1, 'RQ-001', 'Request RQ-001 Approved', 'Your request for Certificate of Employment (COE) has been approved by the Administrator. Ready for Pickup. No Fee Required.', 'Please visit the Registrar\'s Office to claim your document.', 0, NOW() - INTERVAL 5 MINUTE);

-- --------------------------------------------------------
-- Table structure for table `audit_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_name` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL,
  `action` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '127.0.0.1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `audit_logs` (`id`, `user_name`, `role`, `action`, `ip_address`, `created_at`) VALUES
(1, 'System Administrator', 'ADMIN', 'Approved Request RQ-001 (Certificate of Employment)', '192.168.1.10', NOW() - INTERVAL 1 HOUR),
(2, 'System Administrator', 'ADMIN', 'Logged into Administrator Portal', '192.168.1.10', NOW() - INTERVAL 2 HOUR),
(3, 'Maria Reyes', 'TEACHER', 'Submitted Request RQ-001 (Certificate of Employment)', '192.168.1.45', NOW() - INTERVAL 1 DAY);

-- --------------------------------------------------------
-- Table structure for table `otp_verifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `otp_verifications`;
CREATE TABLE `otp_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `type` enum('registration','forgot_password') NOT NULL,
  `temp_data` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
