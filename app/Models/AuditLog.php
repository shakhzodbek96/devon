<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only audit trail row (PLAN_PHASE_AB.md §1.1). Mirrors the frontend
 * `AuditEntry` (src/types/domain.ts). Immutable once written — `update()`
 * and `delete()` are blocked at the model layer for every caller,
 * including ROLE_SUPER_ADMIN (CLAUDE.md: "audit entries are append-only").
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    /** No `updated_at` column — rows never change after insert. */
    const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'actor_uuid',
        'actor_name',
        'action',
        'resource_type',
        'resource_uuid',
        'resource_label',
        'changes',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }
}
