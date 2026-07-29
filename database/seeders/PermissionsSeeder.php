<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the permission set for the Auth & Roles slice (PLAN_AUTH_ROLES.md
 * §1.3) plus the Phase A+B additions (PLAN_PHASE_AB.md §1.1, §2.3) and the
 * Phase C Employees additions (PLAN_PHASE_C.md §2):
 * - ROLE_SUPER_ADMIN → all permissions.
 * - ROLE_HR_ADMIN → user management + roles.view + audit.view + units.* +
 *   employees.*.
 * - ROLE_AUDITOR → audit.view + units.view + employees.view.
 * - Every other role → units.view + employees.view (read-only).
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
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.terminate',
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
            'audit.view', 'units.view', 'units.create', 'units.update',
            'employees.view', 'employees.create', 'employees.update', 'employees.terminate',
        ]);

        $auditor = Role::findByName('ROLE_AUDITOR', 'web');
        $auditor->syncPermissions(['audit.view', 'units.view', 'employees.view']);

        foreach (['ROLE_HR_OPERATOR', 'ROLE_UNIT_HEAD', 'ROLE_EMPLOYEE', 'ROLE_DEVONXONA'] as $roleName) {
            Role::findByName($roleName, 'web')->syncPermissions(['units.view', 'employees.view']);
        }
    }
}
