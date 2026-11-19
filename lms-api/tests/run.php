<?php

/**
 * Standalone test runner for the LMS API.
 *
 * Usage:
 *   php lms-api/tests/run.php
 *   php lms-api/tests/run.php --db-host=localhost --db-user=root --db-pass=
 *
 * Exit code 0 means every test passed or skipped. Exit code 1 means at least
 * one test failed.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/AccessControlTest.php';
require_once __DIR__ . '/RoleMiddlewareTest.php';
require_once __DIR__ . '/InstitutionIsolationTest.php';
require_once __DIR__ . '/JwtConfigTest.php';
require_once __DIR__ . '/RefreshTokenRotationTest.php';
require_once __DIR__ . '/QuizShuffleTest.php';
require_once __DIR__ . '/QuizBacktrackingTest.php';
require_once __DIR__ . '/QuestionCsvTest.php';
require_once __DIR__ . '/StaticChecksTest.php';

exit(runTests());
