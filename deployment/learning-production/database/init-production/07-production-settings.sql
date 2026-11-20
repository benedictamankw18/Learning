-- Production reference data for a fresh install.
-- No demo institution, no demo users, no committed password hashes.
-- Public registration is disabled.

-- Reference subscription plans (configuration data, not user data).
INSERT INTO `subscription_plans` (`plan_id`, `plan_name`, `amount`, `currency`, `duration_months`, `max_students`, `max_teachers`, `features_json`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Basic', 5000.00, 'GHS', 12, 500, 50, '["Student Management", "Teacher Management", "Attendance Tracking", "Grade Management", "Reports", "Parent Portal"]', 1, 10, NOW(), NOW()),
(2, 'Standard', 10000.00, 'GHS', 12, 1000, 100, '["All Basic Features", "Advanced Analytics", "SMS Notifications", "Email Integration", "Timetable Management", "Library Management"]', 1, 20, NOW(), NOW()),
(3, 'Premium', 20000.00, 'GHS', 12, 5000, 500, '["All Standard Features", "Unlimited Students/Teachers", "API Access", "Custom Branding", "Priority Support", "Mobile App Access", "Advanced Security"]', 1, 30, NOW(), NOW());

-- System settings: registration OFF, correct LAN site URL, mail left for you to configure.
INSERT INTO `system_settings` (`settings_id`, `settings`, `updated_at`) VALUES
(1, '{"site_name":"Learning Management System","site_url":"http://lms.geslab.local","app_version":"1.0.0","timezone":"Africa/Accra","default_language":"en","date_format":"Y-m-d","time_format":"H:i:s","max_upload_size":"104857600","session_timeout":"3600","allow_registration":0,"require_verification":0,"smtp_host":"","smtp_port":587,"smtp_username":"","from_address":"noreply@lms.geslab.local","from_name":"LMS System","enable_notifications":1,"enable_email_notifications":0,"enable_sms_notifications":0,"maintenance_mode":0,"ga_id":"","integrations_note":"","updated_at":"2026-11-20 00:00:00"}', NOW());

-- 01-lms.sql disabled foreign key checks; restore them at the end of the init set.
SET FOREIGN_KEY_CHECKS=1;
