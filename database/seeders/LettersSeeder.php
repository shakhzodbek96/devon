<?php

namespace Database\Seeders;

use App\Models\Letter;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Letter fixtures ported from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `buildLetters`).
 * PLAN_PHASE_G.md §4: 12 letters (9 incoming + 3 outgoing) spanning every
 * BP-3 station (REGISTERED..DISPATCHED, plus CLOSED/CLOSED_NO_RESPONSE),
 * routed through the same IT branch the five personas live in (Backend
 * Bo'limi / `DEP-IT-DEV-BE`), linked to `EmployeesSeeder::PERSONAS` exactly
 * as the mock does.
 *
 * Rows are created directly (no live `LetterService` mutation calls) — same
 * convention as `DocumentsSeeder`: descriptive history only. No synthetic
 * audit trail is written for these rows (a letter walked live through
 * `LetterService` from this point on gets a real one); the detail
 * timeline for these seeded rows simply renders without a backing audit
 * entry per station, same as seeded documents.
 *
 * Idempotent — guarded by `Letter::exists()`, same convention as
 * `DocumentsSeeder`.
 */
class LettersSeeder extends Seeder
{
    private static function daysAgo(float $days): \Carbon\Carbon
    {
        return now()->subSeconds((int) round($days * 86400));
    }

    /**
     * @return array<string, mixed>
     */
    private static function pdfMeta(string $fileName, int $sizeKb, float $daysAgo): array
    {
        return [
            'fileName' => $fileName,
            'fileSize' => $sizeKb * 1024,
            'mimeType' => 'application/pdf',
            'uploadedAt' => self::daysAgo($daysAgo)->toIso8601String(),
        ];
    }

    public function run(): void
    {
        if (Letter::exists()) {
            return;
        }

        $p = EmployeesSeeder::PERSONAS;
        $backendBolim = Unit::where('code', 'DEP-IT-DEV-BE')->value('uuid');
        if (! $backendBolim) {
            // UnitsSeeder/EmployeesSeeder must run first (DatabaseSeeder order).
            return;
        }

        // K-2026/0001's uuid is needed twice: on the incoming row itself and
        // as CH-2026/0001's `linked_incoming_uuid`.
        $closedIncomingUuid = (string) Str::uuid();

        $rows = [
            // K-2026/0001 — the full happy path, CLOSED: registered -> routed
            // -> assigned -> executed (response file) -> accepted -> signed
            // by the Rahbar -> dispatched.
            [
                'uuid' => $closedIncomingUuid,
                'direction' => 'INCOMING',
                'number' => 'K-2026/0001',
                'external_org' => 'Toshkent shahar hokimligi',
                'subject' => "Axborot tizimlari integratsiyasi to'g'risida so'rov",
                'channel' => 'POCHTA',
                'file_meta' => self::pdfMeta('kiruvchi_K-2026-0001_skan.pdf', 412, 9),
                'received_at' => self::daysAgo(9)->format('Y-m-d'),
                'deadline' => self::daysAgo(1)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => true,
                'response_file_meta' => self::pdfMeta('javob_K-2026-0001.pdf', 188, 4),
                'status' => 'CLOSED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'dispatched_at' => self::daysAgo(2.5),
                'closed_at' => self::daysAgo(2.5),
                'created_at' => self::daysAgo(9),
                'updated_at' => self::daysAgo(2.5),
            ],
            // K-2026/0002 — no signature required, response file present ->
            // RESPONDED, awaiting Devonxona dispatch.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0002',
                'external_org' => "O'zstandart agentligi",
                'subject' => "Ma'lumotlar almashinuvi reglamenti loyihasi",
                'channel' => 'EMAIL',
                'file_meta' => self::pdfMeta('kiruvchi_K-2026-0002_skan.pdf', 256, 5.5),
                'received_at' => self::daysAgo(5.5)->format('Y-m-d'),
                'deadline' => self::daysAgo(-3)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => false,
                'response_file_meta' => self::pdfMeta('javob_K-2026-0002.pdf', 145, 1.2),
                'status' => 'RESPONDED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(5.5),
                'updated_at' => self::daysAgo(0.9),
            ],
            // K-2026/0003 — comment-only execution accepted -> CLOSED_NO_RESPONSE.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0003',
                'external_org' => 'Raqamli texnologiyalar vazirligi',
                'subject' => "Seminar o'tkazilishi haqida xabarnoma",
                'channel' => 'EMAIL',
                'received_at' => self::daysAgo(5.2)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => false,
                'execution_comment' => "Ma'lumot uchun qabul qilindi, bo'lim xodimlari seminar haqida xabardor qilindi. Javob xati talab etilmaydi.",
                'status' => 'CLOSED_NO_RESPONSE',
                'registered_by_uuid' => $p['DEVONXONA'],
                'closed_at' => self::daysAgo(3),
                'created_at' => self::daysAgo(5.2),
                'updated_at' => self::daysAgo(3),
            ],
            // K-2026/0004 — response ready, awaiting the Rahbar's ERI.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0004',
                'external_org' => "O'zbekiston Respublikasi Markaziy banki",
                'subject' => 'Hamkorlik memorandumi loyihasini kelishish',
                'channel' => 'KURYER',
                'file_meta' => self::pdfMeta('kiruvchi_K-2026-0004_skan.pdf', 530, 4),
                'received_at' => self::daysAgo(4)->format('Y-m-d'),
                'deadline' => self::daysAgo(-5)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => true,
                'response_file_meta' => self::pdfMeta('javob_K-2026-0004.pdf', 210, 1),
                'status' => 'ON_SIGNATURE',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(4),
                'updated_at' => self::daysAgo(0.7),
            ],
            // K-2026/0005 — executor submitted a response file; awaiting
            // the BOLIM_BOSHLIGI's acceptance.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0005',
                'external_org' => "Soliq qo'mitasi",
                'subject' => "Statistik hisobot taqdim etish to'g'risida",
                'channel' => 'POCHTA',
                'file_meta' => self::pdfMeta('kiruvchi_K-2026-0005_skan.pdf', 320, 3),
                'received_at' => self::daysAgo(3)->format('Y-m-d'),
                'deadline' => self::daysAgo(-7)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => false,
                'response_file_meta' => self::pdfMeta('javob_K-2026-0005.pdf', 96, 0.5),
                'status' => 'EXECUTED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(3),
                'updated_at' => self::daysAgo(0.5),
            ],
            // K-2026/0006 — XODIM working on it.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0006',
                'external_org' => 'Adliya vazirligi',
                'subject' => "Server uskunalari texnik talablariga oid so'rov",
                'channel' => 'EMAIL',
                'received_at' => self::daysAgo(2.5)->format('Y-m-d'),
                'deadline' => self::daysAgo(-4)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => false,
                'status' => 'IN_PROGRESS',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(2.5),
                'updated_at' => self::daysAgo(1.8),
            ],
            // K-2026/0007 — assigned with a deadline already in the past ->
            // the registry's one visibly OVERDUE row.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0007',
                'external_org' => 'Xavfsizlik kengashi kotibiyati',
                'subject' => 'Kiberxavfsizlik auditi natijalarini taqdim etish',
                'channel' => 'QOGOZ',
                'file_meta' => self::pdfMeta('kiruvchi_K-2026-0007_skan.pdf', 275, 2.4),
                'received_at' => self::daysAgo(2.4)->format('Y-m-d'),
                'deadline' => self::daysAgo(1)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'assigned_employee_uuid' => $p['XODIM'],
                'requires_signature' => true,
                'status' => 'ASSIGNED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(2.4),
                'updated_at' => self::daysAgo(2.1),
            ],
            // K-2026/0008 — routed to the Backend Bo'limi; head hasn't
            // assigned yet.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0008',
                'external_org' => "O'zarxiv agentligi",
                'subject' => "Dasturiy ta'minot litsenziyalari to'g'risida ma'lumot so'rovi",
                'channel' => 'EMAIL',
                'received_at' => self::daysAgo(1)->format('Y-m-d'),
                'deadline' => self::daysAgo(-10)->format('Y-m-d'),
                'routed_to_unit_uuid' => $backendBolim,
                'requires_signature' => false,
                'status' => 'ROUTED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(1),
                'updated_at' => self::daysAgo(0.5),
            ],
            // K-2026/0009 — fresh, no routing yet (the Rahbar's routing queue).
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => 'K-2026/0009',
                'external_org' => 'Elektron hukumat loyihalari milliy agentligi',
                'subject' => 'Davlat xizmatlari portali bilan integratsiya taklifi',
                'channel' => 'POCHTA',
                'file_meta' => self::pdfMeta('kiruvchi_K-2026-0009_skan.pdf', 364, 0.2),
                'received_at' => self::daysAgo(0.2)->format('Y-m-d'),
                'requires_signature' => false,
                'status' => 'REGISTERED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'created_at' => self::daysAgo(0.2),
                'updated_at' => self::daysAgo(0.2),
            ],
            // CH-2026/0001 — the dispatched reply to K-2026/0001, carrying
            // the Rahbar's signature (via the shared `signatures` table —
            // not seeded here, mirrors DocumentsSeeder's SIGNED rows having
            // no backing signature record either) and the response package.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'OUTGOING',
                'number' => 'CH-2026/0001',
                'external_org' => 'Toshkent shahar hokimligi',
                'subject' => "Axborot tizimlari integratsiyasi to'g'risida so'rov",
                'channel' => 'POCHTA',
                'file_meta' => self::pdfMeta('javob_K-2026-0001.pdf', 188, 2.5),
                'requires_signature' => true,
                'linked_incoming_uuid' => $closedIncomingUuid,
                'status' => 'DISPATCHED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'dispatched_at' => self::daysAgo(2.5),
                'created_at' => self::daysAgo(2.5),
                'updated_at' => self::daysAgo(2.5),
            ],
            // CH-2026/0002 / 0003 — standalone outbound letters (no linked
            // incoming): the org's own initiative correspondence.
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'OUTGOING',
                'number' => 'CH-2026/0002',
                'external_org' => "O'zarxiv agentligi",
                'subject' => "Yillik hisobotni taqdim etish to'g'risida",
                'channel' => 'EMAIL',
                'file_meta' => self::pdfMeta('chiquvchi_CH-2026-0002.pdf', 152, 1.8),
                'requires_signature' => false,
                'status' => 'DISPATCHED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'dispatched_at' => self::daysAgo(1.8),
                'created_at' => self::daysAgo(1.8),
                'updated_at' => self::daysAgo(1.8),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'direction' => 'OUTGOING',
                'number' => 'CH-2026/0003',
                'external_org' => 'Toshkent axborot texnologiyalari universiteti',
                'subject' => "Texnik hamkorlik bo'yicha taklifnoma",
                'channel' => 'KURYER',
                'file_meta' => self::pdfMeta('chiquvchi_CH-2026-0003.pdf', 98, 0.8),
                'requires_signature' => false,
                'status' => 'DISPATCHED',
                'registered_by_uuid' => $p['DEVONXONA'],
                'dispatched_at' => self::daysAgo(0.8),
                'created_at' => self::daysAgo(0.8),
                'updated_at' => self::daysAgo(0.8),
            ],
        ];

        foreach ($rows as $row) {
            Letter::create($row);
        }
    }
}
