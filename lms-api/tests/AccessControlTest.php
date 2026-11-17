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

$superAdminWithAdmin = [
    'user_id' => 3,
    'role' => 'super_admin',
    'roles' => ['admin', 'super_admin'],
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

test('AccessControl::isSuperAdmin detects the flag, singular role, and roles array', function () use ($superAdmin, $superAdminOnly, $adminA) {
    assertTrue(AccessControl::isSuperAdmin($superAdmin));
    assertTrue(AccessControl::isSuperAdmin($superAdminOnly));
    assertTrue(AccessControl::isSuperAdmin(['role' => 'super-admin']));
    assertTrue(AccessControl::isSuperAdmin(['roles' => ['admin', 'super admin']]));
    assertFalse(AccessControl::isSuperAdmin($adminA));
    assertFalse(AccessControl::isSuperAdmin(['role' => 'teacher']));
});

test('AccessControl::isAdmin does not promote super-admin-only accounts', function () use ($superAdmin, $superAdminOnly, $adminA, $teacherA) {
    assertFalse(AccessControl::isAdmin($superAdmin));
    assertFalse(AccessControl::isAdmin($superAdminOnly));
    assertTrue(AccessControl::isAdmin($adminA));
    assertFalse(AccessControl::isAdmin($teacherA));
});

test('AccessControl::sameInstitution requires two positive matching institutions', function () use ($adminA, $adminB, $teacherA) {
    assertTrue(AccessControl::sameInstitution($adminA, $teacherA));
    assertFalse(AccessControl::sameInstitution($adminA, $adminB));
    assertFalse(AccessControl::sameInstitution(['institution_id' => 0], ['institution_id' => 0]));
    assertFalse(AccessControl::sameInstitution(['institution_id' => 1], ['institution_id' => 0]));
    assertFalse(AccessControl::sameInstitution(['institution_id' => 0], ['institution_id' => 1]));
});

test('AccessControl::canViewUser blocks cross-institution and super-admin targets', function () use ($superAdmin, $superAdminOnly, $adminA, $adminB, $teacherA, $teacherB) {
    assertTrue(AccessControl::canViewUser($adminA, $teacherA));
    assertTrue(AccessControl::canViewUser($adminA, $adminA));
    assertFalse(AccessControl::canViewUser($adminA, $teacherB));
    assertFalse(AccessControl::canViewUser($adminA, $adminB));
    assertFalse(AccessControl::canViewUser($adminA, $superAdminOnly));

    assertTrue(AccessControl::canViewUser($superAdmin, $teacherB));
    assertTrue(AccessControl::canViewUser($superAdminOnly, $adminA));

    assertTrue(AccessControl::canViewUser($teacherA, $teacherA));
    assertFalse(AccessControl::canViewUser($teacherA, $teacherB));
});

test('AccessControl::canManageUser keeps super-admin and cross-institution targets off limits', function () use ($superAdmin, $superAdminOnly, $adminA, $adminB, $teacherA) {
    assertTrue(AccessControl::canManageUser($adminA, $teacherA));
    assertTrue(AccessControl::canManageUser($adminA, $adminA));
    assertFalse(AccessControl::canManageUser($adminA, $adminB));
    assertFalse(AccessControl::canManageUser($adminA, $superAdminOnly));

    // Super admins may manage admin-level accounts, but not ordinary users
    // through the user-management endpoints.
    assertTrue(AccessControl::canManageUser($superAdmin, $adminA));
    assertTrue(AccessControl::canManageUser($superAdminOnly, $adminA));
    assertFalse(AccessControl::canManageUser($superAdmin, $teacherA));
    assertFalse(AccessControl::canManageUser($superAdminOnly, $teacherA));
});

test('AccessControl::canAssignRole never grants super_admin and respects admin scope', function () use ($superAdmin, $superAdminOnly, $superAdminWithAdmin, $adminA, $teacherA) {
    assertTrue(AccessControl::canAssignRole($adminA, ['role_name' => 'teacher']));
    assertTrue(AccessControl::canAssignRole($adminA, ['role_name' => 'admin']));
    assertFalse(AccessControl::canAssignRole($adminA, ['role_name' => 'super_admin']));

    assertFalse(AccessControl::canAssignRole($teacherA, ['role_name' => 'teacher']));

    assertTrue(AccessControl::canAssignRole($superAdminOnly, ['role_name' => 'admin']));
    assertFalse(AccessControl::canAssignRole($superAdminOnly, ['role_name' => 'teacher']));

    // A super-admin-only account may only hand out admin-level roles. Once the
    // actor also holds the admin role, the normal admin rules apply.
    assertFalse(AccessControl::canAssignRole($superAdmin, ['role_name' => 'teacher']));
    assertTrue(AccessControl::canAssignRole($superAdminWithAdmin, ['role_name' => 'teacher']));
    assertFalse(AccessControl::canAssignRole($superAdminWithAdmin, ['role_name' => 'super_admin']));
});

test('AccessControl::institutionId casts missing and invalid values to zero', function () {
    assertSame(0, AccessControl::institutionId([]));
    assertSame(0, AccessControl::institutionId(['institution_id' => null]));
    assertSame(0, AccessControl::institutionId(['institution_id' => 'nope']));
    assertSame(7, AccessControl::institutionId(['institution_id' => '7']));
});
