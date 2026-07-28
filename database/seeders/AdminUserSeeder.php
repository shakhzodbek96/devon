<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Bootstrap: the single seeded Admin. This Admin then creates every other
 * user through the /users API (PLAN_AUTH_ROLES.md §1.6). Credentials match
 * the frontend LoginPage demo hint exactly, so login works immediately.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@devon.uz'],
            [
                'full_name' => 'Platforma administratori',
                'password' => 'Demo2026!',
                'must_change_password' => false,
                'password_changed_at' => now(),
                'status' => 'ACTIVE',
            ],
        );

        if (! $admin->hasRole('ROLE_SUPER_ADMIN')) {
            $admin->assignRole('ROLE_SUPER_ADMIN');
        }
    }
}
