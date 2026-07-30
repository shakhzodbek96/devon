<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkShift>
 */
class WorkShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'unit_uuid' => null,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'late_grace_min' => 10,
            'early_exit_grace_min' => 15,
        ];
    }
}
