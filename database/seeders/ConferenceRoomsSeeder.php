<?php

namespace Database\Seeders;

use App\Models\ConferenceRoom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Conference-room fixtures (PLAN_conference-room.md §3) — 3 sample rooms.
 * `created_by`/`updated_by` are stamped as the HR admin persona (mirrors
 * the mock's convention of attributing seed rows to a real seeded actor).
 * Idempotent — guarded by `ConferenceRoom::exists()`, same convention as
 * `TasksSeeder`/`LettersSeeder`.
 */
class ConferenceRoomsSeeder extends Seeder
{
    public function run(): void
    {
        if (ConferenceRoom::exists()) {
            return;
        }

        $actor = EmployeesSeeder::PERSONAS['HR_ADMIN'];

        $rooms = [
            ['name' => 'Katta zal', 'capacity' => 20, 'location' => '3-qavat'],
            ['name' => 'Kichik zal', 'capacity' => 6, 'location' => '2-qavat'],
            ['name' => 'Boshqaruv zali', 'capacity' => 10, 'location' => '5-qavat'],
        ];

        foreach ($rooms as $room) {
            ConferenceRoom::create([
                'uuid' => (string) Str::uuid(),
                'name' => $room['name'],
                'capacity' => $room['capacity'],
                'location' => $room['location'],
                'status' => 'ACTIVE',
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
        }
    }
}
