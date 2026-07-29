<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Employee + user + primary-assignment fixtures ported from the frontend
 * mock (frontend/dashboard/src/lib/mock-backend/seed.ts `fios` /
 * `fioToUnit`). PLAN_PHASE_C.md §0.1 (CRITICAL): the 5 POV personas MUST
 * seed with the EXACT same uuids + corporateEmails as the mock — the
 * still-mock M2 modules (documents/letters/tasks) reference employees by
 * those uuids. PLAN_PHASE_C.md §0.3: `admin@devon.uz` already exists
 * (`AdminUserSeeder`) — this seeder links its employee record via
 * `employee_uuid` instead of creating a duplicate user.
 *
 * Idempotent — re-running leaves existing rows (matched by corporateEmail)
 * untouched, mirroring `UnitsSeeder`'s idempotency convention.
 */
class EmployeesSeeder extends Seeder
{
    /**
     * Fixed literal employee UUIDs — mirrors the mock's `PERSONAS`
     * (frontend/dashboard/src/lib/mock-backend/seed.ts) verbatim.
     */
    public const PERSONAS = [
        'HR_ADMIN' => '00000000-0000-4000-8000-00000000a001',
        'RAHBAR' => '00000000-0000-4000-8000-00000000a002',
        'BOLIM_BOSHLIGI' => '00000000-0000-4000-8000-00000000a003',
        'DEVONXONA' => '00000000-0000-4000-8000-00000000a004',
        'XODIM' => '00000000-0000-4000-8000-00000000a005',
    ];

    /**
     * Positions whose holder heads their unit — drives `head_employee_uuid`
     * wiring (mirrors the mock's `HEAD_POSITION_IDS`,
     * frontend/dashboard/src/lib/mock-backend/seed.ts). The POV switcher's
     * `headedUnitUuids` (`src/lib/acting.ts`) and the M2 approval queues
     * key off this — without it every `Unit.headEmployeeUuid` stays null
     * against the real backend, breaking manager-gated UI (PLAN_PHASE_F1.md
     * §0: "Units ham fasaddan").
     */
    private const HEAD_POSITION_IDS = [
        'POS-DIR',
        'POS-DEP-HEAD',
        'POS-DIRECT-HEAD',
        'POS-DIV-HEAD',
        'POS-SUB-HEAD',
        'POS-HR-MANAGER',
    ];

    /** FIO catalogue. Index 0 is the HR_ADMIN (admin@devon.uz). */
    private const FIOS = [
        ['lastName' => 'Pulatov', 'firstName' => 'Asilbek', 'middleName' => 'Karimovich', 'gender' => 'M'],
        ['lastName' => 'Karimov', 'firstName' => 'Bekzod', 'middleName' => 'Anvarovich', 'gender' => 'M'],
        ['lastName' => 'Yusupov', 'firstName' => 'Jasur', 'middleName' => 'Rustamovich', 'gender' => 'M'],
        ['lastName' => 'Tursunov', 'firstName' => 'Doniyor', 'middleName' => 'Olimovich', 'gender' => 'M'],
        ['lastName' => 'Saidov', 'firstName' => 'Bobur', 'middleName' => 'Tohirovich', 'gender' => 'M'],
        ['lastName' => 'Rashidov', 'firstName' => 'Ilxomjon', 'middleName' => 'Shavkatovich', 'gender' => 'M'],
        ['lastName' => 'Akhmedov', 'firstName' => 'Akmal', 'middleName' => 'Zafarbekovich', 'gender' => 'M'],
        ['lastName' => 'Komilov', 'firstName' => 'Sherzod', 'middleName' => 'Iskandarovich', 'gender' => 'M'],
        ['lastName' => 'Mirzayev', 'firstName' => 'Aziz', 'middleName' => 'Davronovich', 'gender' => 'M'],
        ['lastName' => 'Toshmuhammedov', 'firstName' => "Ulug'bek", 'middleName' => 'Ravshanovich', 'gender' => 'M'],
        ['lastName' => 'Xolmatov', 'firstName' => 'Shohrux', 'middleName' => 'Maxsumovich', 'gender' => 'M'],
        ['lastName' => 'Nazarov', 'firstName' => 'Sanjar', 'middleName' => 'Erkinovich', 'gender' => 'M'],
        ['lastName' => 'Egamberdiyev', 'firstName' => 'Otabek', 'middleName' => 'Nurmuhammadovich', 'gender' => 'M'],
        ['lastName' => 'Abdullaev', 'firstName' => 'Husan', 'middleName' => 'Akbarovich', 'gender' => 'M'],
        ['lastName' => 'Ergashev', 'firstName' => 'Davron', 'middleName' => 'Lazizovich', 'gender' => 'M'],
        ['lastName' => 'Sobirova', 'firstName' => 'Dilnoza', 'middleName' => 'Murodovna', 'gender' => 'F'],
        ['lastName' => "Norbo'taeva", 'firstName' => 'Mohira', 'middleName' => 'Sherzodovna', 'gender' => 'F'],
        ['lastName' => "Yo'ldosheva", 'firstName' => 'Nilufar', 'middleName' => 'Bahodirovna', 'gender' => 'F'],
        ['lastName' => 'Saidova', 'firstName' => 'Gulnoza', 'middleName' => 'Ruxshonovna', 'gender' => 'F'],
        ['lastName' => 'Rakhimova', 'firstName' => 'Aziza', 'middleName' => 'Doniyorovna', 'gender' => 'F'],
        ['lastName' => 'Komilova', 'firstName' => 'Mahliyo', 'middleName' => 'Ozodbekovna', 'gender' => 'F'],
        ['lastName' => 'Tursunova', 'firstName' => 'Madina', 'middleName' => 'Farxodovna', 'gender' => 'F'],
        ['lastName' => 'Saidova', 'firstName' => 'Shaxnoza', 'middleName' => 'Olimjonovna', 'gender' => 'F'],
        ['lastName' => 'Xolmatova', 'firstName' => 'Charos', 'middleName' => 'Akmalovna', 'gender' => 'F'],
        ['lastName' => 'Karimova', 'firstName' => 'Diyora', 'middleName' => 'Rashidovna', 'gender' => 'F'],
        ['lastName' => 'Ergasheva', 'firstName' => 'Zarina', 'middleName' => 'Yusupovna', 'gender' => 'F'],
        ['lastName' => 'Mirzaeva', 'firstName' => 'Sevara', 'middleName' => 'Bahromovna', 'gender' => 'F'],
        ['lastName' => 'Toshmuhammedova', 'firstName' => 'Marjona', 'middleName' => 'Anvarovna', 'gender' => 'F'],
        ['lastName' => 'Egamberdiyeva', 'firstName' => 'Sevinch', 'middleName' => 'Nodirovna', 'gender' => 'F'],
        ['lastName' => 'Akhmedova', 'firstName' => 'Munisa', 'middleName' => 'Akrambekovna', 'gender' => 'F'],
        // Index 30 — the Devonxona persona.
        ['lastName' => 'Yusupova', 'firstName' => 'Nilufar', 'middleName' => 'Baxtiyorovna', 'gender' => 'F'],
    ];

    /**
     * fioIdx → { unitCode, positionId, employmentType?, status?, hireDaysAgo,
     * employeeUuid?, roles? }. Unit codes match `UnitsSeeder::SPECS`;
     * position ids match `PositionsSeeder::POSITIONS`.
     */
    private const ASSIGNS = [
        ['fioIdx' => 0, 'unitCode' => 'DEP-HR-REC', 'positionId' => 'POS-HR-MANAGER', 'hireDaysAgo' => 1100, 'employeeUuid' => self::PERSONAS['HR_ADMIN']],

        ['fioIdx' => 1, 'unitCode' => 'DEP-IT', 'positionId' => 'POS-DEP-HEAD', 'hireDaysAgo' => 1500, 'employeeUuid' => self::PERSONAS['RAHBAR']],
        ['fioIdx' => 2, 'unitCode' => 'DEP-IT-INF', 'positionId' => 'POS-DIRECT-HEAD', 'hireDaysAgo' => 1200],
        ['fioIdx' => 3, 'unitCode' => 'DEP-IT-INF-NET', 'positionId' => 'POS-SPECIALIST', 'hireDaysAgo' => 800],
        ['fioIdx' => 4, 'unitCode' => 'DEP-IT-INF-SRV', 'positionId' => 'POS-SPECIALIST', 'hireDaysAgo' => 700],
        ['fioIdx' => 5, 'unitCode' => 'DEP-IT-DEV', 'positionId' => 'POS-DIRECT-HEAD', 'hireDaysAgo' => 1300],
        ['fioIdx' => 6, 'unitCode' => 'DEP-IT-DEV-BE', 'positionId' => 'POS-DIV-HEAD', 'hireDaysAgo' => 900, 'employeeUuid' => self::PERSONAS['BOLIM_BOSHLIGI']],
        ['fioIdx' => 7, 'unitCode' => 'DEP-IT-DEV-BE-API', 'positionId' => 'POS-LEAD-DEV', 'hireDaysAgo' => 600],
        ['fioIdx' => 15, 'unitCode' => 'DEP-IT-DEV-BE-API', 'positionId' => 'POS-DEV', 'employmentType' => 'FULL_TIME', 'hireDaysAgo' => 380, 'employeeUuid' => self::PERSONAS['XODIM']],
        ['fioIdx' => 8, 'unitCode' => 'DEP-IT-DEV-FE', 'positionId' => 'POS-DIV-HEAD', 'hireDaysAgo' => 950],
        ['fioIdx' => 9, 'unitCode' => 'DEP-IT-DEV-FE-UI', 'positionId' => 'POS-LEAD-DEV', 'hireDaysAgo' => 500],
        ['fioIdx' => 16, 'unitCode' => 'DEP-IT-DEV-FE-UI', 'positionId' => 'POS-DEV', 'employmentType' => 'INTERN', 'hireDaysAgo' => 90],

        ['fioIdx' => 10, 'unitCode' => 'DEP-HR', 'positionId' => 'POS-DEP-HEAD', 'hireDaysAgo' => 1400],
        ['fioIdx' => 17, 'unitCode' => 'DEP-HR-REC-ONB', 'positionId' => 'POS-HR-SPEC', 'hireDaysAgo' => 420],
        ['fioIdx' => 18, 'unitCode' => 'DEP-HR-COMP', 'positionId' => 'POS-HR-SPEC', 'hireDaysAgo' => 650],
        ['fioIdx' => 11, 'unitCode' => 'DEP-HR-COMP', 'positionId' => 'POS-HR-SPEC', 'status' => 'ON_LEAVE', 'hireDaysAgo' => 1100],

        ['fioIdx' => 12, 'unitCode' => 'DEP-FIN', 'positionId' => 'POS-DEP-HEAD', 'hireDaysAgo' => 1600],
        ['fioIdx' => 19, 'unitCode' => 'DEP-FIN-ACC', 'positionId' => 'POS-ACCOUNTANT', 'hireDaysAgo' => 480],
        ['fioIdx' => 20, 'unitCode' => 'DEP-FIN-ACC-TAX', 'positionId' => 'POS-ACCOUNTANT', 'hireDaysAgo' => 320],
        ['fioIdx' => 21, 'unitCode' => 'DEP-FIN-BUD', 'positionId' => 'POS-ANALYST', 'hireDaysAgo' => 280],

        ['fioIdx' => 13, 'unitCode' => 'DEP-LEG', 'positionId' => 'POS-DEP-HEAD', 'hireDaysAgo' => 1700],
        ['fioIdx' => 22, 'unitCode' => 'DEP-LEG-CORP', 'positionId' => 'POS-LAWYER', 'hireDaysAgo' => 540],
        ['fioIdx' => 23, 'unitCode' => 'DEP-LEG-CORP', 'positionId' => 'POS-LAWYER', 'hireDaysAgo' => 220],

        ['fioIdx' => 14, 'unitCode' => 'DEP-OPS-LOG', 'positionId' => 'POS-SPECIALIST', 'employmentType' => 'CONTRACT', 'hireDaysAgo' => 200],
        ['fioIdx' => 24, 'unitCode' => 'DEP-OPS-PROC', 'positionId' => 'POS-SPECIALIST', 'hireDaysAgo' => 360],
        ['fioIdx' => 29, 'unitCode' => 'DEP-OPS-PROC', 'positionId' => 'POS-SPECIALIST', 'employmentType' => 'PART_TIME', 'hireDaysAgo' => 110],

        ['fioIdx' => 25, 'unitCode' => 'DEP-SEC-INFO', 'positionId' => 'POS-SECURITY-SPEC', 'hireDaysAgo' => 720],
        ['fioIdx' => 26, 'unitCode' => 'DEP-SEC-INFO', 'positionId' => 'POS-SECURITY-SPEC', 'hireDaysAgo' => 250],
        ['fioIdx' => 27, 'unitCode' => 'DEP-SEC-PHYS', 'positionId' => 'POS-SPECIALIST', 'hireDaysAgo' => 850],
        ['fioIdx' => 28, 'unitCode' => 'DEP-SEC-PHYS', 'positionId' => 'POS-SPECIALIST', 'hireDaysAgo' => 180],

        ['fioIdx' => 30, 'unitCode' => 'DEV-01', 'positionId' => 'POS-CHANCELLERY', 'hireDaysAgo' => 460, 'employeeUuid' => self::PERSONAS['DEVONXONA'], 'roles' => ['ROLE_DEVONXONA']],
    ];

    public function run(): void
    {
        foreach (self::ASSIGNS as $assign) {
            $fio = self::FIOS[$assign['fioIdx']];
            $unit = Unit::where('code', $assign['unitCode'])->first();
            if (! $unit) {
                throw new \RuntimeException("Unit not found: {$assign['unitCode']}");
            }

            $isHrAdmin = $assign['fioIdx'] === 0;
            $fullNameGenerated = trim("{$fio['lastName']} {$fio['firstName']} {$fio['middleName']}");
            $corporateEmail = $isHrAdmin ? 'admin@devon.uz' : $this->emailFor($fio['firstName'], $fio['lastName']);

            if (Employee::where('corporate_email', $corporateEmail)->exists()) {
                continue;
            }

            $hireDate = now()->subDays($assign['hireDaysAgo']);
            $employeeUuid = $assign['employeeUuid'] ?? (string) Str::uuid();

            if ($isHrAdmin) {
                // PLAN_PHASE_C.md §0.3 — link the existing admin@devon.uz
                // user instead of creating a duplicate.
                $adminUser = User::where('email', 'admin@devon.uz')->first();
                if (! $adminUser) {
                    throw new \RuntimeException('admin@devon.uz not found — run AdminUserSeeder first.');
                }
                $userUuid = $adminUser->uuid;
            } else {
                $userUuid = (string) Str::uuid();
            }

            $extractExt = $assign['fioIdx'] % 5 === 3 ? 'jpg' : ($assign['fioIdx'] % 5 === 4 ? 'png' : 'pdf');
            $extractMime = match ($extractExt) {
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/pdf',
            };

            Employee::create([
                'uuid' => $employeeUuid,
                'user_uuid' => $userUuid,
                'last_name' => $fio['lastName'],
                'first_name' => $fio['firstName'],
                'middle_name' => $fio['middleName'],
                'full_name_generated' => $fullNameGenerated,
                'gender' => $fio['gender'],
                'birth_date' => now()->subYears(random_int(24, 55))->format('Y-m-d'),
                'pinfl' => $this->generatePinfl($fio['gender'], (int) $hireDate->format('Y')),
                'mobile_phone' => $this->generatePhone(),
                'corporate_email' => $corporateEmail,
                'primary_unit_uuid' => $unit->uuid,
                'position_id' => $assign['positionId'],
                'employment_type' => $assign['employmentType'] ?? 'FULL_TIME',
                'hire_date' => $hireDate->format('Y-m-d'),
                'employment_order_extract' => [
                    'fileName' => "buyruq_{$hireDate->format('Y')}-{$assign['fioIdx']}_kochirma.{$extractExt}",
                    'fileSize' => random_int(180, 1200) * 1024,
                    'mimeType' => $extractMime,
                    'uploadedAt' => $hireDate->toIso8601String(),
                ],
                'position_instruction' => [
                    'fileName' => "lavozim_yoriqnomasi_{$assign['positionId']}.pdf",
                    'fileSize' => random_int(90, 700) * 1024,
                    'mimeType' => 'application/pdf',
                    'uploadedAt' => $hireDate->toIso8601String(),
                ],
                'status' => $assign['status'] ?? 'ACTIVE',
                'created_at' => $hireDate,
                'updated_at' => $hireDate,
            ]);

            if (! $isHrAdmin) {
                User::create([
                    'uuid' => $userUuid,
                    'employee_uuid' => $employeeUuid,
                    'full_name' => $fullNameGenerated,
                    'email' => $corporateEmail,
                    'password' => 'Welcome2026!',
                    'must_change_password' => true,
                    'status' => 'ACTIVE',
                ])->assignRole($assign['roles'] ?? ['ROLE_EMPLOYEE']);
            } else {
                $adminUser = User::where('email', 'admin@devon.uz')->first();
                if (! $adminUser->employee_uuid) {
                    $adminUser->employee_uuid = $employeeUuid;
                    $adminUser->save();
                }
            }

            Assignment::create([
                'uuid' => (string) Str::uuid(),
                'employee_uuid' => $employeeUuid,
                'unit_uuid' => $unit->uuid,
                'position_id' => $assign['positionId'],
                'is_primary' => true,
                'start_date' => $hireDate->format('Y-m-d'),
                'workload_percent' => ($assign['employmentType'] ?? null) === 'PART_TIME' ? 50 : 100,
                'type' => 'PRIMARY',
                'created_at' => $hireDate,
            ]);

            // Head-position holders head their unit. First holder wins — the
            // seed never places two head positions in one unit.
            if (in_array($assign['positionId'], self::HEAD_POSITION_IDS, true) && ! $unit->head_employee_uuid) {
                $unit->head_employee_uuid = $employeeUuid;
                $unit->save();
            }
        }
    }

    private function emailFor(string $firstName, string $lastName): string
    {
        return $this->asciiSlug($firstName).'.'.$this->asciiSlug($lastName).'@devon.uz';
    }

    private function asciiSlug(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    }

    /** Plausible PINFL matching /^[1-6]\d{13}$/ — first digit encodes century + gender. */
    private function generatePinfl(string $gender, int $hireYear): string
    {
        $firstDigit = $gender === 'M'
            ? ($hireYear < 2018 ? '3' : '5')
            : ($hireYear < 2018 ? '4' : '6');
        $rest = '';
        for ($i = 0; $i < 13; $i++) {
            $rest .= random_int(0, 9);
        }

        return $firstDigit.$rest;
    }

    private function generatePhone(): string
    {
        $prefixes = ['90', '91', '93', '94', '97', '98', '99'];
        $prefix = $prefixes[array_rand($prefixes)];

        return sprintf('+998 %s %03d %02d %02d', $prefix, random_int(100, 999), random_int(10, 99), random_int(10, 99));
    }
}
