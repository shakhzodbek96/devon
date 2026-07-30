<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConferenceRoom>
 */
class ConferenceRoomFactory extends Factory
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
            'name' => fake()->unique()->words(2, true).' zali',
            'capacity' => fake()->numberBetween(4, 30),
            'location' => fake()->optional()->buildingNumber(),
            'status' => 'ACTIVE',
            'created_by' => $actor,
            'updated_by' => $actor,
        ];
    }
}
