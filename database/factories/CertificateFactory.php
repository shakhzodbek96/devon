<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Certificate>
 */
class CertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'employee_uuid' => fn () => Employee::factory()->create()->uuid,
            'serial_number' => strtoupper(bin2hex(random_bytes(8))),
            'thumbprint' => strtoupper(bin2hex(random_bytes(20))),
            'subject_pinfl' => fake()->unique()->numerify('5#############'),
            'subject_common_name' => fake()->name(),
            'subject_organization' => 'Devon Demo',
            'issuer_name' => 'YANGI TEXNOLOGIYALAR ILMIY-AXBOROT MARKAZI AJ',
            'valid_from' => now()->subDays(180),
            'valid_to' => now()->addDays(185),
            'key_usage' => ['digitalSignature', 'nonRepudiation'],
            'certificate_type' => 'SIGNING',
            'status' => 'ACTIVE',
            'uploaded_by_uuid' => (string) Str::uuid(),
            'approved_by_uuid' => (string) Str::uuid(),
            'approved_at' => now()->subDays(150),
        ];
    }
}
