<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OfficeNetwork>
 */
class OfficeNetworkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->unique()->words(2, true).' tarmog\'i',
            'cidr' => '0.0.0.0/0',
            'unit_uuid' => null,
            'status' => 'ACTIVE',
        ];
    }
}
