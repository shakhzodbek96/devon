<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

/**
 * Position catalogue ported verbatim from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `positions`).
 * Foundation for the Employees module (milestone C).
 */
class PositionsSeeder extends Seeder
{
    private const POSITIONS = [
        ['id' => 'POS-DIR', 'nameUz' => 'Direktor', 'allowedUnitTypes' => ['DEPARTMENT']],
        ['id' => 'POS-DEP-HEAD', 'nameUz' => 'Departament rahbari', 'allowedUnitTypes' => ['DEPARTMENT']],
        ['id' => 'POS-DIRECT-HEAD', 'nameUz' => "Boshqarma boshlig'i", 'allowedUnitTypes' => ['DIRECTORATE']],
        ['id' => 'POS-DIV-HEAD', 'nameUz' => "Bo'lim boshlig'i", 'allowedUnitTypes' => ['DIVISION']],
        ['id' => 'POS-SUB-HEAD', 'nameUz' => "Sho'ba boshlig'i", 'allowedUnitTypes' => ['DEPARTMENT_SUB']],
        ['id' => 'POS-LEAD-DEV', 'nameUz' => 'Bosh dasturchi', 'allowedUnitTypes' => ['DEPARTMENT_SUB', 'SECTION']],
        ['id' => 'POS-DEV', 'nameUz' => 'Dasturchi', 'allowedUnitTypes' => ['DEPARTMENT_SUB', 'SECTION', 'DIVISION']],
        ['id' => 'POS-ANALYST', 'nameUz' => 'Tahlilchi', 'allowedUnitTypes' => ['DEPARTMENT_SUB', 'SECTION', 'DIRECTORATE']],
        ['id' => 'POS-SPECIALIST', 'nameUz' => 'Mutaxassis', 'allowedUnitTypes' => ['DEPARTMENT_SUB', 'SECTION', 'DIVISION', 'DIRECTORATE']],
        ['id' => 'POS-ACCOUNTANT', 'nameUz' => 'Buxgalter', 'allowedUnitTypes' => ['DEPARTMENT_SUB', 'SECTION', 'DIVISION', 'DIRECTORATE']],
        ['id' => 'POS-HR-MANAGER', 'nameUz' => "Kadrlar bo'limi boshlig'i", 'allowedUnitTypes' => ['DIRECTORATE', 'DIVISION']],
        ['id' => 'POS-HR-SPEC', 'nameUz' => "Kadrlar bo'yicha mutaxassis", 'allowedUnitTypes' => ['DEPARTMENT_SUB', 'SECTION', 'DIRECTORATE', 'DIVISION']],
        ['id' => 'POS-LAWYER', 'nameUz' => 'Yurist', 'allowedUnitTypes' => ['DIVISION', 'SECTION']],
        ['id' => 'POS-SECURITY-SPEC', 'nameUz' => 'Xavfsizlik mutaxassisi', 'allowedUnitTypes' => ['DIVISION', 'SECTION', 'DEPARTMENT_SUB']],
        ['id' => 'POS-CHANCELLERY', 'nameUz' => 'Devonxona xodimi', 'allowedUnitTypes' => ['OTHER']],
    ];

    public function run(): void
    {
        foreach (self::POSITIONS as $position) {
            Position::updateOrCreate(
                ['id' => $position['id']],
                [
                    'name_uz' => $position['nameUz'],
                    'allowed_unit_types' => $position['allowedUnitTypes'],
                ],
            );
        }
    }
}
