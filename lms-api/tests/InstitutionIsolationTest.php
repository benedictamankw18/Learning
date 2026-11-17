<?php

declare(strict_types=1);

use App\Security\AccessControl;

$superAdmin = [
    'user_id' => 1,
    'role' => 'super_admin',
    'roles' => ['super_admin'],
    'is_super_admin' => 1,
    'institution_id' => 0,
];

$superAdminOnly = [
    'user_id' => 2,
    'role' => 'super_admin',
    'roles' => [],
    'is_super_admin' => 1,
    'institution_id' => 0,
];

$adminA = [
    'user_id' => 10,
    'role' => 'admin',
    'roles' => ['admin'],
    'institution_id' => 1,
];

$adminB = [
    'user_id' => 20,
    'role' => 'admin',
    'roles' => ['admin'],
    'institution_id' => 2,
];

$adminWithoutInstitution = [
    'user_id' => 30,
    'role' => 'admin',
    'roles' => ['admin'],
    'institution_id' => 0,
];

$teacherA = [
    'user_id' => 11,
    'role' => 'teacher',
    'roles' => ['teacher'],
    'institution_id' => 1,
];

$teacherB = [
    'user_id' => 21,
    'role' => 'teacher',
    'roles' => ['teacher'],
    'institution_id' => 2,
];

$studentA = [
    'user_id' => 12,
    'role' => 'student',
    'roles' => ['student'],
    'institution_id' => 1,
];

$studentB = [
    'user_id' => 22,
    'role' => 'student',
    'roles' => ['student'],
    'institution_id' => 2,
];

$parentA = [
    'user_id' => 13,
    'role' => 'parent',
    'roles' => ['parent'],
    'institution_id' => 1,
];

test('Institution A admin can view and manage only institution A users', function () use ($adminA, $teacherA, $studentA, $parentA, $adminB, $teacherB, $studentB) {
    foreach ([$teacherA, $studentA, $parentA] as $target) {
        assertTrue(AccessControl::canViewUser($adminA, $target), 'Same-institution view should be allowed');
        assertTrue(AccessControl::canManageUser($adminA, $target), 'Same-institution management should be allowed');
    }

    assertTrue(AccessControl::canViewUser($adminA, $adminA));
    assertTrue(AccessControl::canManageUser($adminA, $adminA));

    foreach ([$adminB, $teacherB, $studentB] as $target) {
        assertFalse(AccessControl::canViewUser($adminA, $target), 'Cross-institution view must be denied');
        assertFalse(AccessControl::canManageUser($adminA, $target), 'Cross-institution management must be denied');
    }
});

test('Institution isolation is symmetric between A and B', function () use ($adminA, $adminB, $teacherA, $teacherB) {
    assertTrue(AccessControl::canViewUser($adminB, $teacherB));
    assertTrue(AccessControl::canManageUser($adminB, $teacherB));

    assertFalse(AccessControl::canViewUser($adminB, $teacherA));
    assertFalse(AccessControl::canManageUser($adminB, $teacherA));

    assertFalse(AccessControl::sameInstitution($adminA, $teacherB));
    assertFalse(AccessControl::sameInstitution($adminB, $teacherA));
    assertTrue(AccessControl::sameInstitution($adminA, $teacherA));
    assertTrue(AccessControl::sameInstitution($adminB, $teacherB));
});

test('Admins with no institution context cannot view or manage anyone', function () use ($adminWithoutInstitution, $teacherA, $studentA) {
    assertFalse(AccessControl::canViewUser($adminWithoutInstitution, $teacherA));
    assertFalse(AccessControl::canManageUser($adminWithoutInstitution, $teacherA));
    assertFalse(AccessControl::canViewUser($adminWithoutInstitution, $studentA));
    assertFalse(AccessControl::canManageUser($adminWithoutInstitution, $studentA));
});

test('Teachers and students cannot manage or view peers in another institution', function () use ($teacherA, $teacherB, $studentA, $studentB) {
    assertTrue(AccessControl::canViewUser($teacherA, $teacherA));
    assertTrue(AccessControl::canManageUser($teacherA, $teacherA));

    assertFalse(AccessControl::canViewUser($teacherA, $teacherB));
    assertFalse(AccessControl::canManageUser($teacherA, $teacherB));
    assertFalse(AccessControl::canViewUser($teacherA, $studentB));
    assertFalse(AccessControl::canManageUser($teacherA, $studentB));

    assertFalse(AccessControl::canViewUser($studentA, $studentB));
    assertFalse(AccessControl::canManageUser($studentA, $studentB));
    assertFalse(AccessControl::canViewUser($studentA, $teacherA));
});

test('Role assignment stays inside the manageable target set', function () use ($adminA, $teacherA, $teacherB, $studentA, $adminB) {
    // The controller first requires canManageUser(target), which blocks
    // cross-institution targets with a 404 before role assignment runs.
    assertTrue(AccessControl::canManageUser($adminA, $teacherA));
    assertTrue(AccessControl::canAssignRole($adminA, ['role_name' => 'teacher']));
    assertTrue(AccessControl::canAssignRole($adminA, ['role_name' => 'student']));
    assertTrue(AccessControl::canAssignRole($adminA, ['role_name' => 'parent']));
    assertTrue(AccessControl::canAssignRole($adminA, ['role_name' => 'admin']));

    assertFalse(AccessControl::canManageUser($adminA, $teacherB));
    assertFalse(AccessControl::canManageUser($adminA, $adminB));

    // Even though admin A can assign a teacher role in the abstract, the
    // cross-institution target is rejected above.
    assertFalse(AccessControl::canAssignRole($adminA, ['role_name' => 'super_admin']));
    assertFalse(AccessControl::canAssignRole($adminA, ['role_name' => 'super-admin']));
});

test('Non-admin roles can never assign roles', function () use ($teacherA, $studentA) {
    foreach ([$teacherA, $studentA] as $actor) {
        foreach (['admin', 'teacher', 'student', 'parent'] as $roleName) {
            assertFalse(
                AccessControl::canAssignRole($actor, ['role_name' => $roleName]),
                'Non-admin actors must not assign roles'
            );
        }
    }
});

test('Super admins view globally but manage only admin-level accounts', function () use ($superAdmin, $superAdminOnly, $adminA, $adminB, $teacherA, $studentA) {
    assertTrue(AccessControl::canViewUser($superAdmin, $adminA));
    assertTrue(AccessControl::canViewUser($superAdmin, $adminB));
    assertTrue(AccessControl::canViewUser($superAdmin, $teacherA));
    assertTrue(AccessControl::canViewUser($superAdmin, $studentA));

    assertTrue(AccessControl::canManageUser($superAdmin, $adminA));
    assertTrue(AccessControl::canManageUser($superAdmin, $adminB));
    assertTrue(AccessControl::canManageUser($superAdmin, $superAdminOnly));
    assertFalse(AccessControl::canManageUser($superAdmin, $teacherA));
    assertFalse(AccessControl::canManageUser($superAdmin, $studentA));
});

test('Institution admins cannot see or manage super-admin accounts', function () use ($adminA, $superAdminOnly, $superAdmin) {
    assertFalse(AccessControl::canViewUser($adminA, $superAdminOnly));
    assertFalse(AccessControl::canManageUser($adminA, $superAdminOnly));
    assertFalse(AccessControl::canViewUser($adminA, $superAdmin));
    assertFalse(AccessControl::canManageUser($adminA, $superAdmin));
});

test('Super-admin role names are detected across spelling variants', function () {
    foreach (['super_admin', 'super-admin', 'Super Admin', 'SUPERADMIN'] as $variant) {
        assertTrue(AccessControl::isSuperAdminRoleName($variant), "Expected {$variant} to be a super-admin role name");
        assertTrue(AccessControl::targetIsSuperAdmin(['role' => $variant]));
        assertFalse(AccessControl::isAdminLikeRoleName($variant));
    }

    foreach (['admin', 'institution_admin', 'institution-admin', 'school administrator'] as $variant) {
        assertTrue(AccessControl::isAdminLikeRoleName($variant), "Expected {$variant} to be admin-like");
    }

    assertFalse(AccessControl::isAdminLikeRoleName('teacher'));
    assertFalse(AccessControl::isAdminLikeRoleName('student'));
});
