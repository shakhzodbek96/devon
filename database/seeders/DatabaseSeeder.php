<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            PermissionsSeeder::class,
            AdminUserSeeder::class,
            PositionsSeeder::class,
            UnitsSeeder::class,
            EmployeesSeeder::class,
            CertificatesSeeder::class,
            DocumentTemplatesSeeder::class,
            DocumentsSeeder::class,
            LettersSeeder::class,
            TasksSeeder::class,
            ConferenceRoomsSeeder::class,
            NotificationsSeeder::class,
        ]);
    }
}
