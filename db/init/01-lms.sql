-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 26, 2026 at 08:37 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lms`
--
CREATE DATABASE IF NOT EXISTS `lms` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lms`;

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE IF NOT EXISTS `academic_years` (
  `academic_year_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `year_name` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`academic_year_id`),
  UNIQUE KEY `unique_year_institution` (`year_name`,`institution_id`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_is_current` (`is_current`),
  KEY `idx_year_name` (`year_name`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `academic_years`:
--

--
-- Truncate table before insert `academic_years`
--

TRUNCATE TABLE `academic_years`;
--
-- Dumping data for table `academic_years`
--


-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
CREATE TABLE IF NOT EXISTS `admins` (
  `admin_id` int(11) NOT NULL,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `employment_end_date` date DEFAULT NULL,
  `qualification` varchar(200) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `alternative_email` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `unique_employee_id_institution` (`employee_id`,`institution_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_admins_institution_id` (`institution_id`),
  KEY `idx_admins_employee_id` (`employee_id`),
  KEY `idx_admins_status` (`status`),
  KEY `idx_admins_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `admins`:
--

--
-- Truncate table before insert `admins`
--

TRUNCATE TABLE `admins`;
--
-- Dumping data for table `admins`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity`
--

DROP TABLE IF EXISTS `admin_activity`;
CREATE TABLE IF NOT EXISTS `admin_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL COMMENT 'Institution the admin belongs to',
  `performed_by` int(11) NOT NULL COMMENT 'user_id of the admin who performed the action',
  `activity_type` varchar(50) NOT NULL COMMENT 'e.g. student_enrolled, teacher_added, class_created, login, update, delete',
  `description` varchar(500) NOT NULL COMMENT 'Human-readable summary shown in the dashboard activity list',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource affected: student, teacher, class, course, user, etc.',
  `entity_id` int(11) DEFAULT NULL COMMENT 'Primary key of the affected entity (optional)',
  `meta` varchar(255) DEFAULT NULL COMMENT 'Extra context shown in the activity list subtitle (e.g. student name, class name)',
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_performed_by` (`performed_by`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all meaningful actions performed by institution admins';

--
-- RELATIONSHIPS FOR TABLE `admin_activity`:
--

--
-- Truncate table before insert `admin_activity`
--

TRUNCATE TABLE `admin_activity`;
--
-- Dumping data for table `admin_activity`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `institution_id` int(11) DEFAULT NULL,
  `target_role` varchar(50) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `priority` tinyint(3) NOT NULL DEFAULT 0,
  `read_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`announcement_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `FK_announcements_author` (`author_id`),
  KEY `idx_target_role` (`target_role`),
  KEY `idx_is_published` (`is_published`),
  KEY `idx_published_at` (`published_at`),
  KEY `idx_announcements_uuid` (`uuid`),
  KEY `idx_institution_id` (`institution_id`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `announcements`:
--

--
-- Truncate table before insert `announcements`
--

TRUNCATE TABLE `announcements`;
--
-- Dumping data for table `announcements`
--
-- --------------------------------------------------------

--
-- Table structure for table `announcement_reads`
--

DROP TABLE IF EXISTS `announcement_reads`;
CREATE TABLE IF NOT EXISTS `announcement_reads` (
  `announcement_read_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_read_id`),
  UNIQUE KEY `uq_announcement_reads_uuid` (`uuid`),
  UNIQUE KEY `uq_announcement_reads_announcement_user` (`announcement_id`,`user_id`),
  KEY `idx_announcement_reads_announcement_id` (`announcement_id`),
  KEY `idx_announcement_reads_user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `announcement_reads`:
--

--
-- Truncate table before insert `announcement_reads`
--

TRUNCATE TABLE `announcement_reads`;
--
-- Dumping data for table `announcement_reads`
--

-- --------------------------------------------------------

--
-- Table structure for table `assessments`
--

DROP TABLE IF EXISTS `assessments`;
CREATE TABLE IF NOT EXISTS `assessments` (
  `assessment_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `assessment_type` varchar(50) NOT NULL,
  `max_score` decimal(10,2) DEFAULT 100.00,
  `passing_score` decimal(10,2) DEFAULT 60.00,
  `due_date` datetime DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `weight_percentage` decimal(5,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`assessment_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_assessment_type` (`assessment_type`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_academic_year_id` (`academic_year_id`),
  KEY `idx_semester_id` (`semester_id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `assessments`:
--

--
-- Truncate table before insert `assessments`
--

TRUNCATE TABLE `assessments`;
--
-- Dumping data for table `assessments`
--

-- --------------------------------------------------------

--
-- Table structure for table `assessment_categories`
--

DROP TABLE IF EXISTS `assessment_categories`;
CREATE TABLE IF NOT EXISTS `assessment_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `weight_percentage` decimal(5,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  KEY `idx_category_name` (`category_name`),
  KEY `institution_id` (`institution_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `assessment_categories`:
--

--
-- Truncate table before insert `assessment_categories`
--

TRUNCATE TABLE `assessment_categories`;
--
-- Dumping data for table `assessment_categories`
--

-- --------------------------------------------------------

--
-- Table structure for table `assessment_submissions`
--

DROP TABLE IF EXISTS `assessment_submissions`;
CREATE TABLE IF NOT EXISTS `assessment_submissions` (
  `submission_id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submission_text` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `score` decimal(10,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`submission_id`),
  KEY `idx_assessment_id` (`assessment_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_submission_assessment_student` (`assessment_id`,`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=272 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `assessment_submissions`:
--

--
-- Truncate table before insert `assessment_submissions`
--

TRUNCATE TABLE `assessment_submissions`;
--
-- Dumping data for table `assessment_submissions`
--

--
-- Triggers `assessment_submissions`
--
DROP TRIGGER IF EXISTS `after_submission_graded`;
DELIMITER $$
CREATE TRIGGER `after_submission_graded` AFTER UPDATE ON `assessment_submissions` FOR EACH ROW BEGIN
    DECLARE student_user_id INT;
    DECLARE sender_user_id INT;
    DECLARE assessment_title VARCHAR(200);

    IF NEW.graded_at IS NOT NULL AND OLD.graded_at IS NULL THEN
        SELECT user_id INTO student_user_id
          FROM students
         WHERE student_id = NEW.student_id;

        SELECT title INTO assessment_title
          FROM assessments
         WHERE assessment_id = NEW.assessment_id;

        SELECT t.user_id INTO sender_user_id
          FROM assessments a
          INNER JOIN class_subjects cs ON cs.course_id = a.course_id
          INNER JOIN teachers t ON t.teacher_id = cs.teacher_id
         WHERE a.assessment_id = NEW.assessment_id
         LIMIT 1;

        IF sender_user_id IS NULL THEN
            SET sender_user_id = student_user_id;
        END IF;

        INSERT INTO notifications (uuid, sender_id, user_id, title, message, notification_type)
        VALUES (
            UUID(),
            sender_user_id,
            student_user_id,
            'Assessment Graded',
            CONCAT('Your submission for "', assessment_title, '" has been graded. Score: ', NEW.score),
            'grading'
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

DROP TABLE IF EXISTS `assignments`;
CREATE TABLE IF NOT EXISTS `assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `course_id` int(11) NOT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `max_score` decimal(10,2) DEFAULT 100.00,
  `passing_score` decimal(10,2) DEFAULT 60.00,
  `rubric` text DEFAULT NULL,
  `submission_type` varchar(50) DEFAULT 'both',
  `due_date` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'draft',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_section_id` (`section_id`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_status` (`status`),
  KEY `idx_assignments_uuid` (`uuid`),
  KEY `academic_year_id` (`academic_year_id`)
) ENGINE=InnoDB AUTO_INCREMENT=210 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `assignments`:
--

--
-- Truncate table before insert `assignments`
--

TRUNCATE TABLE `assignments`;
--
-- Dumping data for table `assignments`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

DROP TABLE IF EXISTS `assignment_submissions`;
CREATE TABLE IF NOT EXISTS `assignment_submissions` (
  `submission_id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `submission_text` text DEFAULT NULL,
  `submission_file` varchar(500) DEFAULT NULL,
  `score` decimal(10,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`submission_id`),
  KEY `FK_assignment_submissions_grader` (`graded_by`),
  KEY `idx_assignment_id` (`assignment_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `assignment_submissions`:
--

--
-- Truncate table before insert `assignment_submissions`
--

TRUNCATE TABLE `assignment_submissions`;
--
-- Dumping data for table `assignment_submissions`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
CREATE TABLE IF NOT EXISTS `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` varchar(20) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `unique_attendance` (`student_id`,`course_id`,`attendance_date`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_attendance_date` (`attendance_date`),
  KEY `idx_attendance_date_range` (`student_id`,`attendance_date`)
) ENGINE=InnoDB AUTO_INCREMENT=215 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `attendance`:
--

--
-- Truncate table before insert `attendance`
--

TRUNCATE TABLE `attendance`;
--
-- Dumping data for table `attendance`
--

-- --------------------------------------------------------

--
-- Table structure for table `chat_attachments`
--

DROP TABLE IF EXISTS `chat_attachments`;
CREATE TABLE IF NOT EXISTS `chat_attachments` (
  `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_message_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attachment_id`),
  KEY `idx_chat_attachments_message` (`chat_message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `chat_attachments`:
--

--
-- Truncate table before insert `chat_attachments`
--

TRUNCATE TABLE `chat_attachments`;
--
-- Dumping data for table `chat_attachments`
--

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `chat_message_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `room_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message_type` varchar(20) NOT NULL DEFAULT 'text',
  `message_text` longtext DEFAULT NULL,
  `reply_to_message_id` int(11) DEFAULT NULL,
  `is_edited` tinyint(1) NOT NULL DEFAULT 0,
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chat_message_id`),
  UNIQUE KEY `uq_chat_messages_uuid` (`uuid`),
  KEY `idx_chat_messages_room_created` (`room_id`,`created_at`),
  KEY `idx_chat_messages_sender_created` (`sender_id`,`created_at`),
  KEY `idx_chat_messages_room_deleted_created` (`room_id`,`deleted_at`,`created_at`),
  KEY `idx_chat_messages_reply_to` (`reply_to_message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `chat_messages`:
--

--
-- Truncate table before insert `chat_messages`
--

TRUNCATE TABLE `chat_messages`;
--
-- Dumping data for table `chat_messages`
--

-- --------------------------------------------------------

--
-- Table structure for table `chat_message_reads`
--

DROP TABLE IF EXISTS `chat_message_reads`;
CREATE TABLE IF NOT EXISTS `chat_message_reads` (
  `read_id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`read_id`),
  UNIQUE KEY `uq_chat_message_reads_message_user` (`chat_message_id`,`user_id`),
  KEY `idx_chat_message_reads_user_read_at` (`user_id`,`read_at`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `chat_message_reads`:
--

--
-- Truncate table before insert `chat_message_reads`
--

TRUNCATE TABLE `chat_message_reads`;
--
-- Dumping data for table `chat_message_reads`
--



DROP TABLE IF EXISTS `chat_rooms`;
CREATE TABLE IF NOT EXISTS `chat_rooms` (
  `room_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) DEFAULT NULL,
  `room_type` varchar(20) NOT NULL DEFAULT 'direct',
  `room_name` varchar(200) DEFAULT NULL,
  `room_avatar` varchar(500) DEFAULT NULL,
  `room_description` text DEFAULT NULL,
  `category_type` varchar(20) DEFAULT NULL,
  `category_ref_id` int(11) DEFAULT NULL,
  `category_label` varchar(100) DEFAULT NULL,
  `direct_user_1` int(11) DEFAULT NULL,
  `direct_user_2` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_message_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`room_id`),
  UNIQUE KEY `uq_chat_rooms_uuid` (`uuid`),
  UNIQUE KEY `uq_chat_rooms_direct_pair` (`direct_user_1`,`direct_user_2`),
  KEY `idx_chat_rooms_institution_type` (`institution_id`,`room_type`),
  KEY `idx_chat_rooms_category` (`category_type`,`category_ref_id`),
  KEY `idx_chat_rooms_last_message_at` (`last_message_at`),
  KEY `idx_chat_rooms_created_by` (`created_by`),
  KEY `fk_chat_rooms_direct_user_2` (`direct_user_2`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `chat_rooms`:
--

--
-- Truncate table before insert `chat_rooms`
--

TRUNCATE TABLE `chat_rooms`;
--
-- Dumping data for table `chat_rooms`
--


--
-- Table structure for table `chat_room_members`
--

DROP TABLE IF EXISTS `chat_room_members`;
CREATE TABLE IF NOT EXISTS `chat_room_members` (
  `room_member_id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `member_role` varchar(20) NOT NULL DEFAULT 'member',
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `left_at` datetime DEFAULT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `last_read_message_id` int(11) DEFAULT NULL,
  `last_read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`room_member_id`),
  UNIQUE KEY `uq_chat_room_members_room_user` (`room_id`,`user_id`),
  KEY `idx_chat_room_members_user_active` (`user_id`,`left_at`),
  KEY `idx_chat_room_members_room_role` (`room_id`,`member_role`),
  KEY `idx_chat_room_members_room_left` (`room_id`,`left_at`),
  KEY `fk_chat_room_members_last_read_message` (`last_read_message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `chat_room_members`:
--

--
-- Truncate table before insert `chat_room_members`
--

TRUNCATE TABLE `chat_room_members`;
--
-- Dumping data for table `chat_room_members`
--



DROP TABLE IF EXISTS `classes`;
CREATE TABLE IF NOT EXISTS `classes` (
  `class_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `grade_level_id` int(11) NOT NULL,
  `class_code` varchar(50) NOT NULL,
  `class_name` varchar(200) NOT NULL,
  `section` varchar(50) NOT NULL,
  `class_teacher_id` int(11) DEFAULT NULL,
  `max_students` int(11) DEFAULT 40,
  `room_number` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`class_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  UNIQUE KEY `unique_class_code_institution` (`class_code`,`institution_id`) USING BTREE,
  UNIQUE KEY `unique_class_composition` (`institution_id`,`program_id`,`grade_level_id`,`section`) USING BTREE,
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_program_id` (`program_id`),
  KEY `idx_grade_level_id` (`grade_level_id`),
  KEY `idx_class_code` (`class_code`),
  KEY `idx_section` (`section`),
  KEY `idx_class_teacher_id` (`class_teacher_id`),
  KEY `idx_status` (`status`),
  KEY `idx_classes_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `classes`:
--

--
-- Truncate table before insert `classes`
--

TRUNCATE TABLE `classes`;
--
-- Dumping data for table `classes`
--

DROP TABLE IF EXISTS `class_subjects`;
CREATE TABLE IF NOT EXISTS `class_subjects` (
  `course_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `duration_weeks` int(11) DEFAULT 16,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`course_id`),
  UNIQUE KEY `unique_class_subject_year` (`institution_id`,`class_id`,`subject_id`,`academic_year_id`,`semester_id`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_subject_id` (`subject_id`),
  KEY `idx_teacher_id` (`teacher_id`),
  KEY `idx_academic_year_id` (`academic_year_id`),
  KEY `idx_semester_id` (`semester_id`),
  KEY `idx_status` (`status`),
  KEY `idx_course_teacher_status` (`teacher_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `class_subjects`:
--

--
-- Truncate table before insert `class_subjects`
--

TRUNCATE TABLE `class_subjects`;
--
-- Dumping data for table `class_subjects`
--



DROP TABLE IF EXISTS `course_content`;
CREATE TABLE IF NOT EXISTS `course_content` (
  `course_content_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content_text` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `content_type` varchar(50) DEFAULT 'lesson',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`course_content_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `FK_course_content_creator` (`created_by`),
  KEY `idx_course_content_course` (`course_id`),
  KEY `idx_course_content_section` (`section_id`),
  KEY `idx_course_content_active` (`is_active`),
  KEY `idx_course_content_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_content`:
--

--
-- Truncate table before insert `course_content`
--

TRUNCATE TABLE `course_content`;
-- --------------------------------------------------------

--
-- Table structure for table `course_content_order`
--

DROP TABLE IF EXISTS `course_content_order`;
CREATE TABLE IF NOT EXISTS `course_content_order` (
  `course_content_order_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `course_section_id` int(11) NOT NULL,
  `course_content_id` int(11) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `item_type` varchar(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`course_content_order_id`),
  KEY `FK_content_order_content` (`course_content_id`),
  KEY `FK_content_order_material` (`material_id`),
  KEY `idx_content_order_course` (`course_id`),
  KEY `idx_content_order_section` (`course_section_id`),
  KEY `idx_content_order_index` (`order_index`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_content_order`:
--

--
-- Truncate table before insert `course_content_order`
--

TRUNCATE TABLE `course_content_order`;
--
-- Dumping data for table `course_content_order`
--


DROP TABLE IF EXISTS `user_activity`;
CREATE TABLE IF NOT EXISTS `user_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `activity_details` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=90458 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `user_activity`:
--

--
-- Truncate table before insert `user_activity`
--

TRUNCATE TABLE `user_activity`;
--
-- Dumping data for table `user_activity`
--


DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `title` varchar(10) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `is_super_admin` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `profile_photo` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  UNIQUE KEY `unique_username_institution` (`username`,`institution_id`),
  UNIQUE KEY `unique_email_institution` (`email`,`institution_id`),
  KEY `idx_institution` (`institution_id`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_is_super_admin` (`is_super_admin`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_user_active` (`is_active`,`created_at`),
  KEY `idx_users_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=290 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `users`:
--

--
-- Truncate table before insert `users`
--

TRUNCATE TABLE `users`;
--
-- Dumping data for table `users`
--


--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_role_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`user_role_id`),
  UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  UNIQUE KEY `user_role_id` (`user_role_id`),
  UNIQUE KEY `unique_user_id` (`user_id`) USING BTREE,
  KEY `FK_user_roles_role` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=386 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `user_roles`:
--

--
-- Truncate table before insert `user_roles`
--

TRUNCATE TABLE `user_roles`;
--
-- Dumping data for table `user_roles`
--



--
-- Table structure for table `course_enrollments`
--

DROP TABLE IF EXISTS `course_enrollments`;
CREATE TABLE IF NOT EXISTS `course_enrollments` (
  `enrollment_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enrollment_date` datetime DEFAULT current_timestamp(),
  `completion_date` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `final_grade` varchar(2) DEFAULT NULL,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `unique_enrollment` (`student_id`,`course_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_status` (`status`),
  KEY `idx_enrollment_student_status` (`student_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_enrollments`:
--

--
-- Truncate table before insert `course_enrollments`
--

TRUNCATE TABLE `course_enrollments`;
--
-- Triggers `course_enrollments`
--
DROP TRIGGER IF EXISTS `after_enrollment_insert`;
DELIMITER $$
CREATE TRIGGER `after_enrollment_insert` AFTER INSERT ON `course_enrollments` FOR EACH ROW BEGIN
    DECLARE student_user_id INT;
    DECLARE class_subject_name VARCHAR(200);
    
    SELECT user_id INTO student_user_id FROM students WHERE student_id = NEW.student_id;
    SELECT class_subject_name INTO class_subject_name FROM class_subjects WHERE course_id = NEW.course_id;
    
    INSERT INTO notifications (uuid, sender_id, user_id, title, message, notification_type)
    VALUES (
        UUID(),
        0,
        student_user_id,
        'Course Enrollment',
        CONCAT('You have been enrolled in ', class_subject_name),
        'enrollment'
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `course_materials`
--

DROP TABLE IF EXISTS `course_materials`;
CREATE TABLE IF NOT EXISTS `course_materials` (
  `material_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `material_type` varchar(50) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `external_link` varchar(500) DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `is_required` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `uploaded_by` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'active',
  `access_permission` enum('view','download') NOT NULL DEFAULT 'download',
  `download_count` int(11) NOT NULL DEFAULT 0,
  `tags` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`material_id`),
  KEY `FK_materials_uploader` (`uploaded_by`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_section_id` (`section_id`),
  KEY `idx_material_type` (`material_type`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_status` (`status`),
  KEY `idx_access_permission` (`access_permission`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_materials`:
--

--
-- Truncate table before insert `course_materials`
--

TRUNCATE TABLE `course_materials`;
--
-- Dumping data for table `course_materials`
--



DROP TABLE IF EXISTS `course_reviews`;
CREATE TABLE IF NOT EXISTS `course_reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `review_text` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `unique_review` (`course_id`,`student_id`),
  KEY `FK_reviews_student` (`student_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_reviews`:
--

--
-- Truncate table before insert `course_reviews`
--

TRUNCATE TABLE `course_reviews`;
-- --------------------------------------------------------

--
-- Table structure for table `course_schedules`
--

DROP TABLE IF EXISTS `course_schedules`;
CREATE TABLE IF NOT EXISTS `course_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `institution_id` int(11) DEFAULT NULL,
  `day_of_week` varchar(20) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(50) DEFAULT NULL,
  `period_label` varchar(50) DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 1,
  `status` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `unique_course_schedules` (`course_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_day_of_week` (`day_of_week`),
  KEY `idx_institution_id` (`institution_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_schedules`:
--

--
-- Truncate table before insert `course_schedules`
--

TRUNCATE TABLE `course_schedules`;
--
-- Dumping data for table `course_schedules`
--


--
-- Table structure for table `course_sections`
--

DROP TABLE IF EXISTS `course_sections`;
CREATE TABLE IF NOT EXISTS `course_sections` (
  `course_sections_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `section_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`course_sections_id`),
  KEY `FK_course_sections_creator` (`created_by`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_order_index` (`order_index`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `course_sections`:
--

--
-- Truncate table before insert `course_sections`
--

TRUNCATE TABLE `course_sections`;
--
-- Dumping data for table `course_sections`
--


-- Table structure for table `error_logs`
--

DROP TABLE IF EXISTS `error_logs`;
CREATE TABLE IF NOT EXISTS `error_logs` (
  `error_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `error_message` text DEFAULT NULL,
  `stack_trace` text DEFAULT NULL,
  `source` varchar(200) DEFAULT NULL,
  `severity_level` varchar(20) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`error_log_id`),
  KEY `FK_error_logs_user` (`user_id`),
  KEY `FK_error_logs_resolved_by` (`resolved_by`),
  KEY `idx_severity_level` (`severity_level`),
  KEY `idx_is_resolved` (`is_resolved`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `error_logs`:
--

--
-- Truncate table before insert `error_logs`
--

TRUNCATE TABLE `error_logs`;
-- --------------------------------------------------------

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
CREATE TABLE IF NOT EXISTS `events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `event_type` varchar(50) DEFAULT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `all_day` tinyint(1) DEFAULT 0,
  `location` varchar(200) DEFAULT NULL,
  `target_role` varchar(50) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  `recurrence_pattern` varchar(100) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `FK_events_creator` (`created_by`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_event_dates` (`start_date`,`end_date`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_target_role` (`target_role`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_academic_year_id` (`academic_year_id`),
  KEY `idx_events_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `events`:
--

--
-- Truncate table before insert `events`
--

TRUNCATE TABLE `events`;
--
-- Dumping data for table `events`
--


--
-- Table structure for table `grade_categories`
--

DROP TABLE IF EXISTS `grade_categories`;
CREATE TABLE IF NOT EXISTS `grade_categories` (
  `grade_categories_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `grade_categories_name` varchar(50) NOT NULL,
  `grade_categories_description` varchar(255) DEFAULT NULL,
  `Pass_Threshold` int(11) DEFAULT NULL,
  `Used_By` varchar(50) DEFAULT NULL,
  `set_as_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(50) NOT NULL DEFAULT 'draft',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grade_categories_id`),
  UNIQUE KEY `uuid` (`grade_categories_name`,`institution_id`),
  KEY `institution_id` (`institution_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `grade_categories`:
--

--
-- Truncate table before insert `grade_categories`
--

TRUNCATE TABLE `grade_categories`;
--
-- Dumping data for table `grade_categories`
--


--
-- Table structure for table `grade_levels`
--

DROP TABLE IF EXISTS `grade_levels`;
CREATE TABLE IF NOT EXISTS `grade_levels` (
  `grade_level_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `grade_level_code` varchar(20) NOT NULL,
  `grade_level_name` varchar(50) NOT NULL,
  `level_order` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grade_level_id`),
  UNIQUE KEY `unique_grade_code_institution` (`grade_level_code`,`institution_id`),
  UNIQUE KEY `unique_grade_order_institution` (`institution_id`,`level_order`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_grade_level_code` (`grade_level_code`),
  KEY `idx_level_order` (`level_order`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `grade_levels`:
--

--
-- Truncate table before insert `grade_levels`
--

TRUNCATE TABLE `grade_levels`;
--
-- Dumping data for table `grade_levels`
--


--
-- Table structure for table `grade_reports`
--

DROP TABLE IF EXISTS `grade_reports`;
CREATE TABLE IF NOT EXISTS `grade_reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `report_type` varchar(50) DEFAULT 'semester',
  `teacher_comment` text DEFAULT NULL,
  `principal_comment` text DEFAULT NULL,
  `generated_at` datetime DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `Approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`report_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `unique_student_semester_report` (`student_id`,`academic_year_id`,`semester_id`,`report_type`),
  KEY `FK_grade_reports_generator` (`generated_by`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_academic_year` (`academic_year_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_report_type` (`report_type`),
  KEY `idx_grade_reports_uuid` (`uuid`),
  KEY `idx_grade_reports_class_id` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `grade_reports`:
--

--
-- Truncate table before insert `grade_reports`
--

TRUNCATE TABLE `grade_reports`;
--
-- Dumping data for table `grade_reports`
--



DROP TABLE IF EXISTS `grade_report_details`;
CREATE TABLE IF NOT EXISTS `grade_report_details` (
  `report_detail_id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `total_score` decimal(10,2) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`report_detail_id`),
  UNIQUE KEY `report_id` (`report_id`,`course_id`),
  KEY `idx_report_id` (`report_id`),
  KEY `idx_course_id` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `grade_report_details`:
--

--
-- Truncate table before insert `grade_report_details`
--

TRUNCATE TABLE `grade_report_details`;
--
-- Dumping data for table `grade_report_details`
--


--
-- Table structure for table `grade_scales`
--

DROP TABLE IF EXISTS `grade_scales`;
CREATE TABLE IF NOT EXISTS `grade_scales` (
  `grade_scale_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) DEFAULT NULL,
  `grade_categories_id` int(11) DEFAULT NULL,
  `grade` varchar(5) NOT NULL,
  `min_score` decimal(5,2) NOT NULL,
  `max_score` decimal(5,2) NOT NULL,
  `grade_point` decimal(5,2) DEFAULT NULL,
  `Interpretation` varchar(50) DEFAULT NULL,
  `Status` varchar(50) DEFAULT NULL,
  `remark` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grade_scale_id`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_grade` (`grade`),
  KEY `idx_score_range` (`min_score`,`max_score`),
  KEY `grade_categories_id` (`grade_categories_id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `grade_scales`:
--

--
-- Truncate table before insert `grade_scales`
--

TRUNCATE TABLE `grade_scales`;
--
-- Dumping data for table `grade_scales`
--


--
-- Table structure for table `institutions`
--

DROP TABLE IF EXISTS `institutions`;
CREATE TABLE IF NOT EXISTS `institutions` (
  `institution_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_code` varchar(20) NOT NULL,
  `institution_name` varchar(200) NOT NULL,
  `institution_type` varchar(50) DEFAULT 'shs',
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Ghana',
  `postal_code` varchar(20) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `subscription_plan` varchar(50) DEFAULT NULL,
  `subscription_expires_at` date DEFAULT NULL,
  `max_students` int(11) DEFAULT 500,
  `max_teachers` int(11) DEFAULT 50,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`institution_id`),
  UNIQUE KEY `institution_code` (`institution_code`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `idx_institution_code` (`institution_code`),
  KEY `idx_status` (`status`),
  KEY `idx_institutions_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `institutions`:
--

--
-- Truncate table before insert `institutions`
--

TRUNCATE TABLE `institutions`;
--
-- Dumping data for table `institutions`
--


--
-- Table structure for table `institution_settings`
--

DROP TABLE IF EXISTS `institution_settings`;
CREATE TABLE IF NOT EXISTS `institution_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `school_name` varchar(200) DEFAULT NULL,
  `motto` varchar(300) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `vision` text DEFAULT NULL,
  `mission` text DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `banner_url` varchar(500) DEFAULT NULL,
  `theme_primary_color` varchar(20) DEFAULT '#1976d2',
  `theme_secondary_color` varchar(20) DEFAULT '#dc004e',
  `timezone` varchar(50) DEFAULT 'Africa/Accra',
  `academic_year_start_month` int(11) DEFAULT 9,
  `academic_year_end_month` int(11) DEFAULT 6,
  `grading_system` varchar(20) DEFAULT 'percentage',
  `locale` varchar(10) DEFAULT 'en_US',
  `currency` varchar(10) DEFAULT 'GHS',
  `date_format` varchar(20) DEFAULT 'Y-m-d',
  `time_format` varchar(20) DEFAULT 'H:i:s',
  `allow_parent_registration` tinyint(1) DEFAULT 1,
  `allow_student_self_enrollment` tinyint(1) DEFAULT 0,
  `require_email_verification` tinyint(1) DEFAULT 1,
  `meta` text DEFAULT NULL,
  `custom_css` text DEFAULT NULL,
  `custom_footer` text DEFAULT NULL,
  `social_facebook` varchar(200) DEFAULT NULL,
  `social_twitter` varchar(200) DEFAULT NULL,
  `social_instagram` varchar(200) DEFAULT NULL,
  `social_linkedin` varchar(200) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `unique_institution` (`institution_id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `institution_settings`:
--

--
-- Truncate table before insert `institution_settings`
--

TRUNCATE TABLE `institution_settings`;
--
-- Dumping data for table `institution_settings`
--



--
-- Table structure for table `lesson_plans`
--

DROP TABLE IF EXISTS `lesson_plans`;
CREATE TABLE IF NOT EXISTS `lesson_plans` (
  `lesson_plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `strand` varchar(255) DEFAULT NULL,
  `sub_strand` varchar(255) DEFAULT NULL,
  `duration` varchar(120) DEFAULT NULL,
  `content_standard` text DEFAULT NULL,
  `learning_outcomes` text DEFAULT NULL,
  `learning_indicators` text DEFAULT NULL,
  `essential_questions` text DEFAULT NULL,
  `pedagogical_strategies` text DEFAULT NULL,
  `teaching_learning_resources` text DEFAULT NULL,
  `differentiation_notes` text DEFAULT NULL,
  `lesson_introduction` text DEFAULT NULL,
  `lesson_main` text DEFAULT NULL,
  `lesson_closure` text DEFAULT NULL,
  `formative_assessment_mode` varchar(120) DEFAULT NULL,
  `formative_assessment_task` text DEFAULT NULL,
  `formative_mark_scheme` text DEFAULT NULL,
  `transcript_assessment_mode` varchar(120) DEFAULT NULL,
  `transcript_assessment_task` text DEFAULT NULL,
  `transcript_rubric_mark_scheme` text DEFAULT NULL,
  `reflection_remarks` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lesson_plan_id`),
  KEY `FK_lesson_plans_course` (`course_id`),
  KEY `FK_lesson_plans_section` (`section_id`),
  KEY `FK_lesson_plans_creator` (`created_by`),
  KEY `idx_lesson_plan_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `lesson_plans`:
--

--
-- Truncate table before insert `lesson_plans`
--

TRUNCATE TABLE `lesson_plans`;
--
-- Dumping data for table `lesson_plans`
--


--
-- Table structure for table `lesson_plan_materials`
--

DROP TABLE IF EXISTS `lesson_plan_materials`;
CREATE TABLE IF NOT EXISTS `lesson_plan_materials` (
  `lesson_plan_materials_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_plan_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `order_index` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`lesson_plan_materials_id`),
  UNIQUE KEY `unique_lesson_material` (`lesson_plan_id`,`material_id`),
  KEY `FK_lesson_material_material` (`material_id`),
  KEY `idx_lesson_plan_id` (`lesson_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `lesson_plan_materials`:
--

--
-- Truncate table before insert `lesson_plan_materials`
--

TRUNCATE TABLE `lesson_plan_materials`;
-- --------------------------------------------------------

--
-- Table structure for table `login_activity`
--

DROP TABLE IF EXISTS `login_activity`;
CREATE TABLE IF NOT EXISTS `login_activity` (
  `login_activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `login_time` datetime DEFAULT current_timestamp(),
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `is_successful` tinyint(1) DEFAULT 1,
  `failure_reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`login_activity_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_login_time` (`login_time`),
  KEY `idx_is_successful` (`is_successful`)
) ENGINE=InnoDB AUTO_INCREMENT=1079 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `login_activity`:
--

--
-- Truncate table before insert `login_activity`
--

TRUNCATE TABLE `login_activity`;
--
-- Dumping data for table `login_activity`
--


--
-- Table structure for table `teacher_activity`
--

DROP TABLE IF EXISTS `teacher_activity`;
CREATE TABLE IF NOT EXISTS `teacher_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL COMMENT 'Institution the teacher belongs to',
  `performed_by` int(11) NOT NULL COMMENT 'user_id of the teacher who performed the action',
  `activity_type` varchar(50) NOT NULL COMMENT 'e.g. grade_added, assignment_created, attendance_marked, material_uploaded, login, logout',
  `description` varchar(500) NOT NULL COMMENT 'Human-readable summary shown in the activity list',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource affected: student, class, course, assignment, grade, etc.',
  `entity_id` int(11) DEFAULT NULL COMMENT 'Primary key of the affected entity (optional)',
  `meta` varchar(255) DEFAULT NULL COMMENT 'Extra context: student name, class name, subject, etc.',
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_ta_institution_id` (`institution_id`),
  KEY `idx_ta_performed_by` (`performed_by`),
  KEY `idx_ta_activity_type` (`activity_type`),
  KEY `idx_ta_entity` (`entity_type`,`entity_id`),
  KEY `idx_ta_severity` (`severity`),
  KEY `idx_ta_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all meaningful actions performed by teachers';

--
-- RELATIONSHIPS FOR TABLE `teacher_activity`:
--

--
-- Truncate table before insert `teacher_activity`
--

TRUNCATE TABLE `teacher_activity`;
--
-- Triggers `teacher_activity`
--
DROP TRIGGER IF EXISTS `trg_teacher_activity_purge`;
DELIMITER $$
CREATE TRIGGER `trg_teacher_activity_purge` AFTER INSERT ON `teacher_activity` FOR EACH ROW BEGIN
  DELETE FROM `teacher_activity`
  WHERE `created_at` < (NOW() - INTERVAL 90 DAY);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_subjects`
--

DROP TABLE IF EXISTS `teacher_subjects`;
CREATE TABLE IF NOT EXISTS `teacher_subjects` (
  `teacher_subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `assigned_date` date DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`teacher_subject_id`),
  KEY `idx_teacher_id` (`teacher_id`),
  KEY `idx_subject_id` (`subject_id`),
  KEY `idx_teacher_subject` (`teacher_id`,`subject_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `teacher_subjects`:
--

--
-- Truncate table before insert `teacher_subjects`
--

TRUNCATE TABLE `teacher_subjects`;
--
-- Dumping data for table `teacher_subjects`
--


DROP TABLE IF EXISTS `messages`;
CREATE TABLE IF NOT EXISTS `messages` (
  `message_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message_text` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `parent_message_id` int(11) DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `FK_messages_parent` (`parent_message_id`),
  KEY `idx_messages_sender` (`sender_id`),
  KEY `idx_messages_receiver` (`receiver_id`),
  KEY `idx_messages_course` (`course_id`),
  KEY `idx_messages_read` (`is_read`),
  KEY `idx_messages_sent_at` (`sent_at`),
  KEY `idx_messages_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `messages`:
--

--
-- Truncate table before insert `messages`
--

TRUNCATE TABLE `messages`;
--
-- Dumping data for table `messages`
--


DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL DEFAULT 1,
  `sender_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `target_role` varchar(50) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `notification_type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `link` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`notification_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_target_role` (`target_role`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_notifications_uuid` (`uuid`),
  KEY `idx_institution_id` (`institution_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1015 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `notifications`:
--

--
-- Truncate table before insert `notifications`
--

TRUNCATE TABLE `notifications`;
--
-- Dumping data for table `notifications`
--



DROP TABLE IF EXISTS `notification_reads`;
CREATE TABLE IF NOT EXISTS `notification_reads` (
  `notification_read_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_read_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uk_notification_user` (`notification_id`,`user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_read_at` (`read_at`)
) ENGINE=InnoDB AUTO_INCREMENT=646 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `notification_reads`:
--

--
-- Truncate table before insert `notification_reads`
--

TRUNCATE TABLE `notification_reads`;
--
-- Dumping data for table `notification_reads`
--



--
-- Table structure for table `parents`
--

DROP TABLE IF EXISTS `parents`;
CREATE TABLE IF NOT EXISTS `parents` (
  `parent_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `guardian_id` varchar(50) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `prefers_email_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `prefers_sms_notifications` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`parent_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `guardian_id` (`guardian_id`),
  UNIQUE KEY `parent_id` (`parent_id`,`institution_id`,`user_id`,`guardian_id`) USING BTREE,
  KEY `idx_institution_id` (`institution_id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `parents`:
--

--
-- Truncate table before insert `parents`
--

TRUNCATE TABLE `parents`;
--
-- Dumping data for table `parents`
--


--
-- Table structure for table `parent_activity`
--

DROP TABLE IF EXISTS `parent_activity`;
CREATE TABLE IF NOT EXISTS `parent_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL COMMENT 'Institution the parent belongs to (via their child)',
  `performed_by` int(11) NOT NULL COMMENT 'user_id of the parent who performed the action',
  `activity_type` varchar(50) NOT NULL COMMENT 'e.g. grade_viewed, attendance_viewed, announcement_read, message_sent, login, logout',
  `description` varchar(500) NOT NULL COMMENT 'Human-readable summary shown in the activity list',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource affected: student, grade, attendance, announcement, etc.',
  `entity_id` int(11) DEFAULT NULL COMMENT 'Primary key of the affected entity (optional)',
  `meta` varchar(255) DEFAULT NULL COMMENT 'Extra context: child name, subject, class, etc.',
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_pa_institution_id` (`institution_id`),
  KEY `idx_pa_performed_by` (`performed_by`),
  KEY `idx_pa_activity_type` (`activity_type`),
  KEY `idx_pa_entity` (`entity_type`,`entity_id`),
  KEY `idx_pa_severity` (`severity`),
  KEY `idx_pa_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all meaningful actions performed by parents';

--
-- RELATIONSHIPS FOR TABLE `parent_activity`:
--

--
-- Truncate table before insert `parent_activity`
--

TRUNCATE TABLE `parent_activity`;
--
-- Triggers `parent_activity`
--
DROP TRIGGER IF EXISTS `trg_parent_activity_purge`;
DELIMITER $$
CREATE TRIGGER `trg_parent_activity_purge` AFTER INSERT ON `parent_activity` FOR EACH ROW BEGIN
  DELETE FROM `parent_activity`
  WHERE `created_at` < (NOW() - INTERVAL 90 DAY);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `parent_students`
--

DROP TABLE IF EXISTS `parent_students`;
CREATE TABLE IF NOT EXISTS `parent_students` (
  `parent_student_id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `relationship_type` varchar(50) DEFAULT 'Parent',
  `is_primary_contact` tinyint(1) DEFAULT 0,
  `can_pickup` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`parent_student_id`),
  UNIQUE KEY `unique_parent_student` (`parent_id`,`student_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_student_id` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `parent_students`:
--

--
-- Truncate table before insert `parent_students`
--


--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expiry_date` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `password_reset_tokens`:
--

--
-- Truncate table before insert `password_reset_tokens`
--

TRUNCATE TABLE `password_reset_tokens`;
--
-- Dumping data for table `password_reset_tokens`
--


--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `permission_id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permission_name` (`permission_name`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `permissions`:
--

--
-- Truncate table before insert `permissions`
--

TRUNCATE TABLE `permissions`;
--
-- Dumping data for table `permissions`
--



--
-- Table structure for table `programs`
--

DROP TABLE IF EXISTS `programs`;
CREATE TABLE IF NOT EXISTS `programs` (
  `program_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_years` int(11) DEFAULT 3,
  `status` varchar(20) DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`program_id`),
  UNIQUE KEY `unique_program_code_institution` (`program_code`,`institution_id`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_program_code` (`program_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `programs`:
--

--
-- Truncate table before insert `programs`
--

TRUNCATE TABLE `programs`;
--
-- Dumping data for table `programs`
--



--
-- Table structure for table `quizzes`
--

DROP TABLE IF EXISTS `quizzes`;
CREATE TABLE IF NOT EXISTS `quizzes` (
  `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL,
  `max_attempts` int(11) DEFAULT 1,
  `status` varchar(20) DEFAULT 'draft',
  `quiz_type` varchar(20) DEFAULT 'graded',
  `is_activated` tinyint(1) DEFAULT 0,
  `randomize_questions` tinyint(1) DEFAULT 0,
  `show_results` varchar(20) DEFAULT 'after_end',
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`quiz_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_section_id` (`section_id`),
  KEY `idx_status` (`status`),
  KEY `idx_is_activated` (`is_activated`),
  KEY `idx_start_date` (`start_date`),
  KEY `idx_end_date` (`end_date`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `quizzes`:
--

--
-- Truncate table before insert `quizzes`
--

TRUNCATE TABLE `quizzes`;
--
-- Dumping data for table `quizzes`
--


--
-- Table structure for table `quiz_questions`
--

DROP TABLE IF EXISTS `quiz_questions`;
CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `image_question` varchar(255) DEFAULT NULL,
  `question_type` varchar(50) NOT NULL,
  `points` int(11) DEFAULT 1,
  `difficulty` varchar(20) DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  `correct_answer` varchar(500) DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`question_id`),
  KEY `idx_quiz_id` (`quiz_id`),
  KEY `idx_question_type` (`question_type`),
  KEY `idx_order_index` (`order_index`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `quiz_questions`:
--

--
-- Truncate table before insert `quiz_questions`
--

TRUNCATE TABLE `quiz_questions`;
--
-- Dumping data for table `quiz_questions`
--



--
-- Table structure for table `quiz_question_options`
--

DROP TABLE IF EXISTS `quiz_question_options`;
CREATE TABLE IF NOT EXISTS `quiz_question_options` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) NOT NULL,
  `option_label` varchar(5) NOT NULL,
  `option_text` varchar(500) NOT NULL,
  `is_correct` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`option_id`),
  KEY `idx_question_id` (`question_id`)
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `quiz_question_options`:
--

--
-- Truncate table before insert `quiz_question_options`
--

TRUNCATE TABLE `quiz_question_options`;
--
-- Dumping data for table `quiz_question_options`
--


--
-- Table structure for table `quiz_submissions`
--

DROP TABLE IF EXISTS `quiz_submissions`;
CREATE TABLE IF NOT EXISTS `quiz_submissions` (
  `submission_id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attempt` int(11) DEFAULT 1,
  `score` decimal(10,2) DEFAULT NULL,
  `max_score` decimal(10,2) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'in_progress',
  `duration_minutes` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`submission_id`),
  KEY `FK_quiz_submissions_grader` (`graded_by`),
  KEY `idx_quiz_id` (`quiz_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_attempt` (`attempt`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `quiz_submissions`:
--

--
-- Truncate table before insert `quiz_submissions`
--

TRUNCATE TABLE `quiz_submissions`;
--
-- Dumping data for table `quiz_submissions`
--



--
-- Table structure for table `quiz_submission_answers`
--

DROP TABLE IF EXISTS `quiz_submission_answers`;
CREATE TABLE IF NOT EXISTS `quiz_submission_answers` (
  `submission_answer_id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer` text DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `points_earned` decimal(10,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`submission_answer_id`),
  KEY `idx_submission_id` (`submission_id`),
  KEY `idx_question_id` (`question_id`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `quiz_submission_answers`:
--

--
-- Truncate table before insert `quiz_submission_answers`
--

TRUNCATE TABLE `quiz_submission_answers`;
--
-- Dumping data for table `quiz_submission_answers`
--


--
-- Table structure for table `report_schedules`
--

DROP TABLE IF EXISTS `report_schedules`;
CREATE TABLE IF NOT EXISTS `report_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `schedule_name` varchar(150) NOT NULL,
  `report_type` varchar(30) NOT NULL,
  `frequency` varchar(20) NOT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_run_at` datetime DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_report_schedules_institution` (`institution_id`),
  KEY `idx_report_schedules_created_by` (`created_by`),
  KEY `idx_report_schedules_active_next` (`is_active`,`next_run_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `report_schedules`:
--

--
-- Truncate table before insert `report_schedules`
--

TRUNCATE TABLE `report_schedules`;
--
-- Dumping data for table `report_schedules`
--


--
-- Table structure for table `report_schedule_runs`
--

DROP TABLE IF EXISTS `report_schedule_runs`;
CREATE TABLE IF NOT EXISTS `report_schedule_runs` (
  `run_id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `run_at` datetime NOT NULL,
  `status` varchar(20) NOT NULL,
  `message` text DEFAULT NULL,
  `generated_payload` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`run_id`),
  KEY `idx_schedule_runs_schedule` (`schedule_id`),
  KEY `idx_schedule_runs_run_at` (`run_at`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `report_schedule_runs`:
--

--
-- Truncate table before insert `report_schedule_runs`
--

TRUNCATE TABLE `report_schedule_runs`;
--
-- Dumping data for table `report_schedule_runs`
--



--
-- Table structure for table `results`
--

DROP TABLE IF EXISTS `results`;
CREATE TABLE IF NOT EXISTS `results` (
  `result_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `class_score` decimal(5,2) DEFAULT NULL,
  `exam_score` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) DEFAULT NULL,
  `grade` varchar(2) DEFAULT NULL,
  `grade_point` decimal(3,2) DEFAULT NULL,
  `remark` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`result_id`),
  UNIQUE KEY `unique_student_course_semester` (`student_id`,`course_id`,`semester_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_course_id` (`course_id`),
  KEY `idx_subject_id` (`subject_id`),
  KEY `idx_semester_id` (`semester_id`),
  KEY `idx_grade` (`grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `results`:
--

--
-- Truncate table before insert `results`
--

TRUNCATE TABLE `results`;
-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `roles`:
--

--
-- Truncate table before insert `roles`
--

TRUNCATE TABLE `roles`;
--
-- Dumping data for table `roles`
--


--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_permission_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`role_permission_id`),
  UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  KEY `FK_role_permissions_permission` (`permission_id`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `role_permissions`:
--

--
-- Truncate table before insert `role_permissions`
--

TRUNCATE TABLE `role_permissions`;
--
-- Dumping data for table `role_permissions`
--



--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `applied_by` varchar(100) DEFAULT 'admin',
  `execution_time_ms` int(11) DEFAULT NULL,
  `success` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`version`),
  KEY `idx_applied_at` (`applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `schema_migrations`:
--

--
-- Truncate table before insert `schema_migrations`
--

TRUNCATE TABLE `schema_migrations`;
--
-- Dumping data for table `schema_migrations`
--



--
-- Table structure for table `semesters`
--

DROP TABLE IF EXISTS `semesters`;
CREATE TABLE IF NOT EXISTS `semesters` (
  `semester_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_name` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`semester_id`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_academic_year_id` (`academic_year_id`),
  KEY `idx_is_current` (`is_current`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `semesters`:
--

--
-- Truncate table before insert `semesters`
--

TRUNCATE TABLE `semesters`;
--
-- Dumping data for table `semesters`
--


--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
CREATE TABLE IF NOT EXISTS `students` (
  `student_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `student_id_number` varchar(50) NOT NULL,
  `alternative_email` varchar(100) DEFAULT NULL COMMENT 'alternative_email',
  `enrollment_date` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `parent_name` varchar(200) DEFAULT NULL,
  `parent_phone` varchar(20) DEFAULT NULL,
  `parent_email` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `completion_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `unique_student_id_institution` (`student_id_number`,`institution_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_student_id_number` (`student_id_number`),
  KEY `idx_students_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `students`:
--

--
-- Truncate table before insert `students`
--

TRUNCATE TABLE `students`;
--
-- Dumping data for table `students`
--



--
-- Table structure for table `student_activity`
--

DROP TABLE IF EXISTS `student_activity`;
CREATE TABLE IF NOT EXISTS `student_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL COMMENT 'Institution the student belongs to',
  `performed_by` int(11) NOT NULL COMMENT 'user_id of the student who performed the action',
  `activity_type` varchar(50) NOT NULL COMMENT 'e.g. assignment_submitted, material_viewed, grade_viewed, login, logout, attendance_checked',
  `description` varchar(500) NOT NULL COMMENT 'Human-readable summary shown in the activity list',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource affected: assignment, material, grade, course, etc.',
  `entity_id` int(11) DEFAULT NULL COMMENT 'Primary key of the affected entity (optional)',
  `meta` varchar(255) DEFAULT NULL COMMENT 'Extra context: subject name, assignment title, score, etc.',
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_sa_institution_id` (`institution_id`),
  KEY `idx_sa_performed_by` (`performed_by`),
  KEY `idx_sa_activity_type` (`activity_type`),
  KEY `idx_sa_entity` (`entity_type`,`entity_id`),
  KEY `idx_sa_severity` (`severity`),
  KEY `idx_sa_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all meaningful actions performed by students';

--
-- RELATIONSHIPS FOR TABLE `student_activity`:
--

--
-- Truncate table before insert `student_activity`
--

TRUNCATE TABLE `student_activity`;
--
-- Triggers `student_activity`
--
DROP TRIGGER IF EXISTS `trg_student_activity_purge`;
DELIMITER $$
CREATE TRIGGER `trg_student_activity_purge` AFTER INSERT ON `student_activity` FOR EACH ROW BEGIN
  DELETE FROM `student_activity`
  WHERE `created_at` < (NOW() - INTERVAL 90 DAY);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `student_material_completion`
--

DROP TABLE IF EXISTS `student_material_completion`;
CREATE TABLE IF NOT EXISTS `student_material_completion` (
  `completion_id` int(11) NOT NULL AUTO_INCREMENT,
  `material_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `first_opened_at` datetime DEFAULT current_timestamp(),
  `last_opened_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `preview_count` int(11) NOT NULL DEFAULT 0,
  `open_count` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT current_timestamp(),
  `completion_source` enum('open','preview','download') NOT NULL DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`completion_id`),
  UNIQUE KEY `uniq_material_student` (`material_id`,`student_id`),
  KEY `idx_material_id` (`material_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_completed_at` (`completed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `student_material_completion`:
--

--
-- Truncate table before insert `student_material_completion`
--

TRUNCATE TABLE `student_material_completion`;
--
-- Dumping data for table `student_material_completion`
--


--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
CREATE TABLE IF NOT EXISTS `subjects` (
  `subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `credits` int(11) DEFAULT 3,
  `is_core` tinyint(1) DEFAULT 0,
  `image` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`subject_id`),
  UNIQUE KEY `unique_subject_code_institution` (`subject_code`,`institution_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_subject_code` (`subject_code`),
  KEY `idx_is_core` (`is_core`),
  KEY `idx_subjects_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `subjects`:
--

--
-- Truncate table before insert `subjects`
--

TRUNCATE TABLE `subjects`;
--
-- Dumping data for table `subjects`
--


--
-- Table structure for table `subscription_plans`
--

DROP TABLE IF EXISTS `subscription_plans`;
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_name` varchar(100) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'GHS',
  `duration_months` int(11) NOT NULL DEFAULT 12,
  `max_students` int(11) NOT NULL DEFAULT 0,
  `max_teachers` int(11) NOT NULL DEFAULT 0,
  `features_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`plan_id`),
  UNIQUE KEY `uq_subscription_plans_name` (`plan_name`),
  KEY `idx_subscription_plans_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `subscription_plans`:
--

--
-- Truncate table before insert `subscription_plans`
--

TRUNCATE TABLE `subscription_plans`;
--
-- Dumping data for table `subscription_plans`
--


--
-- Table structure for table `superadmin_activity`
--

DROP TABLE IF EXISTS `superadmin_activity`;
CREATE TABLE IF NOT EXISTS `superadmin_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `performed_by` int(11) NOT NULL COMMENT 'user_id of the super admin who performed the action',
  `activity_type` varchar(50) NOT NULL COMMENT 'e.g. institution_created, admin_created, user_created, backup, update, security, login',
  `description` varchar(500) NOT NULL COMMENT 'Human-readable summary shown in the dashboard activity list',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Type of resource affected: institution, user, role, system, etc.',
  `entity_id` int(11) DEFAULT NULL COMMENT 'Primary key of the affected entity (optional)',
  `meta` varchar(255) DEFAULT NULL COMMENT 'Extra context shown in the activity list subtitle (e.g. institution name, username)',
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_performed_by` (`performed_by`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all meaningful actions performed by super admins';

--
-- RELATIONSHIPS FOR TABLE `superadmin_activity`:
--

--
-- Truncate table before insert `superadmin_activity`
--

TRUNCATE TABLE `superadmin_activity`;
--
-- Dumping data for table `superadmin_activity`
--


--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `settings_id` int(11) NOT NULL AUTO_INCREMENT,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`settings`)),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`settings_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `system_settings`:
--

--
-- Truncate table before insert `system_settings`
--

TRUNCATE TABLE `system_settings`;
--
-- Dumping data for table `system_settings`
--


--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
CREATE TABLE IF NOT EXISTS `teachers` (
  `teacher_id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `alternative_email` varchar(100) DEFAULT NULL COMMENT 'teaher_alternative_email',
  `bio` text DEFAULT NULL COMMENT 'bio of teacher',
  `hire_date` date DEFAULT NULL,
  `employment_end_date` date DEFAULT NULL,
  `qualification` varchar(200) DEFAULT NULL,
  `years_of_experience` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`teacher_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `unique_employee_id_institution` (`employee_id`,`institution_id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uuid_2` (`uuid`),
  UNIQUE KEY `uuid_3` (`uuid`),
  KEY `idx_institution_id` (`institution_id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_employment_end_date` (`employment_end_date`),
  KEY `idx_teachers_uuid` (`uuid`),
  KEY `idx_program_id` (`program_id`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `teachers`:
--

--
-- Truncate table before insert `teachers`
--

TRUNCATE TABLE `teachers`;
--
-- Dumping data for table `teachers`
--



