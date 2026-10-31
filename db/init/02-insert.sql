-- ===========================================================================
-- DEMO / LOCAL DEVELOPMENT DATA ONLY
--
-- This file creates demo institutions and a fixed `superadmin` account whose
-- password hash is committed to the repository. Never expose an environment
-- seeded with this file to the internet.
--
-- Before using this data anywhere beyond a local machine, rotate the account:
--   php lms-api/scripts/create_superadmin.php --email=you@example.com --force
-- and review/remove the demo institutions, users, and records below.
-- ===========================================================================

INSERT INTO `institution_settings` (`setting_id`, `institution_id`, `school_name`, `motto`, `description`, `vision`, `mission`, `logo_url`, `banner_url`, `theme_primary_color`, `theme_secondary_color`, `timezone`, `academic_year_start_month`, `academic_year_end_month`, `grading_system`, `locale`, `currency`, `date_format`, `time_format`, `allow_parent_registration`, `allow_student_self_enrollment`, `require_email_verification`, `meta`, `custom_css`, `custom_footer`, `social_facebook`, `social_twitter`, `social_instagram`, `social_linkedin`, `updated_at`) VALUES
(1, 1, 'Accra Senior High School vvddre', 'Excellence Through Knowledge with fuck you', 'A leading Senior High School in Accra, Ghana, committed to academic excellence and holistic development.', 'To be the premier institution for secondary education in Ghana, producing well-rounded graduates.', 'To provide quality education that empowers students to excel academically and contribute positively to society.', '/uploads/institutions/69ff37243ee80_1778333476.jpg', '/uploads/institutions/69ff374137ab1_1778333505.jpg', '#ff4d00', '#e100ff', 'Africa/Accra', 1, 9, 'WAEC', 'en_US', 'GHS', 'DD/MM/YYYY', '12', 0, 0, 0, '{\"is_timetable_published\": \"1\"}', '', '', '', '', '', '', '2026-05-09 13:31:50');
-- --------------------------------------------------------

INSERT INTO `permissions` (`permission_id`, `permission_name`, `description`, `created_at`) VALUES
(1, 'manage_institutions', 'Create, update, delete institutions (Super Admin only)', '2026-03-01 18:17:08'),
(2, 'manage_subscriptions', 'Manage institution subscriptions (Super Admin only)', '2026-03-01 18:17:08'),
(3, 'view_all_institutions', 'View all institutions on platform (Super Admin only)', '2026-03-01 18:17:08'),
(4, 'manage_users', 'Create, update, delete users', '2026-03-01 18:17:08'),
(5, 'manage_courses', 'Create, update, delete class subjects', '2026-03-01 18:17:08'),
(6, 'manage_assessments', 'Create, update, grade assessments', '2026-03-01 18:17:08'),
(7, 'view_reports', 'View system reports and analytics', '2026-03-01 18:17:08'),
(8, 'manage_attendance', 'Mark and manage attendance', '2026-03-01 18:17:08'),
(45, 'manage_grades', 'Input and modify student grades', '2026-03-03 21:21:30'),
(46, 'view_students', 'View student information', '2026-03-03 21:21:30'),
(47, 'manage_announcements', 'Create and manage announcements', '2026-03-03 21:21:30'),
(48, 'send_messages', 'Send messages to other users', '2026-03-03 21:21:30'),
(49, 'Teacher_checked', 'ghhwww', '2026-03-14 09:25:36');

-- --------------------------------------------------------

INSERT INTO `roles` (`role_id`, `role_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'Platform Super Administrator - Manages all institutions', '2026-03-01 18:17:07', '2026-03-01 18:17:07'),
(2, 'admin', 'Institution Administrator - Manages single institution', '2026-03-01 18:17:07', '2026-03-01 18:17:07'),
(3, 'teacher', 'Teacher/Instructor', '2026-03-01 18:17:07', '2026-03-01 18:17:07'),
(4, 'student', 'Student', '2026-03-01 18:17:07', '2026-03-01 18:17:07'),
(5, 'parent', 'Parent/Guardian', '2026-03-01 18:17:07', '2026-03-01 18:17:07');

-- --------------------------------------------------------

INSERT INTO `role_permissions` (`role_permission_id`, `role_id`, `permission_id`, `created_at`) VALUES
(4, 1, 1, '2026-03-01 18:17:08'),
(5, 1, 2, '2026-03-01 18:17:08'),
(6, 1, 4, '2026-03-01 18:17:08'),
(7, 1, 3, '2026-03-01 18:17:08'),
(8, 1, 7, '2026-03-01 18:17:08'),
(16, 2, 6, '2026-03-01 18:17:08'),
(17, 2, 8, '2026-03-01 18:17:08'),
(18, 2, 5, '2026-03-01 18:17:08'),
(19, 2, 4, '2026-03-01 18:17:08'),
(20, 2, 7, '2026-03-01 18:17:08'),
(23, 3, 6, '2026-03-01 18:17:08'),
(24, 3, 8, '2026-03-01 18:17:08'),
(25, 3, 5, '2026-03-01 18:17:08'),
(26, 1, 11, '2026-03-03 20:59:25'),
(27, 1, 9, '2026-03-03 20:59:25'),
(28, 1, 12, '2026-03-03 20:59:25'),
(29, 1, 10, '2026-03-03 20:59:25'),
(33, 2, 11, '2026-03-03 20:59:25'),
(34, 2, 9, '2026-03-03 20:59:25'),
(35, 2, 12, '2026-03-03 20:59:25'),
(36, 2, 10, '2026-03-03 20:59:25'),
(40, 3, 9, '2026-03-03 20:59:25'),
(41, 3, 12, '2026-03-03 20:59:25'),
(42, 3, 10, '2026-03-03 20:59:25'),
(43, 1, 23, '2026-03-03 21:02:44'),
(44, 1, 21, '2026-03-03 21:02:44'),
(45, 1, 24, '2026-03-03 21:02:44'),
(46, 1, 22, '2026-03-03 21:02:44'),
(50, 2, 23, '2026-03-03 21:02:44'),
(51, 2, 21, '2026-03-03 21:02:44'),
(52, 2, 24, '2026-03-03 21:02:44'),
(53, 2, 22, '2026-03-03 21:02:44'),
(57, 3, 21, '2026-03-03 21:02:44'),
(58, 3, 24, '2026-03-03 21:02:44'),
(59, 3, 22, '2026-03-03 21:02:44'),
(60, 1, 35, '2026-03-03 21:03:05'),
(61, 1, 33, '2026-03-03 21:03:05'),
(62, 1, 36, '2026-03-03 21:03:05'),
(63, 1, 34, '2026-03-03 21:03:05'),
(67, 2, 35, '2026-03-03 21:03:05'),
(68, 2, 33, '2026-03-03 21:03:05'),
(69, 2, 36, '2026-03-03 21:03:05'),
(70, 2, 34, '2026-03-03 21:03:05'),
(74, 3, 33, '2026-03-03 21:03:05'),
(75, 3, 36, '2026-03-03 21:03:05'),
(76, 3, 34, '2026-03-03 21:03:05'),
(77, 1, 47, '2026-03-03 21:21:31'),
(79, 1, 48, '2026-03-03 21:21:31'),
(84, 2, 47, '2026-03-03 21:21:31'),
(85, 2, 45, '2026-03-03 21:21:31'),
(86, 2, 48, '2026-03-03 21:21:31'),
(87, 2, 46, '2026-03-03 21:21:31'),
(91, 3, 45, '2026-03-03 21:21:31'),
(92, 3, 48, '2026-03-03 21:21:31'),
(93, 3, 46, '2026-03-03 21:21:31'),
(95, 2, 2, '2026-03-14 09:01:03'),
(96, 5, 48, '2026-03-14 09:02:52'),
(97, 5, 7, '2026-03-14 09:02:52'),
(98, 5, 46, '2026-03-14 09:02:52'),
(99, 4, 48, '2026-03-14 09:03:33'),
(100, 4, 7, '2026-03-14 09:03:33'),
(101, 4, 46, '2026-03-14 09:03:33'),
(102, 3, 7, '2026-03-14 09:04:17');

-- --------------------------------------------------------


-- --------------------------------------------------------


INSERT INTO `subscription_plans` (`plan_id`, `plan_name`, `amount`, `currency`, `duration_months`, `max_students`, `max_teachers`, `features_json`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Basic', 5000.00, 'GHS', 12, 500, 50, '[\"Student Management\", \"Teacher Management\", \"Attendance Tracking\", \"Grade Management\", \"Reports\", \"Parent Portal\"]', 1, 10, '2026-03-15 00:40:47', '2026-03-15 00:40:47'),
(2, 'Standard', 10000.00, 'GHS', 12, 1000, 100, '[\"All Basic Features\", \"Advanced Analytics\", \"SMS Notifications\", \"Email Integration\", \"Timetable Management\", \"Library Management\"]', 1, 20, '2026-03-15 00:40:47', '2026-03-15 00:40:47'),
(3, 'Premium', 20000.00, 'GHS', 12, 5000, 500, '[\"All Standard Features\", \"Unlimited Students/Teachers\", \"API Access\", \"Custom Branding\", \"Priority Support\", \"Mobile App Access\", \"Advanced Security\"]', 1, 30, '2026-03-15 00:40:47', '2026-03-15 00:40:47');



INSERT INTO `system_settings` (`settings_id`, `settings`, `updated_at`) VALUES
(1, '{\"site_name\":\"Ghana SHS LMS\",\"site_url\":\"https:\\/\\/ghanashslms.edu.gh\",\"app_version\":\"1.0.0\",\"timezone\":\"Africa\\/Accra\",\"default_language\":\"en\",\"date_format\":\"Y-m-d\",\"time_format\":\"H:i:s\",\"max_upload_size\":\"10485760\",\"session_timeout\":\"3600\",\"allow_registration\":1,\"require_verification\":1,\"smtp_host\":\"smtp.ghanashslms.edu.gh\",\"smtp_port\":587,\"smtp_username\":\"noreply@ghanashslms.edu.gh\",\"from_address\":\"noreply@ghanashslms.edu.gh\",\"from_name\":\"Ghana SHS LMS\",\"enable_notifications\":1,\"enable_email_notifications\":1,\"enable_sms_notifications\":0,\"maintenance_mode\":0,\"ga_id\":\"\",\"integrations_note\":\"\",\"updated_at\":\"2026-05-19 03:29:35\"}', '2026-05-19 03:29:55');

-- --------------------------------------------------------

INSERT INTO `users` (`user_id`, `uuid`, `institution_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`, `phone_number`, `address`, `city`, `region`, `date_of_birth`, `title`, `gender`, `is_super_admin`, `is_active`, `created_at`, `updated_at`, `deleted_at`, `profile_photo`) VALUES
(1, '1bfe8992-16ae-11f1-9c28-10653022c2a0', NULL, 'superadmin', 'benedictamankwa18@gmail.com', '$2y$10$CAU/aeWxSNiY8Af7vaQn0.dy2ibLA/zvSLxGoBaT5pWN0kzLdl36O', 'Platform', 'Super Administrator', '+233 30 000 0000', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2025-12-04 21:21:31', '2026-03-09 09:08:07', NULL, NULL);


INSERT INTO `user_roles` (`user_role_id`, `user_id`, `role_id`, `assigned_at`) VALUES (1, 1, 1, '2026-03-03 21:21:31');

SET FOREIGN_KEY_CHECKS=1;
COMMIT;

