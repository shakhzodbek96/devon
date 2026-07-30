<?php

namespace Database\Seeders;

use App\Models\WorkShift;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Organization-wide default work shift (PLAN_tabel-davomat.md §3, §9) —
 * one global (`unit_uuid=null`) 09:00-18:00 shift, 10-minute late grace,
 * 15-minute early-exit grace. Idempotent — guarded by
 * `WorkShift::whereNull('unit_uuid')->exists()`.
 */
class WorkShiftsSeeder extends Seeder
{
    public function run(): void
    {
        if (WorkShift::whereNull('unit_uuid')->exists()) {
            return;
        }

        WorkShift::create([
            'uuid' => (string) Str::uuid(),
            'unit_uuid' => null,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'late_grace_min' => 10,
            'early_exit_grace_min' => 15,
        ]);
    }
}
