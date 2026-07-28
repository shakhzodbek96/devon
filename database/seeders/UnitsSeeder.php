<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Org tree ported verbatim from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `unitSpecs`): a
 * bank-style hierarchy (Departament → Boshqarma → Bo'lim → Sho'ba) plus the
 * root-level Devonxona service unit. Idempotent — re-running leaves
 * existing rows (matched by `code`) untouched so uuids stay stable.
 */
class UnitsSeeder extends Seeder
{
    private const SPECS = [
        // Level 0 — departments
        ['code' => 'DEP-IT', 'nameUz' => 'Axborot Texnologiyalari Departamenti', 'shortName' => 'IT Departamenti', 'type' => 'DEPARTMENT', 'parentCode' => null],
        ['code' => 'DEP-HR', 'nameUz' => 'Kadrlar Departamenti', 'shortName' => 'Kadrlar', 'type' => 'DEPARTMENT', 'parentCode' => null],
        ['code' => 'DEP-FIN', 'nameUz' => 'Moliya Departamenti', 'shortName' => 'Moliya', 'type' => 'DEPARTMENT', 'parentCode' => null],
        ['code' => 'DEP-LEG', 'nameUz' => 'Yuridik Departamenti', 'shortName' => 'Yuridik', 'type' => 'DEPARTMENT', 'parentCode' => null],
        ['code' => 'DEP-OPS', 'nameUz' => 'Operatsion Departamenti', 'shortName' => 'Operatsion', 'type' => 'DEPARTMENT', 'parentCode' => null],
        ['code' => 'DEP-SEC', 'nameUz' => 'Xavfsizlik Departamenti', 'shortName' => 'Xavfsizlik', 'type' => 'DEPARTMENT', 'parentCode' => null],

        // IT branch
        ['code' => 'DEP-IT-INF', 'nameUz' => 'Infratuzilma Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-IT'],
        ['code' => 'DEP-IT-INF-NET', 'nameUz' => "Tarmoq Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-IT-INF'],
        ['code' => 'DEP-IT-INF-SRV', 'nameUz' => "Server Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-IT-INF'],
        ['code' => 'DEP-IT-DEV', 'nameUz' => "Dasturiy Ta'minot Boshqarmasi", 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-IT'],
        ['code' => 'DEP-IT-DEV-BE', 'nameUz' => "Backend Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-IT-DEV'],
        ['code' => 'DEP-IT-DEV-BE-API', 'nameUz' => "API Sho'basi", 'shortName' => null, 'type' => 'DEPARTMENT_SUB', 'parentCode' => 'DEP-IT-DEV-BE'],
        ['code' => 'DEP-IT-DEV-FE', 'nameUz' => "Frontend Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-IT-DEV'],
        ['code' => 'DEP-IT-DEV-FE-UI', 'nameUz' => "UI Sho'basi", 'shortName' => null, 'type' => 'DEPARTMENT_SUB', 'parentCode' => 'DEP-IT-DEV-FE'],

        // HR branch
        ['code' => 'DEP-HR-REC', 'nameUz' => 'Ishga Yollash Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-HR'],
        ['code' => 'DEP-HR-REC-ONB', 'nameUz' => "Onboarding Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-HR-REC'],
        ['code' => 'DEP-HR-COMP', 'nameUz' => 'Tovon va Imtiyozlar Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-HR'],

        // Finance branch
        ['code' => 'DEP-FIN-ACC', 'nameUz' => 'Buxgalteriya Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-FIN'],
        ['code' => 'DEP-FIN-ACC-TAX', 'nameUz' => "Soliq Hisoboti Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-FIN-ACC'],
        ['code' => 'DEP-FIN-BUD', 'nameUz' => 'Byudjet Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-FIN'],

        // Legal branch
        ['code' => 'DEP-LEG-CORP', 'nameUz' => "Korporativ Huquq Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-LEG'],

        // Operations branch
        ['code' => 'DEP-OPS-LOG', 'nameUz' => 'Logistika Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-OPS'],
        ['code' => 'DEP-OPS-PROC', 'nameUz' => 'Xaridlar Boshqarmasi', 'shortName' => null, 'type' => 'DIRECTORATE', 'parentCode' => 'DEP-OPS'],

        // Security branch
        ['code' => 'DEP-SEC-INFO', 'nameUz' => "Axborot Xavfsizligi Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-SEC'],
        ['code' => 'DEP-SEC-PHYS', 'nameUz' => "Jismoniy Xavfsizlik Bo'limi", 'shortName' => null, 'type' => 'DIVISION', 'parentCode' => 'DEP-SEC'],

        // Devonxona (chancellery) — root-level service unit.
        ['code' => 'DEV-01', 'nameUz' => 'Devonxona', 'shortName' => null, 'type' => 'OTHER', 'parentCode' => null],
    ];

    public function run(): void
    {
        $actorUuid = User::where('email', 'admin@devon.uz')->value('uuid') ?? (string) Str::uuid();

        /** @var array<string, Unit> $byCode */
        $byCode = [];

        foreach (self::SPECS as $spec) {
            $existing = Unit::where('code', $spec['code'])->first();
            if ($existing) {
                $byCode[$spec['code']] = $existing;

                continue;
            }

            $parent = $spec['parentCode'] ? ($byCode[$spec['parentCode']] ?? null) : null;
            $level = $parent ? $parent->level + 1 : 0;
            $uuid = (string) Str::uuid();
            $path = $parent ? "{$parent->path}{$uuid}/" : "/{$uuid}/";

            $unit = Unit::create([
                'uuid' => $uuid,
                'name_uz' => $spec['nameUz'],
                'short_name' => $spec['shortName'],
                'code' => $spec['code'],
                'type' => $spec['type'],
                'parent_uuid' => $parent?->uuid,
                'level' => $level,
                'path' => $path,
                'status' => 'ACTIVE',
                'created_by' => $actorUuid,
                'updated_by' => $actorUuid,
            ]);

            $byCode[$spec['code']] = $unit;
        }
    }
}
