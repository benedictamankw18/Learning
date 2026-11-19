<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repositories\StudentRepository;
use App\Repositories\UserRepository;
use App\Utils\StudentId;
use App\Utils\UuidHelper;

/**
 * Student index numbers are derived from the institution, not a hardcoded
 * "STU" prefix:
 *
 *   "PRESBYTERIAN SENIOR HIGH SCHOOL, TEMA" -> PSHST-2026-0001
 */
test('Student ID prefix uses the institution initials', function () {
    assertSame(
        'PSHST',
        StudentId::prefixFromInstitution('PRESBYTERIAN SENIOR HIGH SCHOOL, TEMA'),
        'The school name must produce its initials'
    );
    assertSame(
        'PSHST',
        StudentId::prefixFromInstitution('Presbyterian Senior High School, Tema'),
        'Derivation must be case-insensitive'
    );
    assertSame('TSS', StudentId::prefixFromInstitution('Tema Secondary School'));
    assertSame('ABCDEF', StudentId::prefixFromInstitution('A B C D E F G H'), 'Prefixes are capped at six characters');
});

test('Student ID prefix falls back to the name, then the code, then SHS', function () {
    assertSame('PRESEC', StudentId::prefixFromInstitution('PRESEC'));
    assertSame('ABC123', StudentId::prefixFromInstitution('', 'abc-123'));
    assertSame('SHS', StudentId::prefixFromInstitution('', ''));
    assertSame('SHS', StudentId::prefixFromInstitution(null, null));
});

test('Student ID formatting and parsing round-trip', function () {
    assertSame('PSHST-2026-0001', StudentId::format('PSHST', 2026, 1));
    assertSame('PSHST-2026-0042', StudentId::format('pshst', 2026, 42), 'Prefixes are normalised to uppercase');
    assertSame(42, StudentId::parseSequence('PSHST-2026-0042', 'PSHST', 2026));
    assertSame(0, StudentId::parseSequence('STU-2026-0042', 'PSHST', 2026), 'Legacy STU IDs must not continue the school series');
    assertSame(0, StudentId::parseSequence('PSHST-2025-0042', 'PSHST', 2026), 'Sequences reset each year');
});

test('Student ID generation no longer hardcodes the STU prefix', function () {
    $root = dirname(__DIR__);
    $projectRoot = dirname($root);

    $userRepository = (string) file_get_contents($root . '/src/Repositories/UserRepository.php');
    assertFalse(
        str_contains($userRepository, 'STU-'),
        'The users import path must derive the prefix from the institution'
    );
    assertContains('StudentId::prefixFromInstitution', $userRepository);

    $controller = (string) file_get_contents($root . '/src/Controllers/StudentController.php');
    assertContains('StudentId::format', $controller);
    assertContains('$this->nextStudentIdNumber', $controller);
    assertContains(
        "->required(['username', 'email', 'password', 'first_name', 'last_name'])",
        $controller,
        'student_id_number must stay optional so it can be auto-generated'
    );

    $studentsJs = (string) file_get_contents($projectRoot . '/lms-frontend/admin/js/students.js');
    assertFalse(
        str_contains($studentsJs, 'SHS2024001'),
        'The CSV template must not ship a fake hardcoded index number'
    );
    assertContains("const REQUIRED = ['first_name', 'last_name', 'email', 'username', 'password'];", $studentsJs);
});

test('Student ID sequence increments per institution and year', function () {
    if (!testDatabase()) {
        skip('database is not reachable');
    }

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Throwable $e) {
        skip('database connection is not available');
    }

    if ($db->inTransaction()) {
        skip('another test left a transaction open');
    }

    $year = (int) date('Y');
    $suffix = strtoupper(bin2hex(random_bytes(3)));

    $db->beginTransaction();
    try {
        $institutionStmt = $db->prepare("
            INSERT INTO institutions (uuid, institution_code, institution_name)
            VALUES (:uuid, :code, :name)
        ");
        $institutionStmt->execute([
            'uuid' => UuidHelper::generate(),
            'code' => 'TST' . $suffix,
            'name' => 'Test Senior High School',
        ]);
        $institutionId = (int) $db->lastInsertId();

        $userRepository = new UserRepository();
        $generate = new ReflectionMethod($userRepository, 'generateStudentIdNumber');
        $generate->setAccessible(true);

        $first = 'TSHS-' . $year . '-0001';
        assertSame($first, $generate->invoke($userRepository, $institutionId), 'A fresh institution starts at 0001');

        $studentStmt = $db->prepare("
            INSERT INTO students (uuid, institution_id, user_id, student_id_number, enrollment_date, status)
            VALUES (:uuid, :institution_id, :user_id, :student_id_number, :enrollment_date, :status)
        ");
        $studentStmt->execute([
            'uuid' => UuidHelper::generate(),
            'institution_id' => $institutionId,
            'user_id' => 990000000 + random_int(1, 999999),
            'student_id_number' => $first,
            'enrollment_date' => date('Y-m-d'),
            'status' => 'active',
        ]);

        assertSame(
            'TSHS-' . $year . '-0002',
            $generate->invoke($userRepository, $institutionId),
            'The next ID increments the sequence'
        );

        $studentRepository = new StudentRepository();
        assertSame(
            2,
            $studentRepository->getNextIdSequence($institutionId, 'TSHS', $year),
            'StudentRepository must agree with the generator'
        );

        assertThrows(function () use ($studentStmt, $institutionId, $first) {
            $studentStmt->execute([
                'uuid' => UuidHelper::generate(),
                'institution_id' => $institutionId,
                'user_id' => 990000000 + random_int(1, 999999),
                'student_id_number' => $first,
                'enrollment_date' => date('Y-m-d'),
                'status' => 'active',
            ]);
        }, PDOException::class);
    } finally {
        $db->rollBack();
    }
});
