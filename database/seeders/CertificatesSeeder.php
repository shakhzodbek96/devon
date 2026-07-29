<?php

namespace Database\Seeders;

use App\Models\Certificate;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ERI certificate fixtures ported from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `buildCertificates`):
 * 19 ACTIVE / 4 PENDING_APPROVAL / 2 EXPIRED / 1 REVOKED = 26 total.
 * Distributed across employee indices identical to the mock's
 * `employees[i]` — `Employee::orderBy('id')` mirrors `EmployeesSeeder`'s
 * sequential `ASSIGNS` insertion order, so `$employees[i]` here lines up
 * with the mock's array index verbatim.
 *
 * Idempotent — skipped entirely if any certificate already exists.
 */
class CertificatesSeeder extends Seeder
{
    private const CA_NAME = 'YANGI TEXNOLOGIYALAR ILMIY-AXBOROT MARKAZI AJ';

    private const ORG_SHORT = 'Devon Demo';

    public function run(): void
    {
        if (Certificate::query()->exists()) {
            return;
        }

        $employees = Employee::orderBy('id')->get()->values();
        $hrAdminUuid = User::where('email', 'admin@devon.uz')->value('uuid');
        if (! $hrAdminUuid || $employees->isEmpty()) {
            throw new RuntimeException('CertificatesSeeder requires AdminUserSeeder + EmployeesSeeder to run first.');
        }

        $daysAgo = fn (int $n) => now()->subDays($n);
        $daysFromNow = fn (int $n) => now()->addDays($n);

        $makeCert = function (Employee $employee, string $status, string $type, array $overrides = []) use ($hrAdminUuid, $daysAgo, $daysFromNow) {
            $validFrom = $status === 'EXPIRED' ? $daysAgo(800) : $daysAgo(180);
            $validTo = $status === 'EXPIRED' ? $daysAgo(80) : $daysFromNow(365 - 180);
            $approved = in_array($status, ['ACTIVE', 'EXPIRED', 'REVOKED'], true);

            $keyUsage = match ($type) {
                'BOTH' => ['digitalSignature', 'keyEncipherment', 'nonRepudiation'],
                'SIGNING' => ['digitalSignature', 'nonRepudiation'],
                default => ['keyEncipherment'],
            };

            $attrs = array_merge([
                'uuid' => (string) Str::uuid(),
                'employee_uuid' => $employee->uuid,
                'serial_number' => strtoupper(bin2hex(random_bytes(8))),
                'thumbprint' => strtoupper(bin2hex(random_bytes(20))),
                'subject_pinfl' => $employee->pinfl,
                'subject_common_name' => $employee->full_name_generated,
                'subject_organization' => self::ORG_SHORT,
                'issuer_name' => self::CA_NAME,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'key_usage' => $keyUsage,
                'certificate_type' => $type,
                'status' => $status,
                'uploaded_by_uuid' => $hrAdminUuid,
                'approved_by_uuid' => $approved ? $hrAdminUuid : null,
                'approved_at' => $approved ? $daysAgo(150) : null,
                'created_at' => $status === 'PENDING_APPROVAL' ? $daysAgo(random_int(1, 5)) : $daysAgo(180),
            ], $overrides);

            return Certificate::create($attrs);
        };

        // 18 ACTIVE — distribute across the same employee indices as the mock.
        $activeOwners = [0, 1, 2, 5, 6, 7, 8, 9, 10, 11, 12, 13, 19, 22, 0, 5, 9, 25];
        foreach ($activeOwners as $i => $idx) {
            $emp = $employees[$idx];
            $type = $i % 3 === 0 ? 'BOTH' : ($i % 2 === 0 ? 'SIGNING' : 'ENCRYPTION');
            $makeCert($emp, 'ACTIVE', $type);
        }

        // 4 PENDING_APPROVAL — recent uploads waiting on HR_ADMIN review.
        foreach ([15, 16, 17, 20] as $idx) {
            $makeCert($employees[$idx], 'PENDING_APPROVAL', 'SIGNING');
        }

        // 2 EXPIRED — old certs past their validity window.
        $makeCert($employees[3], 'EXPIRED', 'SIGNING');
        $makeCert($employees[14], 'EXPIRED', 'BOTH');

        // 1 REVOKED — compromised / replaced.
        $makeCert($employees[4], 'REVOKED', 'SIGNING', [
            'revoked_at' => $daysAgo(45),
            'revocation_reason' => 'COMPROMISED',
        ]);

        // 1 more ACTIVE — the Devonxona persona signs outgoing letters (M2).
        $devonxona = $employees->firstWhere('uuid', EmployeesSeeder::PERSONAS['DEVONXONA']);
        if ($devonxona) {
            $makeCert($devonxona, 'ACTIVE', 'SIGNING');
        }
    }
}
