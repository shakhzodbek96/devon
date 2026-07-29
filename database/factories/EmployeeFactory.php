<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastName = fake()->lastName();
        $firstName = fake()->firstName();

        return [
            'uuid' => (string) Str::uuid(),
            'user_uuid' => (string) Str::uuid(),
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => null,
            'full_name_generated' => "{$lastName} {$firstName}",
            'gender' => fake()->randomElement(['M', 'F']),
            'pinfl' => fake()->unique()->numerify('5#############'),
            'mobile_phone' => '+998 90 '.fake()->numerify('###').' '.fake()->numerify('##').' '.fake()->numerify('##'),
            'corporate_email' => fake()->unique()->userName().'@devon.uz',
            'primary_unit_uuid' => fn () => Unit::factory()->create()->uuid,
            'position_id' => 'POS-SPECIALIST',
            'employment_type' => 'FULL_TIME',
            'hire_date' => now()->subYear()->format('Y-m-d'),
            'status' => 'ACTIVE',
        ];
    }
}
