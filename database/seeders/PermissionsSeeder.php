<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the permission set for the Auth & Roles slice (PLAN_AUTH_ROLES.md
 * §1.3) plus the Phase A addition (PLAN_PHASE_AB.md §1.1):
 * - ROLE_SUPER_ADMIN → all permissions.
 * - ROLE_HR_ADMIN → user management + roles.view + audit.view.
 * - ROLE_AUDITOR → audit.view.
 * - Every other role → no user-management/audit permissions.
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
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findByName('ROLE_SUPER_ADMIN', 'web');
        $superAdmin->syncPermissions(self::PERMISSIONS);

        $hrAdmin = Role::findByName('ROLE_HR_ADMIN', 'web');
        $hrAdmin->syncPermissions(['users.view', 'users.create', 'users.update', 'roles.view', 'audit.view']);

        $auditor = Role::findByName('ROLE_AUDITOR', 'web');
        $auditor->syncPermissions(['audit.view']);
    }
}
