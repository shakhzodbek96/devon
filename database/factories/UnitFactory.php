<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'name_uz' => fake()->unique()->company(),
            'code' => strtoupper(fake()->unique()->bothify('UNIT-####')),
            'type' => 'OTHER',
            'parent_uuid' => null,
            'level' => 0,
            'path' => "/{$uuid}/",
            'status' => 'ACTIVE',
        ];
    }
}
