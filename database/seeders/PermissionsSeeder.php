<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the permission set for the Auth & Roles slice (PLAN_AUTH_ROLES.md
 * §1.3) plus the Phase A+B additions (PLAN_PHASE_AB.md §1.1, §2.3):
 * - ROLE_SUPER_ADMIN → all permissions.
 * - ROLE_HR_ADMIN → user management + roles.view + audit.view + units.*.
 * - ROLE_AUDITOR → audit.view + units.view.
 * - Every other role → units.view only (read-only org tree).
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
        ]);

        $auditor = Role::findByName('ROLE_AUDITOR', 'web');
        $auditor->syncPermissions(['audit.view', 'units.view']);

        foreach (['ROLE_HR_OPERATOR', 'ROLE_UNIT_HEAD', 'ROLE_EMPLOYEE', 'ROLE_DEVONXONA'] as $roleName) {
            Role::findByName($roleName, 'web')->syncPermissions(['units.view']);
        }
    }
}
