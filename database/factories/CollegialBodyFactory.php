<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CollegialBody>
 */
class CollegialBodyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actor = (string) Str::uuid();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->unique()->words(2, true).' qo\'mitasi',
            'chairman_employee_uuid' => (string) Str::uuid(),
            'secretary_employee_uuid' => (string) Str::uuid(),
            'quorum_min' => fake()->numberBetween(1, 5),
            'status' => 'ACTIVE',
            'created_by' => $actor,
            'updated_by' => $actor,
        ];
    }
}
