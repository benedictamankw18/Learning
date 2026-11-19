-- --------------------------------------------------------
-- Per-attempt question order (quiz "Shuffle Questions")
--
-- One row per question served in a quiz submission. Rows are written once
-- when an attempt starts, so refreshing, switching tabs, or resuming an
-- attempt never changes the sequence. A new attempt gets a new order.
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `quiz_submission_questions` (
  `submission_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `question_snapshot` json DEFAULT NULL,
  `question_version_id` int(11) DEFAULT NULL COMMENT 'Phase 1C: immutable version served for this attempt',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`submission_id`,`question_id`),
  KEY `idx_qsq_submission_position` (`submission_id`,`position`),
  KEY `idx_qsq_question_id` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
