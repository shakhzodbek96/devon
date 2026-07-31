<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the permission set for the Auth & Roles slice (PLAN_AUTH_ROLES.md
 * §1.3) plus the Phase A+B additions (PLAN_PHASE_AB.md §1.1, §2.3), the
 * Phase C Employees additions (PLAN_PHASE_C.md §2), and the Phase E
 * Certificates additions (PLAN_PHASE_E.md §5):
 * - ROLE_SUPER_ADMIN → all permissions.
 * - ROLE_HR_ADMIN → user management + roles.view + audit.view + units.* +
 *   employees.* + certificates.*.
 * - ROLE_AUDITOR → audit.view + units.view + employees.view + certificates.view.
 * - ROLE_EMPLOYEE → units.view + employees.view + certificates.view +
 *   certificates.upload (self-service ERI upload — PLAN_PHASE_E.md §5).
 * - ROLE_HR_OPERATOR / ROLE_UNIT_HEAD / ROLE_DEVONXONA → units.view +
 *   employees.view + certificates.view (read-only on certificates; they
 *   don't upload/manage).
 *
 * `collegial.manage` (PLAN_kollegial-organ.md §4) — SUPER_ADMIN + HR_ADMIN
 * only; gates `CollegialBody` CRUD/member management. Protocol actions
 * (create/submit/reviewer-decide/member-sign/chairman-decide) are NOT
 * spatie-permission-gated — authorization is per-resource, enforced in
 * `ProtocolService` (secretary/reviewer/participant/chairman role checks).
 */
class PermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users.view',
        'users.create',
        'users.update',
        'users.deactivate',
        'roles.view',
        'audit.view',
        'units.view',
        'units.create',
        'units.update',
        'positions.manage',
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.terminate',
        'certificates.view',
        'certificates.upload',
        'certificates.manage',
        'conference-rooms.manage',
        'attendance.manage-config',
        'attendance.manage',
        'attendance.view',
        'attendance.view-registry',
        'collegial.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findByName('ROLE_SUPER_ADMIN', 'web');
        $superAdmin->syncPermissions(self::PERMISSIONS);

        $hrAdmin = Role::findByName('ROLE_HR_ADMIN', 'web');
        $hrAdmin->syncPermissions([
            'users.view', 'users.create', 'users.update', 'roles.view',
            'audit.view', 'units.view', 'units.create', 'units.update', 'positions.manage',
            'employees.view', 'employees.create', 'employees.update', 'employees.terminate',
            'certificates.view', 'certificates.upload', 'certificates.manage',
            'conference-rooms.manage',
            'attendance.manage-config', 'attendance.manage', 'attendance.view', 'attendance.view-registry',
            'collegial.manage',
        ]);

        $auditor = Role::findByName('ROLE_AUDITOR', 'web');
        $auditor->syncPermissions(['audit.view', 'units.view', 'employees.view', 'certificates.view', 'attendance.view']);

        $employee = Role::findByName('ROLE_EMPLOYEE', 'web');
        $employee->syncPermissions(['units.view', 'employees.view', 'certificates.view', 'certificates.upload']);

        foreach (['ROLE_HR_OPERATOR', 'ROLE_UNIT_HEAD', 'ROLE_DEVONXONA'] as $roleName) {
            Role::findByName($roleName, 'web')->syncPermissions([
                'units.view', 'employees.view', 'certificates.view',
            ]);
        }
    }
}
