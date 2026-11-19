-- --------------------------------------------------------
-- Phase 1B: central question bank
--
-- Question content lives once in `questions`, with its answer options in
-- `question_bank_options`. A quiz references bank rows through
-- `quiz_question_links`, which stores the per-quiz order and an optional
-- points override.
--
-- The legacy `quiz_questions` / `quiz_question_options` tables stay in place
-- during the transition. `migrations/create_question_bank.php` copies every
-- row across while keeping the original question and option IDs, so nothing
-- that already references a question has to be rewritten.
--
-- No FOREIGN KEY constraints are declared here on purpose: the canonical
-- schema does not use them, and legacy quiz data is known to contain orphaned
-- option rows that a strict constraint would reject.
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `institution_id` int(11) NOT NULL,
  `owner_user_id` int(11) DEFAULT NULL COMMENT 'NULL means the institution owns the question',
  `question_text` text NOT NULL,
  `image_question` varchar(255) DEFAULT NULL,
  `question_type` varchar(50) NOT NULL,
  `points` int(11) NOT NULL DEFAULT 1,
  `difficulty` varchar(20) DEFAULT 'medium',
  `explanation` text DEFAULT NULL,
  `correct_answer` varchar(500) DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`question_id`),
  KEY `idx_questions_institution` (`institution_id`),
  KEY `idx_questions_owner` (`owner_user_id`),
  KEY `idx_questions_type` (`question_type`),
  KEY `idx_questions_archived` (`is_archived`),
  KEY `idx_questions_institution_type` (`institution_id`,`question_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_bank_options` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) NOT NULL,
  `option_label` varchar(5) NOT NULL,
  `option_text` varchar(500) NOT NULL,
  `is_correct` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`option_id`),
  KEY `idx_qbo_question_id` (`question_id`),
  KEY `idx_qbo_question_label` (`question_id`,`option_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quiz_question_links` (
  `quiz_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `points_override` int(11) DEFAULT NULL COMMENT 'NULL uses questions.points',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`quiz_id`,`question_id`),
  KEY `idx_qql_quiz_position` (`quiz_id`,`position`),
  KEY `idx_qql_question_id` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
