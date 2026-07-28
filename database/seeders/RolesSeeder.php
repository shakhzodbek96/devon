<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds the 7 Devon roles (spatie/laravel-permission, guard `web`).
 * Role codes must match frontend/dashboard/src/types/domain.ts `Role`
 * union verbatim. Display labels come from config/devon_roles.php
 * (mirrors frontend `common.roles.*` i18n keys).
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (array_keys(config('devon_roles')) as $code) {
            Role::findOrCreate($code, 'web');
        }
    }
}
