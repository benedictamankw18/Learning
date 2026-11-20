<?php
/**
 * Migration: scope the question bank to teacher-private ownership.
 * Run from the repository root:
 *   php lms-api/migrations/scope_question_bank_to_teachers.php
 *
 * This is the one-time ownership normalization for the private bank:
 *   1. adds owner_user_id to the existing taxonomy tables;
 *   2. makes taxonomy names unique per teacher instead of per institution;
 *   3. assigns every ownerless question to benedict.teacher (user 11);
 *   4. marks existing rows private for legacy compatibility.
 *
 * Rows that already have an owner keep that owner. The application has no API
 * that changes question or taxonomy ownership; this migration is the only
 * place legacy ownerless rows are reassigned.
 *
 * Approval/review columns are retained in the schema for existing data, but
 * the application no longer reads or writes them as part of any workflow.
 */

function qprivate_env(string $key, $default = null)
{
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return $default;
}

$envFile = __DIR__ . '/../.env';
$envProvided = getenv('DB_HOST') !== false || getenv('DB_NAME') !== false;
if (!$envProvided && file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') && strpos($line, '#') === false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = trim($value);
            }
        }
    }
}

/** @return array<string,true> */
function qprivate_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ");
    $stmt->execute(['table' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[(string) $column] = true;
    }

    return $columns;
}

/** @return array<string,array<int,string>> */
function qprivate_indexes(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT INDEX_NAME, COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
        ORDER BY INDEX_NAME ASC, SEQ_IN_INDEX ASC
    ");
    $stmt->execute(['table' => $table]);

    $indexes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $indexes[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
    }

    return $indexes;
}

function qprivate_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ");
    $stmt->execute(['table' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

try {
    $host = qprivate_env('DB_HOST', 'localhost');
    $dbname = qprivate_env('DB_NAME', 'lms');
    $user = qprivate_env('DB_USER', 'root');
    $pass = qprivate_env('DB_PASS', '');
    $port = qprivate_env('DB_PORT', 3306);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Running migration: scope question bank to teacher-private ownership...\n";

    if (!qprivate_table_exists($pdo, 'questions') || !qprivate_table_exists($pdo, 'users')) {
        echo "The question bank is missing. Run create_question_bank.php first.\n";
        exit(1);
    }

    $fallbackOwnerId = 11;

    // A fresh database may have no legacy rows at all. In that case there is
    // nothing to reassign, so the fallback teacher does not have to exist.
    $needsFallbackOwner = false;
    $initialQuestionColumns = qprivate_columns($pdo, 'questions');
    if (isset($initialQuestionColumns['owner_user_id'])) {
        $ownerlessQuestions = (int) $pdo
            ->query('SELECT COUNT(*) FROM questions WHERE owner_user_id IS NULL OR owner_user_id = 0')
            ->fetchColumn();
        if ($ownerlessQuestions > 0) {
            $needsFallbackOwner = true;
        }
    }

    $taxonomyTables = ['question_categories', 'question_topics'];
    foreach ($taxonomyTables as $table) {
        if (!qprivate_table_exists($pdo, $table)) {
            continue;
        }

        $columns = qprivate_columns($pdo, $table);
        if (isset($columns['owner_user_id'])) {
            $countSql = "SELECT COUNT(*) FROM `{$table}` WHERE owner_user_id IS NULL OR owner_user_id = 0";
        } else {
            $countSql = "SELECT COUNT(*) FROM `{$table}`";
        }
        if ((int) $pdo->query($countSql)->fetchColumn() > 0) {
            $needsFallbackOwner = true;
        }
    }

    if ($needsFallbackOwner) {
        $ownerStmt = $pdo->prepare("SELECT username, email FROM users WHERE user_id = :id LIMIT 1");
        $ownerStmt->execute(['id' => $fallbackOwnerId]);
        $fallbackOwner = $ownerStmt->fetch();
        if (!$fallbackOwner) {
            echo "Fallback teacher user 11 was not found. Refusing to reassign legacy questions.\n";
            exit(1);
        }
        echo "  -> fallback owner: user {$fallbackOwnerId}\n";
    } else {
        echo "  -> no legacy rows need fallback ownership\n";
    }

    // 1. Taxonomy ownership. DDL runs outside the data transaction because
    //    MySQL implicitly commits DDL statements.
    $ownerColumnsAdded = 0;
    foreach ($taxonomyTables as $table) {
        if (!qprivate_table_exists($pdo, $table)) {
            continue;
        }

        $columns = qprivate_columns($pdo, $table);
        if (!isset($columns['owner_user_id'])) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `owner_user_id` int(11) DEFAULT NULL AFTER `institution_id`");
            $ownerColumnsAdded++;
            $columns['owner_user_id'] = true;
        }

        $pdo->exec("UPDATE `{$table}` SET owner_user_id = {$fallbackOwnerId}
                    WHERE owner_user_id IS NULL OR owner_user_id = 0");
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `owner_user_id` int(11) NOT NULL");
    }
    echo "  -> taxonomy owner columns added: {$ownerColumnsAdded}\n";

    // 2. Taxonomy uniqueness is per owner, not per institution.
    $categoryIndexes = qprivate_indexes($pdo, 'question_categories');
    if (isset($categoryIndexes['uq_qcategory_institution_name'])) {
        $pdo->exec('ALTER TABLE `question_categories` DROP INDEX `uq_qcategory_institution_name`');
    }
    if (!isset($categoryIndexes['uq_qcategory_owner_name'])) {
        $pdo->exec('ALTER TABLE `question_categories`
                    ADD UNIQUE KEY `uq_qcategory_owner_name` (`institution_id`,`owner_user_id`,`name`)');
    }

    $topicIndexes = qprivate_indexes($pdo, 'question_topics');
    if (isset($topicIndexes['uq_qtopic_scope'])) {
        $pdo->exec('ALTER TABLE `question_topics` DROP INDEX `uq_qtopic_scope`');
    }
    if (!isset($topicIndexes['uq_qtopic_owner_scope'])) {
        $pdo->exec('ALTER TABLE `question_topics`
                    ADD UNIQUE KEY `uq_qtopic_owner_scope` (`institution_id`,`owner_user_id`,`subject_id`,`name`)');
    }
    echo "  -> taxonomy unique keys are owner-scoped\n";

    // 3. Question ownership and private semantics.
    $pdo->beginTransaction();

    $assignStmt = $pdo->prepare("
        UPDATE questions
        SET owner_user_id = :owner_user_id
        WHERE owner_user_id IS NULL OR owner_user_id = 0
    ");
    $assignStmt->execute(['owner_user_id' => $fallbackOwnerId]);
    $assigned = $assignStmt->rowCount();

    $questionColumns = qprivate_columns($pdo, 'questions');
    $updates = [];
    $privateParams = [];
    if (isset($questionColumns['visibility'])) {
        $updates[] = "visibility = 'private'";
    }
    if (isset($questionColumns['approval_status'])) {
        $updates[] = "approval_status = 'draft'";
    }
    if ($updates !== []) {
        $privateStmt = $pdo->exec('UPDATE questions SET ' . implode(', ', $updates));
        $privatized = $privateStmt === false ? 0 : $privateStmt;
    } else {
        $privatized = 0;
    }

    $pdo->commit();

    echo "  -> ownerless questions assigned: {$assigned}\n";
    echo "  -> questions marked private: {$privatized}\n";
    echo "Migration completed successfully.\n";
    exit(0);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
