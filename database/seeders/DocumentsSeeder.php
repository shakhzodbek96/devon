<?php

namespace Database\Seeders;

use App\Models\ApprovalStep;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Support\TemplateRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Document + approval-step fixtures ported from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `docSpecs` /
 * `buildDocumentDomain`). PLAN_PHASE_F1.md §4: 12 documents spanning every
 * `DocumentStatus`, linked to the five POV personas
 * (`EmployeesSeeder::PERSONAS`) exactly as the mock does, so the M2 pages
 * demo identically against the real backend.
 *
 * SIGNED rows (#2, #5) are seeded as descriptive history only — F1 never
 * exercises a real `signDocument` code path (that mutation is milestone
 * F2, which also adds the `signatures` table); these rows simply carry
 * `signed_at` with no backing signature record.
 *
 * Idempotent — guarded by `number` uniqueness (same convention as sibling
 * seeders).
 */
class DocumentsSeeder extends Seeder
{
    private const FIO_XODIM = 'Sobirova Dilnoza Murodovna';

    private const HR_ADMIN_NAME = 'Pulatov Asilbek Karimovich';

    private static function docUuid(int $n): string
    {
        return "00000000-0000-4000-8000-00000000d00{$n}";
    }

    private static function daysAgo(float $days): \Carbon\Carbon
    {
        return now()->subSeconds((int) round($days * 86400));
    }

    public function run(): void
    {
        if (Document::exists()) {
            return;
        }

        $p = EmployeesSeeder::PERSONAS;
        $templates = DocumentTemplate::all()->keyBy('code');

        $specs = [
            [
                'number' => 'HJ-2026/0001',
                'title' => "Kirish-chiqish tartibi to'g'risida bildirishnoma",
                'templateCode' => 'BILDIRISHNOMA',
                'values' => [
                    'SANA' => '01.06.2026',
                    'MAZMUN' => 'binoga kirish-chiqish yangi elektron ruxsatnomalar orqali amalga oshiriladi',
                    'MASUL_FIO' => 'Ergasheva Zarina Yusupovna',
                ],
                'creator' => $p['HR_ADMIN'], 'recipient' => $p['XODIM'],
                'requiresApproval' => false, 'status' => 'CLOSED',
                'createdDaysAgo' => 20, 'sentDaysAgo' => 19.5, 'approvedDaysAgo' => 19.5, 'closedDaysAgo' => 19,
                'viewedBy' => [['employeeUuid' => $p['XODIM'], 'daysAgo' => 19.2]],
            ],
            [
                'number' => 'HJ-2026/0002',
                'title' => "Lavozimga tayinlash to'g'risida buyruq",
                'templateCode' => 'BUYRUQ',
                'values' => [
                    'SANA' => '28.05.2026', 'RAQAM' => '17', 'XODIM_FIO' => self::FIO_XODIM,
                    'MAZMUN' => "API Sho'basida integratsiya loyihasini muvofiqlashtirish",
                    'ASOS' => "bo'lim boshlig'ining taqdimnomasi",
                ],
                'creator' => $p['BOLIM_BOSHLIGI'], 'recipient' => $p['XODIM'], 'signer' => $p['RAHBAR'],
                'requiresApproval' => true, 'status' => 'SIGNED',
                'createdDaysAgo' => 15, 'sentDaysAgo' => 14.5, 'approvedDaysAgo' => 14, 'signedDaysAgo' => 13.5,
                'steps' => [['order' => 1, 'employeeUuid' => $p['HR_ADMIN'], 'decision' => 'APPROVED', 'daysAgo' => 14]],
                'viewedBy' => [
                    ['employeeUuid' => $p['HR_ADMIN'], 'daysAgo' => 14.2],
                    ['employeeUuid' => $p['RAHBAR'], 'daysAgo' => 13.8],
                    ['employeeUuid' => $p['XODIM'], 'daysAgo' => 13],
                ],
            ],
            [
                'uuid' => self::docUuid(3),
                'number' => 'HJ-2026/0003',
                'title' => "Ta'til berish to'g'risida ariza",
                'templateCode' => 'ARIZA',
                'values' => ['SANA' => '15.06.2026', 'MAZMUN' => "yillik mehnat ta'tili (14 kun)", 'SABAB' => 'oilaviy sharoit'],
                'creator' => $p['XODIM'], 'recipient' => $p['BOLIM_BOSHLIGI'],
                'requiresApproval' => true, 'status' => 'REJECTED',
                'createdDaysAgo' => 5, 'sentDaysAgo' => 4.8,
                'steps' => [[
                    'order' => 1, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'REJECTED', 'daysAgo' => 4.5,
                    'comment' => "Ta'til jadvaliga mos kelmaydi — muddatni bo'lim jadvali bilan kelishib qayta kiriting.",
                ]],
                'viewedBy' => [['employeeUuid' => $p['BOLIM_BOSHLIGI'], 'daysAgo' => 4.6]],
            ],
            [
                'number' => 'HJ-2026/0004',
                'title' => "Ish joyidan ma'lumotnoma",
                'templateCode' => 'MALUMOTNOMA',
                'values' => ['XODIM_FIO' => self::FIO_XODIM, 'LAVOZIM' => 'Dasturchi', 'MAQSAD' => 'bank muassasasiga'],
                'creator' => $p['HR_ADMIN'], 'recipient' => $p['BOLIM_BOSHLIGI'],
                'requiresApproval' => true, 'status' => 'APPROVED',
                'createdDaysAgo' => 3, 'sentDaysAgo' => 2.8, 'approvedDaysAgo' => 2.6,
                'steps' => [['order' => 1, 'employeeUuid' => $p['RAHBAR'], 'decision' => 'APPROVED', 'daysAgo' => 2.6]],
                'viewedBy' => [
                    ['employeeUuid' => $p['RAHBAR'], 'daysAgo' => 2.7],
                    ['employeeUuid' => $p['BOLIM_BOSHLIGI'], 'daysAgo' => 2],
                ],
            ],
            [
                'uuid' => self::docUuid(2),
                'number' => 'HJ-2026/0005',
                'title' => "Server resurslarini kengaytirish to'g'risida",
                'templateCode' => 'XIZMAT_XATI',
                'values' => [
                    'KIMGA' => "Infratuzilma Boshqarmasi boshlig'i", 'SANA' => '09.06.2026',
                    'MAZMUN' => "API xizmatlari yuklamasining o'sishi munosabati bilan qo'shimcha server resurslari ajratish so'raladi",
                    'MUDDAT' => '20.06.2026',
                ],
                'creator' => $p['XODIM'], 'recipient' => $p['HR_ADMIN'], 'signer' => $p['RAHBAR'],
                'requiresApproval' => true, 'status' => 'SIGNED',
                'createdDaysAgo' => 2.5, 'sentDaysAgo' => 2.3, 'approvedDaysAgo' => 2.1, 'signedDaysAgo' => 1.8,
                'emailedTo' => ['arxiv@devon.uz'],
                'steps' => [['order' => 1, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'APPROVED', 'daysAgo' => 2.1]],
                'viewedBy' => [
                    ['employeeUuid' => $p['BOLIM_BOSHLIGI'], 'daysAgo' => 2.2],
                    ['employeeUuid' => $p['RAHBAR'], 'daysAgo' => 1.9],
                    ['employeeUuid' => $p['HR_ADMIN'], 'daysAgo' => 1.5],
                ],
            ],
            [
                'number' => 'HJ-2026/0006',
                'title' => "Texnik ta'minotni kengaytirish to'g'risida",
                'templateCode' => 'XIZMAT_XATI',
                'values' => [
                    'KIMGA' => 'IT Departamenti rahbari', 'SANA' => '10.06.2026',
                    'MAZMUN' => "ishlab chiqish serverlari uchun qo'shimcha quvvat ajratish masalasini ko'rib chiqish so'raladi",
                    'MUDDAT' => '30.06.2026',
                ],
                'creator' => $p['XODIM'], 'recipient' => $p['HR_ADMIN'], 'signer' => $p['RAHBAR'],
                'requiresApproval' => true, 'status' => 'IN_REVIEW',
                'createdDaysAgo' => 1.5, 'sentDaysAgo' => 1.4,
                'steps' => [
                    ['order' => 1, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'APPROVED_WITH_COMMENT', 'daysAgo' => 1.0, 'comment' => "Byudjet doirasida ma'qullayman."],
                    ['order' => 2, 'employeeUuid' => $p['RAHBAR'], 'decision' => 'PENDING'],
                ],
                'viewedBy' => [['employeeUuid' => $p['BOLIM_BOSHLIGI'], 'daysAgo' => 1.1]],
            ],
            [
                'uuid' => self::docUuid(1),
                'number' => 'HJ-2026/0007',
                'title' => "Qo'shimcha jihozlar ajratish to'g'risida",
                'templateCode' => 'XIZMAT_XATI',
                'values' => [
                    'KIMGA' => 'IT Departamenti rahbari', 'SANA' => '11.06.2026',
                    'MAZMUN' => "yangi xodimlar uchun ish stansiyalari ajratish so'raladi", 'MUDDAT' => '25.06.2026',
                ],
                'creator' => $p['XODIM'], 'recipient' => $p['BOLIM_BOSHLIGI'], 'signer' => $p['RAHBAR'],
                'requiresApproval' => true, 'status' => 'APPROVED',
                'createdDaysAgo' => 0.3, 'sentDaysAgo' => 0.2, 'approvedDaysAgo' => 0.15,
                'steps' => [['order' => 1, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'APPROVED', 'daysAgo' => 0.15]],
                'viewedBy' => [['employeeUuid' => $p['BOLIM_BOSHLIGI'], 'daysAgo' => 0.18]],
            ],
            [
                'uuid' => self::docUuid(6),
                'number' => 'HJ-2026/0008',
                'title' => "Attestatsiya o'tkazish to'g'risida bildirishnoma",
                'templateCode' => 'BILDIRISHNOMA',
                'values' => [
                    'SANA' => '15.06.2026', 'MAZMUN' => "Backend Bo'limida navbatdagi attestatsiya o'tkaziladi",
                    'MASUL_FIO' => self::HR_ADMIN_NAME,
                ],
                'creator' => $p['BOLIM_BOSHLIGI'], 'recipient' => $p['RAHBAR'],
                'requiresApproval' => true, 'status' => 'IN_REVIEW',
                'createdDaysAgo' => 0.5, 'sentDaysAgo' => 0.4,
                'steps' => [['order' => 1, 'employeeUuid' => $p['HR_ADMIN'], 'decision' => 'PENDING']],
            ],
            [
                'uuid' => self::docUuid(4),
                'number' => 'HJ-2026/0009',
                'title' => 'Boshqarma majlisi bayonnomasi',
                'fileMeta' => [
                    'fileName' => 'majlis_bayonnomasi_2026-21.pdf', 'fileSize' => 642 * 1024,
                    'mimeType' => 'application/pdf', 'uploadedAt' => self::daysAgo(4)->toIso8601String(),
                ],
                'confidentiality' => 'MAXFIY',
                'creator' => $p['BOLIM_BOSHLIGI'], 'recipient' => $p['HR_ADMIN'],
                'requiresApproval' => true, 'status' => 'CLOSED',
                'createdDaysAgo' => 4, 'sentDaysAgo' => 3.8, 'approvedDaysAgo' => 3.5, 'closedDaysAgo' => 3.2,
                'steps' => [['order' => 1, 'employeeUuid' => $p['RAHBAR'], 'decision' => 'APPROVED', 'daysAgo' => 3.5]],
                'viewedBy' => [
                    ['employeeUuid' => $p['RAHBAR'], 'daysAgo' => 3.6],
                    ['employeeUuid' => $p['HR_ADMIN'], 'daysAgo' => 3.3],
                ],
            ],
            [
                'number' => 'HJ-2026/0010',
                'title' => 'Hamkorlik taklifi (skan)',
                'fileMeta' => [
                    'fileName' => 'hamkorlik_taklifi_skan.pdf', 'fileSize' => 418 * 1024,
                    'mimeType' => 'application/pdf', 'uploadedAt' => self::daysAgo(0.8)->toIso8601String(),
                ],
                'creator' => $p['XODIM'], 'recipient' => $p['BOLIM_BOSHLIGI'],
                'requiresApproval' => true, 'status' => 'DRAFT',
                'createdDaysAgo' => 0.8,
                'steps' => [['order' => 1, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'PENDING']],
            ],
            [
                'uuid' => self::docUuid(5),
                'number' => 'HJ-2026/0011',
                'title' => "Doimiy lavozimga tayinlash to'g'risida buyruq",
                'templateCode' => 'BUYRUQ',
                'values' => [
                    'SANA' => '10.06.2026', 'RAQAM' => '23', 'XODIM_FIO' => "Norbo'taeva Mohira Sherzodovna",
                    'MAZMUN' => "doimiy mehnat shartnomasiga o'tkazilsin", 'ASOS' => "sinov muddati yakunlari to'g'risidagi xulosa",
                ],
                'creator' => $p['HR_ADMIN'], 'recipient' => $p['RAHBAR'], 'signer' => $p['RAHBAR'],
                'requiresApproval' => true, 'status' => 'IN_REVIEW',
                'createdDaysAgo' => 0.7, 'sentDaysAgo' => 0.6,
                'steps' => [
                    ['order' => 1, 'employeeUuid' => $p['RAHBAR'], 'decision' => 'APPROVED', 'daysAgo' => 0.3],
                    ['order' => 2, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'PENDING'],
                ],
                'viewedBy' => [['employeeUuid' => $p['RAHBAR'], 'daysAgo' => 0.35]],
            ],
            [
                'number' => 'HJ-2026/0012',
                'title' => "Malaka oshirish kursi to'g'risida",
                'templateCode' => 'XIZMAT_XATI',
                'values' => [
                    'KIMGA' => 'Kadrlar Departamenti', 'SANA' => '12.06.2026',
                    'MAZMUN' => "malaka oshirish kursiga yo'llanma berish so'raladi",
                ],
                'creator' => $p['XODIM'], 'recipient' => $p['HR_ADMIN'],
                'requiresApproval' => true, 'status' => 'DRAFT',
                'createdDaysAgo' => 0.1,
                'steps' => [
                    ['order' => 1, 'employeeUuid' => $p['BOLIM_BOSHLIGI'], 'decision' => 'PENDING'],
                    ['order' => 2, 'employeeUuid' => $p['RAHBAR'], 'decision' => 'PENDING'],
                ],
            ],
        ];

        foreach ($specs as $spec) {
            $template = isset($spec['templateCode']) ? $templates->get($spec['templateCode']) : null;
            $docUuid = $spec['uuid'] ?? (string) Str::uuid();
            $terminalDaysAgo = $spec['signedDaysAgo'] ?? $spec['closedDaysAgo'] ?? null;
            $updatedDaysAgo = $terminalDaysAgo ?? $spec['approvedDaysAgo'] ?? $spec['sentDaysAgo'] ?? $spec['createdDaysAgo'];

            $document = Document::create([
                'uuid' => $docUuid,
                'number' => $spec['number'],
                'title' => $spec['title'],
                'source' => $template ? 'TEMPLATE' : 'UPLOAD',
                'template_uuid' => $template?->uuid,
                'values' => $template ? ($spec['values'] ?? null) : null,
                'rendered_body' => $template ? TemplateRenderer::render($template->body_template, $spec['values'] ?? []) : null,
                'file_meta' => $spec['fileMeta'] ?? null,
                'confidentiality' => $spec['confidentiality'] ?? 'ODDIY',
                'creator_uuid' => $spec['creator'],
                'recipient_uuid' => $spec['recipient'],
                'signer_uuid' => $spec['signer'] ?? null,
                'requires_approval' => $spec['requiresApproval'],
                'status' => $spec['status'],
                'round' => 1,
                'viewed_by' => array_map(
                    fn ($v) => ['employeeUuid' => $v['employeeUuid'], 'viewedAt' => self::daysAgo($v['daysAgo'])->toIso8601String()],
                    $spec['viewedBy'] ?? [],
                ),
                'sent_for_review_at' => isset($spec['sentDaysAgo']) ? self::daysAgo($spec['sentDaysAgo']) : null,
                'approved_at' => isset($spec['approvedDaysAgo']) ? self::daysAgo($spec['approvedDaysAgo']) : null,
                'signed_at' => isset($spec['signedDaysAgo']) ? self::daysAgo($spec['signedDaysAgo']) : null,
                'closed_at' => isset($spec['closedDaysAgo']) ? self::daysAgo($spec['closedDaysAgo']) : null,
                'archived_at' => $terminalDaysAgo !== null ? self::daysAgo($terminalDaysAgo) : null,
                'emailed_to' => $spec['emailedTo'] ?? null,
                'created_at' => self::daysAgo($spec['createdDaysAgo']),
                'updated_at' => self::daysAgo($updatedDaysAgo),
            ]);

            foreach ($spec['steps'] ?? [] as $step) {
                ApprovalStep::create([
                    'uuid' => (string) Str::uuid(),
                    'document_uuid' => $document->uuid,
                    'round' => 1,
                    'order_no' => $step['order'],
                    'employee_uuid' => $step['employeeUuid'],
                    'decision' => $step['decision'],
                    'comment' => $step['comment'] ?? null,
                    'decided_at' => isset($step['daysAgo']) ? self::daysAgo($step['daysAgo']) : null,
                ]);
            }
        }
    }
}
