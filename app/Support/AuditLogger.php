<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Cross-cutting append-only audit writer (PLAN_PHASE_AB.md §1.1). Every
 * mutation across the backend — auth actions and domain mutations alike —
 * calls `AuditLogger::log()` after the write succeeds. Mirrors the frontend
 * mock's `appendAudit` (src/lib/mock-backend/index.ts).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $context
     */
    public static function log(
        User $actor,
        string $action,
        string $resourceType,
        string $resourceUuid,
        string $resourceLabel,
        ?array $changes = null,
        ?array $context = null,
    ): AuditLog {
        return AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'actor_uuid' => $actor->uuid,
            'actor_name' => $actor->full_name,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_uuid' => $resourceUuid,
            'resource_label' => $resourceLabel,
            'changes' => $changes,
            'context' => $context,
        ]);
    }
}
