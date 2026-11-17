<?php

declare(strict_types=1);

use App\Middleware\RoleMiddleware;

$superAdminOnly = [
    'user_id' => 1,
    'role' => 'super_admin',
    'roles' => ['super_admin'],
    'is_super_admin' => 1,
    'institution_id' => 0,
    'permissions' => [],
];

$superAdminWithAdmin = [
    'user_id' => 2,
    'role' => 'super_admin',
    'roles' => ['admin', 'super_admin'],
    'is_super_admin' => 1,
    'institution_id' => 0,
    'permissions' => [],
];

$admin = [
    'user_id' => 10,
    'role' => 'admin',
    'roles' => ['admin'],
    'institution_id' => 1,
    'permissions' => ['users.view', 'users.manage'],
];

$teacher = [
    'user_id' => 11,
    'role' => 'teacher',
    'roles' => ['teacher'],
    'institution_id' => 1,
    'permissions' => ['grades.edit'],
];

$student = [
    'user_id' => 12,
    'role' => 'student',
    'roles' => ['student'],
    'institution_id' => 1,
    'permissions' => [],
];

$parent = [
    'user_id' => 13,
    'role' => 'parent',
    'roles' => ['parent'],
    'institution_id' => 1,
    'permissions' => [],
];

$multiRole = [
    'user_id' => 14,
    'role' => 'teacher',
    'roles' => ['teacher', 'parent'],
    'institution_id' => 1,
    'permissions' => [],
];

/**
 * Build a PHP snippet that runs in a clean child process. Response::forbidden()
 * calls exit(), so denial paths cannot be exercised inside the main test
 * process.
 */
function buildRoleMiddlewareSnippet(string $code): string
{
    $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

    return 'require ' . $autoload . ';' . $code;
}

test('RoleMiddleware treats super admins as satisfying admin gates only', function () use ($superAdminOnly, $superAdminWithAdmin) {
    $super = new RoleMiddleware($superAdminOnly);

    assertTrue($super->hasRole('super_admin'));
    assertTrue($super->hasRole('admin'));
    assertTrue($super->hasRole(['admin', 'super_admin']));
    assertFalse($super->hasRole('teacher'));
    assertFalse($super->hasRole('student'));
    assertFalse($super->hasRole('parent'));

    assertTrue($super->isAdmin());
    assertFalse($super->isTeacher());
    assertFalse($super->isStudent());
    assertFalse($super->isParent());

    $both = new RoleMiddleware($superAdminWithAdmin);
    assertTrue($both->isAdmin());
    assertFalse($both->isTeacher());
});

test('RoleMiddleware keeps ordinary role gates strict', function () use ($admin, $teacher, $student, $parent) {
    $adminMiddleware = new RoleMiddleware($admin);
    assertTrue($adminMiddleware->hasRole('admin'));
    assertFalse($adminMiddleware->hasRole('super_admin'));
    assertFalse($adminMiddleware->hasRole('teacher'));
    assertTrue($adminMiddleware->isAdmin());

    $teacherMiddleware = new RoleMiddleware($teacher);
    assertTrue($teacherMiddleware->isTeacher());
    assertFalse($teacherMiddleware->isAdmin());
    assertFalse($teacherMiddleware->hasRole('student'));

    $studentMiddleware = new RoleMiddleware($student);
    assertTrue($studentMiddleware->isStudent());
    assertFalse($studentMiddleware->isAdmin());
    assertFalse($studentMiddleware->isTeacher());

    $parentMiddleware = new RoleMiddleware($parent);
    assertTrue($parentMiddleware->isParent());
    assertFalse($parentMiddleware->isAdmin());
});

test('RoleMiddleware considers both the roles array and the singular role', function () use ($multiRole) {
    $middleware = new RoleMiddleware($multiRole);

    assertTrue($middleware->hasRole('teacher'));
    assertTrue($middleware->hasRole('parent'));
    assertFalse($middleware->hasRole('student'));

    // A singular role without a roles array still counts.
    $singularOnly = new RoleMiddleware(['role' => 'teacher']);
    assertTrue($singularOnly->hasRole('teacher'));
    assertFalse($singularOnly->isAdmin());

    // A scalar roles value is normalised to a single-element array.
    $scalarRoles = new RoleMiddleware(['roles' => 'student']);
    assertTrue($scalarRoles->hasRole('student'));
});

test('RoleMiddleware permission checks require every listed permission match', function () use ($admin, $teacher) {
    $adminMiddleware = new RoleMiddleware($admin);
    assertTrue($adminMiddleware->hasPermission('users.view'));
    assertTrue($adminMiddleware->hasPermission(['users.manage', 'missing.permission']));
    assertFalse($adminMiddleware->hasPermission('grades.edit'));
    assertFalse($adminMiddleware->hasPermission(['missing.permission']));

    $teacherMiddleware = new RoleMiddleware($teacher);
    assertTrue($teacherMiddleware->hasPermission('grades.edit'));
    assertFalse($teacherMiddleware->hasPermission('users.manage'));
});

test('RoleMiddleware::requireRole returns true for allowed roles', function () use ($teacher) {
    $middleware = new RoleMiddleware($teacher);

    assertTrue($middleware->requireRole('teacher'));
    assertTrue($middleware->requireRole(['admin', 'teacher']));
});

test('RoleMiddleware::requireRole denies with a 403 and exits', function () {
    $result = runPhpSnippet(buildRoleMiddlewareSnippet(
        '$user = ["role" => "teacher", "roles" => ["teacher"]];'
        . '$mw = new \\App\\Middleware\\RoleMiddleware($user);'
        . 'register_shutdown_function(function () { echo "CODE=" . http_response_code(); });'
        . '$mw->requireRole("admin");'
        . 'echo "RETURNED";'
    ));

    assertContains('Required role(s): admin', $result['stdout'], 'Denial message missing');
    assertContains('CODE=403', $result['stdout'], 'Denial should set HTTP 403');
    assertFalse(str_contains($result['stdout'], 'RETURNED'), 'requireRole must not return after a denial');
});

test('RoleMiddleware::requirePermission denies with a 403 and exits', function () {
    $result = runPhpSnippet(buildRoleMiddlewareSnippet(
        '$user = ["role" => "teacher", "roles" => ["teacher"], "permissions" => ["grades.edit"]];'
        . '$mw = new \\App\\Middleware\\RoleMiddleware($user);'
        . 'register_shutdown_function(function () { echo "CODE=" . http_response_code(); });'
        . '$mw->requirePermission("users.manage");'
        . 'echo "RETURNED";'
    ));

    assertContains('Required permission(s): users.manage', $result['stdout'], 'Denial message missing');
    assertContains('CODE=403', $result['stdout'], 'Denial should set HTTP 403');
    assertFalse(str_contains($result['stdout'], 'RETURNED'), 'requirePermission must not return after a denial');
});
