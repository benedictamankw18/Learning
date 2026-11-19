-- --------------------------------------------------------
-- Phase 1C: question bank taxonomy and immutable versions
--
-- Categories and topics are institution-scoped metadata. Topics may be tied
-- to a subject; NULL means a general topic usable by any subject.
--
-- question_versions holds an immutable copy of the content served to an
-- attempt. The application only ever INSERTs into it. Legacy attempts keep
-- using quiz_submission_questions.question_snapshot.
--
-- The metadata columns on `questions` and `question_version_id` on
-- `quiz_submission_questions` are declared in 05-question-bank.sql and
-- 01-lms.sql respectively; the migration adds them to existing databases.
-- No FOREIGN KEY constraints, consistent with the rest of the schema.
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `question_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_qcategory_institution_name` (`institution_id`,`name`),
  KEY `idx_qcategory_institution` (`institution_id`),
  KEY `idx_qcategory_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_topics` (
  `topic_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`topic_id`),
  UNIQUE KEY `uq_qtopic_scope` (`institution_id`,`subject_id`,`name`),
  KEY `idx_qtopic_institution` (`institution_id`),
  KEY `idx_qtopic_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_versions` (
  `version_id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) NOT NULL,
  `institution_id` int(11) NOT NULL,
  `version_number` int(11) NOT NULL,
  `content_json` json NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`version_id`),
  UNIQUE KEY `uq_question_version_number` (`question_id`,`version_number`),
  KEY `idx_qversion_question` (`question_id`),
  KEY `idx_qversion_institution` (`institution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
