<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Task fixtures ported from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `buildTasks`).
 * PLAN_PHASE_H.md §4: 12 tasks spanning every BP-2 station (NEW..DONE, plus
 * REJECTED), two assigner/assignee pairs (BOLIM_BOSHLIGI -> XODIM is the
 * bulk of the board; RAHBAR -> BOLIM_BOSHLIGI covers the Rahbar's own
 * assigned-by-me box), comment threads (clarification request/reply,
 * return feedback, accept-with-note, reject reason), a real attached
 * document and a real deliverable document, linked to
 * `EmployeesSeeder::PERSONAS` exactly as the mock does.
 *
 * Rows are created directly (no live `TaskService` mutation calls) — same
 * convention as `DocumentsSeeder`/`LettersSeeder`: descriptive history
 * only, no synthetic audit trail for these rows.
 *
 * Idempotent — guarded by `Task::exists()`, same convention as `LettersSeeder`.
 */
class TasksSeeder extends Seeder
{
    private static function daysAgo(float $days): \Carbon\Carbon
    {
        return now()->subSeconds((int) round($days * 86400));
    }

    private static function daysFromNow(float $days): \Carbon\Carbon
    {
        return now()->addSeconds((int) round($days * 86400));
    }

    public function run(): void
    {
        if (Task::exists()) {
            return;
        }

        $p = EmployeesSeeder::PERSONAS;
        $ak = $p['BOLIM_BOSHLIGI']; // Akhmedov — Backend Bo'lim head
        $so = $p['XODIM']; // Sobirova — API Sho'ba dasturchisi
        $ka = $p['RAHBAR']; // Karimov — root IT Departament head

        $attachedDoc = Document::where('number', 'HJ-2026/0011')->value('uuid');
        $deliverableDoc = Document::where('number', 'HJ-2026/0007')->value('uuid');
        if (! $attachedDoc || ! $deliverableDoc) {
            // DocumentsSeeder must run first (DatabaseSeeder order).
            return;
        }

        $rows = [
            // TOP-2026/0001 — NEW, HIGH, future deadline (sits in Sobirova's inbox).
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0001',
                'title' => 'API endpointlarini hujjatlashtirish',
                'description' => "To'lov xizmati API endpointlari uchun OpenAPI spetsifikatsiyasini tayyorlang va misol so'rovlarni qo'shing.",
                'priority' => 'HIGH',
                'status' => 'NEW',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysFromNow(5)->format('Y-m-d'),
                'round' => 1,
                'created_at' => self::daysAgo(1),
                'updated_at' => self::daysAgo(1),
            ],
            // TOP-2026/0002 — NEW, MEDIUM, one open CLARIFICATION_REQUEST.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0002',
                'title' => 'Integratsiya testlari uchun muhit sozlash',
                'description' => "CI quvurida integratsiya testlari uchun alohida ma'lumotlar bazasi muhitini sozlang.",
                'priority' => 'MEDIUM',
                'status' => 'NEW',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysFromNow(7)->format('Y-m-d'),
                'round' => 1,
                'created_at' => self::daysAgo(0.8),
                'updated_at' => self::daysAgo(0.4),
            ],
            // TOP-2026/0003 — IN_PROGRESS, STANDARD, startedAt set.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0003',
                'title' => 'Backend birlik testlarini yozish',
                'description' => "Avtorizatsiya moduli uchun birlik testlarini yozing — qamrov kamida 80% bo'lsin.",
                'priority' => 'STANDARD',
                'status' => 'IN_PROGRESS',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysFromNow(4)->format('Y-m-d'),
                'round' => 1,
                'started_at' => self::daysAgo(1.5),
                'created_at' => self::daysAgo(3),
                'updated_at' => self::daysAgo(1.5),
            ],
            // TOP-2026/0004 — IN_PROGRESS, MEDIUM, round 2 (returned once).
            // Full thread: clarification request + reply, then a return.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0004',
                'title' => "Ma'lumotlar bazasi migratsiyalarini tayyorlash",
                'description' => "Yangi tranzaksiya jadvali uchun migratsiya skriptlarini yozing va orqaga qaytarish (rollback) imkonini qo'shing.",
                'priority' => 'MEDIUM',
                'status' => 'IN_PROGRESS',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysFromNow(3)->format('Y-m-d'),
                'round' => 2,
                'started_at' => self::daysAgo(5),
                'submitted_at' => self::daysAgo(3),
                'created_at' => self::daysAgo(6),
                'updated_at' => self::daysAgo(2.5),
            ],
            // TOP-2026/0005 — UNDER_REVIEW, HIGH, has deliverable (manager review queue).
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0005',
                'title' => 'Kesh qatlamini optimallashtirish',
                'description' => "Tez-tez so'raladigan so'rovlar uchun Redis kesh qatlamini joriy eting va javob vaqtini o'lchang.",
                'priority' => 'HIGH',
                'status' => 'UNDER_REVIEW',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysFromNow(2)->format('Y-m-d'),
                'deliverable' => [
                    'summary' => "Redis kesh qatlami joriy etildi; o'rtacha javob vaqti 320 ms dan 90 ms gacha qisqardi. Natijalar PR da.",
                    'submittedAt' => self::daysAgo(0.5)->toIso8601String(),
                ],
                'submitted_at' => self::daysAgo(0.5),
                'round' => 1,
                'started_at' => self::daysAgo(2),
                'created_at' => self::daysAgo(3.5),
                'updated_at' => self::daysAgo(0.5),
            ],
            // TOP-2026/0006 — UNDER_REVIEW, HIGH, OVERDUE (deadline 2 days past).
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0006',
                'title' => "Xavfsizlik auditini o'tkazish",
                'description' => "Autentifikatsiya oqimida xavfsizlik tekshiruvini o'tkazing va topilgan kamchiliklar bo'yicha hisobot tayyorlang.",
                'priority' => 'HIGH',
                'status' => 'UNDER_REVIEW',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysAgo(2)->format('Y-m-d'),
                'deliverable' => [
                    'summary' => "Audit yakunlandi; 3 ta o'rta darajadagi kamchilik topildi va tuzatish bo'yicha tavsiyalar berildi. Hisobot ilova qilindi.",
                    'submittedAt' => self::daysAgo(0.3)->toIso8601String(),
                ],
                'submitted_at' => self::daysAgo(0.3),
                'late_submission' => true,
                'round' => 1,
                'started_at' => self::daysAgo(4),
                'created_at' => self::daysAgo(6),
                'updated_at' => self::daysAgo(0.3),
            ],
            // TOP-2026/0007 — DONE, STANDARD, accepted on time.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0007',
                'title' => "Loglash formatini standartlashtirish",
                'description' => 'Barcha xizmatlar uchun yagona JSON loglash formatini joriy eting.',
                'priority' => 'STANDARD',
                'status' => 'DONE',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysAgo(1)->format('Y-m-d'),
                'deliverable' => [
                    'summary' => "Barcha mikroservislar yagona structured JSON loglash formatiga o'tkazildi.",
                    'submittedAt' => self::daysAgo(2)->toIso8601String(),
                ],
                'submitted_at' => self::daysAgo(2),
                'accepted_at' => self::daysAgo(1.5),
                'round' => 1,
                'started_at' => self::daysAgo(4),
                'created_at' => self::daysAgo(5),
                'updated_at' => self::daysAgo(1.5),
            ],
            // TOP-2026/0008 — DONE, MEDIUM, lateSubmission + accept-with-note.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0008',
                'title' => "Webhook qayta urinish mexanizmini qo'shish",
                'description' => "Muvaffaqiyatsiz webhook yetkazib berishlar uchun eksponentsial qayta urinish mexanizmini joriy eting.",
                'priority' => 'MEDIUM',
                'status' => 'DONE',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysAgo(4)->format('Y-m-d'),
                'deliverable' => [
                    'summary' => "Qayta urinish mexanizmi qo'shildi; 3 martagacha eksponentsial kechikish bilan urinadi.",
                    'submittedAt' => self::daysAgo(3)->toIso8601String(),
                ],
                'submitted_at' => self::daysAgo(3),
                'accepted_at' => self::daysAgo(2.5),
                'review_note' => "Ish sifati yaxshi, lekin muddatdan kechikdi — keyingi safar muddatni nazoratda tuting.",
                'late_submission' => true,
                'round' => 1,
                'started_at' => self::daysAgo(7),
                'created_at' => self::daysAgo(8),
                'updated_at' => self::daysAgo(2.5),
            ],
            // TOP-2026/0009 — REJECTED, STANDARD, reject reason comment.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0009',
                'title' => "Eski API versiyasini olib tashlash",
                'description' => "v1 API endpointlarini butunlay olib tashlang — barcha mijozlar v2 ga o'tgan.",
                'priority' => 'STANDARD',
                'status' => 'REJECTED',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysAgo(3)->format('Y-m-d'),
                'deliverable' => [
                    'summary' => "v1 endpointlari kod bazasidan olib tashlandi.",
                    'submittedAt' => self::daysAgo(4)->toIso8601String(),
                ],
                'submitted_at' => self::daysAgo(4),
                'rejected_at' => self::daysAgo(3.5),
                'round' => 1,
                'started_at' => self::daysAgo(6),
                'created_at' => self::daysAgo(7),
                'updated_at' => self::daysAgo(3.5),
            ],
            // TOP-2026/0010 — IN_PROGRESS, MEDIUM, with a real attached document.
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0010',
                'title' => "Buyruq bo'yicha tizim sozlamalarini yangilash",
                'description' => "Ilova qilingan buyruqqa asosan ruxsatnoma tizimi sozlamalarini yangilang.",
                'priority' => 'MEDIUM',
                'status' => 'IN_PROGRESS',
                'assigner_uuid' => $ak,
                'assignee_uuid' => $so,
                'deadline' => self::daysFromNow(6)->format('Y-m-d'),
                'attached_document_uuid' => $attachedDoc,
                'round' => 1,
                'started_at' => self::daysAgo(0.6),
                'created_at' => self::daysAgo(1.2),
                'updated_at' => self::daysAgo(0.6),
            ],
            // TOP-2026/0011 — Karimov -> Akhmedov, NEW, HIGH (Rahbar's assigned board).
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0011',
                'title' => "Choraklik texnik hisobotni tayyorlash",
                'description' => "Backend Bo'limining choraklik texnik ko'rsatkichlari bo'yicha hisobot tayyorlang.",
                'priority' => 'HIGH',
                'status' => 'NEW',
                'assigner_uuid' => $ka,
                'assignee_uuid' => $ak,
                'deadline' => self::daysFromNow(8)->format('Y-m-d'),
                'round' => 1,
                'created_at' => self::daysAgo(0.7),
                'updated_at' => self::daysAgo(0.7),
            ],
            // TOP-2026/0012 — Karimov -> Akhmedov, UNDER_REVIEW, MEDIUM,
            // deliverable.documentUuid points at a real seeded document
            // (Rahbar's review queue).
            [
                'uuid' => (string) Str::uuid(),
                'number' => 'TOP-2026/0012',
                'title' => "Departament infratuzilma rejasini ishlab chiqish",
                'description' => "Kelgusi yil uchun server va infratuzilma resurslari rejasini hujjat ko'rinishida taqdim eting.",
                'priority' => 'MEDIUM',
                'status' => 'UNDER_REVIEW',
                'assigner_uuid' => $ka,
                'assignee_uuid' => $ak,
                'deadline' => self::daysFromNow(1)->format('Y-m-d'),
                'deliverable' => [
                    'summary' => "Infratuzilma rejasi hujjat shaklida tayyorlandi va ko'rib chiqish uchun ilova qilindi.",
                    'documentUuid' => $deliverableDoc,
                    'submittedAt' => self::daysAgo(0.4)->toIso8601String(),
                ],
                'submitted_at' => self::daysAgo(0.4),
                'round' => 1,
                'started_at' => self::daysAgo(2),
                'created_at' => self::daysAgo(3),
                'updated_at' => self::daysAgo(0.4),
            ],
        ];

        $taskUuidByNumber = [];
        foreach ($rows as $row) {
            Task::create($row);
            $taskUuidByNumber[$row['number']] = $row['uuid'];
        }

        // Comment threads (PLAN_PHASE_H.md §4).
        $comments = [
            // TOP-2026/0002 — open clarification request (no reply yet).
            ['task' => 'TOP-2026/0002', 'author' => $so, 'kind' => 'CLARIFICATION_REQUEST', 'body' => "Test muhiti uchun alohida server kerakmi yoki mavjud staging serveridan foydalansa bo'ladimi?", 'daysAgo' => 0.4],
            // TOP-2026/0004 — clarification request + reply, then a return.
            ['task' => 'TOP-2026/0004', 'author' => $so, 'kind' => 'CLARIFICATION_REQUEST', 'body' => "Indekslarni shu migratsiyada qo'shaymi yoki alohida bosqichda?", 'daysAgo' => 5.5],
            ['task' => 'TOP-2026/0004', 'author' => $ak, 'kind' => 'CLARIFICATION_REPLY', 'body' => "Indekslarni shu migratsiyaning oxirida qo'shing, alohida bosqich shart emas.", 'daysAgo' => 5.2],
            ['task' => 'TOP-2026/0004', 'author' => $ak, 'kind' => 'RETURN_FEEDBACK', 'body' => "Rollback skripti yetishmayapti — har bir migratsiya uchun qaytarish bandini qo'shib qayta yuboring.", 'daysAgo' => 2.5],
            // TOP-2026/0008 — accept-with-note.
            ['task' => 'TOP-2026/0008', 'author' => $ak, 'kind' => 'NOTE', 'body' => "Yaxshi bajarilgan, biroq topshiriq muddatidan kechikkan holda topshirildi.", 'daysAgo' => 2.5],
            // TOP-2026/0009 — reject reason.
            ['task' => 'TOP-2026/0009', 'author' => $ak, 'kind' => 'REJECT_REASON', 'body' => "Bir nechta tashqi mijoz hali ham v1 dan foydalanmoqda — bu topshiriq hozircha bekor qilinadi.", 'daysAgo' => 3.5],
        ];

        foreach ($comments as $c) {
            TaskComment::create([
                'uuid' => (string) Str::uuid(),
                'task_uuid' => $taskUuidByNumber[$c['task']],
                'author_uuid' => $c['author'],
                'kind' => $c['kind'],
                'body' => $c['body'],
                'created_at' => self::daysAgo($c['daysAgo']),
            ]);
        }
    }
}
