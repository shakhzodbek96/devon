<?php

namespace Database\Seeders;

use App\Models\Certificate;
use App\Models\CollegialBody;
use App\Models\CollegialBodyMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Collegial-body fixture (PLAN_kollegial-organ.md §4) — one sample body
 * ("Boshqaruv Qo'mitasi") built from the seeded personas
 * (`EmployeesSeeder::PERSONAS`): RAHBAR chairs, HR_ADMIN acts as
 * secretary, and the roster picks a handful of employees who all hold an
 * ACTIVE `digital_certificates` row (ERI signing is exercised in tests —
 * `CertificatesSeeder` already guarantees the personas + a spread of other
 * seeded employees have ACTIVE certs, so we query for that directly rather
 * than hard-coding indices).
 *
 * Idempotent — guarded by `CollegialBody::exists()`, same convention as
 * `ConferenceRoomsSeeder`/`TasksSeeder`.
 */
class CollegialBodiesSeeder extends Seeder
{
    public function run(): void
    {
        if (CollegialBody::exists()) {
            return;
        }

        $chairmanUuid = EmployeesSeeder::PERSONAS['RAHBAR'];
        $secretaryUuid = EmployeesSeeder::PERSONAS['HR_ADMIN'];

        $memberUuids = Certificate::query()
            ->where('status', 'ACTIVE')
            ->whereNotIn('employee_uuid', [$chairmanUuid, $secretaryUuid])
            ->distinct()
            ->limit(4)
            ->pluck('employee_uuid');

        if ($memberUuids->count() < 3) {
            throw new RuntimeException('CollegialBodiesSeeder requires CertificatesSeeder to run first (needs ACTIVE-cert employees).');
        }

        $body = CollegialBody::create([
            'uuid' => (string) Str::uuid(),
            'name' => "Boshqaruv Qo'mitasi",
            'chairman_employee_uuid' => $chairmanUuid,
            'secretary_employee_uuid' => $secretaryUuid,
            'quorum_min' => 3,
            'status' => 'ACTIVE',
            'created_by' => $secretaryUuid,
            'updated_by' => $secretaryUuid,
        ]);

        foreach ($memberUuids as $employeeUuid) {
            CollegialBodyMember::create([
                'collegial_body_uuid' => $body->uuid,
                'employee_uuid' => $employeeUuid,
            ]);
        }
    }
}
