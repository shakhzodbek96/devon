<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Notification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Notification fixtures ported from the frontend mock's `buildNotifications`
 * (frontend/dashboard/src/lib/mock-backend/seed.ts) — the document-scoped
 * rows only (PLAN_PHASE_F1.md §4/§5: letters/tasks notifications stay on
 * the mock backend entirely, since those modules are untouched in F1).
 * Linked to the `DocumentsSeeder` fixture uuids by document number.
 */
class NotificationsSeeder extends Seeder
{
    private static function daysAgo(float $days): \Carbon\Carbon
    {
        return now()->subSeconds((int) round($days * 86400));
    }

    public function run(): void
    {
        if (Notification::exists()) {
            return;
        }

        $p = EmployeesSeeder::PERSONAS;
        $nameOf = fn (string $employeeUuid) => Employee::where('uuid', $employeeUuid)->value('full_name_generated') ?? '';
        $xodim = $nameOf($p['XODIM']);
        $rahbar = $nameOf($p['RAHBAR']);
        $bolim = $nameOf($p['BOLIM_BOSHLIGI']);
        $hrAdmin = $nameOf($p['HR_ADMIN']);

        $docUuid = fn (int $n) => "00000000-0000-4000-8000-00000000d00{$n}";

        $specs = [
            // HJ-2026/0007 — XODIM's draft mid-chain: kelishuv -> qaror -> imzo
            ['recipient' => $p['BOLIM_BOSHLIGI'], 'type' => 'DOC_REVIEW_REQUESTED', 'params' => ['docNumber' => 'HJ-2026/0007', 'actorName' => $xodim], 'resourceUuid' => $docUuid(1), 'isRead' => false, 'daysAgo' => 0.2],
            ['recipient' => $p['XODIM'], 'type' => 'DOC_DECIDED', 'params' => ['docNumber' => 'HJ-2026/0007', 'actorName' => $bolim], 'resourceUuid' => $docUuid(1), 'isRead' => false, 'daysAgo' => 0.15],
            ['recipient' => $p['RAHBAR'], 'type' => 'DOC_SIGN_REQUESTED', 'params' => ['docNumber' => 'HJ-2026/0007', 'actorName' => $xodim], 'resourceUuid' => $docUuid(1), 'isRead' => false, 'daysAgo' => 0.1],

            // HJ-2026/0005 — fully approved + signed
            ['recipient' => $p['XODIM'], 'type' => 'DOC_APPROVED', 'params' => ['docNumber' => 'HJ-2026/0005', 'actorName' => $bolim], 'resourceUuid' => $docUuid(2), 'isRead' => true, 'daysAgo' => 2.1],
            ['recipient' => $p['XODIM'], 'type' => 'DOC_SIGNED', 'params' => ['docNumber' => 'HJ-2026/0005', 'actorName' => $rahbar], 'resourceUuid' => $docUuid(2), 'isRead' => true, 'daysAgo' => 1.8],
            ['recipient' => $p['HR_ADMIN'], 'type' => 'DOC_SIGNED', 'params' => ['docNumber' => 'HJ-2026/0005', 'actorName' => $rahbar], 'resourceUuid' => $docUuid(2), 'isRead' => false, 'daysAgo' => 1.8],

            // HJ-2026/0003 — rejected, back to rework
            ['recipient' => $p['XODIM'], 'type' => 'DOC_REJECTED', 'params' => ['docNumber' => 'HJ-2026/0003', 'actorName' => $bolim], 'resourceUuid' => $docUuid(3), 'isRead' => true, 'daysAgo' => 4.5],

            // HJ-2026/0009 — accepted without ERI (CLOSED branch)
            ['recipient' => $p['BOLIM_BOSHLIGI'], 'type' => 'DOC_CLOSED', 'params' => ['docNumber' => 'HJ-2026/0009', 'actorName' => $hrAdmin], 'resourceUuid' => $docUuid(4), 'isRead' => true, 'daysAgo' => 3.2],

            // Standalone review requests landing on RAHBAR / HR_ADMIN
            ['recipient' => $p['RAHBAR'], 'type' => 'DOC_REVIEW_REQUESTED', 'params' => ['docNumber' => 'HJ-2026/0011', 'actorName' => $hrAdmin], 'resourceUuid' => $docUuid(5), 'isRead' => false, 'daysAgo' => 0.6],
            ['recipient' => $p['HR_ADMIN'], 'type' => 'DOC_REVIEW_REQUESTED', 'params' => ['docNumber' => 'HJ-2026/0008', 'actorName' => $bolim], 'resourceUuid' => $docUuid(6), 'isRead' => false, 'daysAgo' => 0.4],
            ['recipient' => $p['HR_ADMIN'], 'type' => 'DOC_DECIDED', 'params' => ['docNumber' => 'HJ-2026/0011', 'actorName' => $rahbar], 'resourceUuid' => $docUuid(5), 'isRead' => true, 'daysAgo' => 0.3],
        ];

        foreach ($specs as $spec) {
            Notification::create([
                'uuid' => (string) Str::uuid(),
                'recipient_employee_uuid' => $spec['recipient'],
                'type' => $spec['type'],
                'title_key' => "dashboard:notifications.title.{$spec['type']}",
                'params' => $spec['params'],
                'resource_type' => 'document',
                'resource_uuid' => $spec['resourceUuid'],
                'is_read' => $spec['isRead'],
                'created_at' => self::daysAgo($spec['daysAgo']),
            ]);
        }
    }
}
