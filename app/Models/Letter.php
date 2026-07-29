<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Incoming/outgoing correspondence master record (PLAN_PHASE_G.md §1, BP-3).
 * Mirrors the frontend `Letter` (src/types/domain.ts). Business rules (the
 * full BP-3 lifecycle, per-station authorization, auto-numbering) live in
 * `App\Services\LetterService` — this model is intentionally thin.
 *
 * ERI signing (ON_SIGNATURE -> RESPONDED, `LetterService::sign`) reuses the
 * shared `signatures` table with `resource_type='letter'` — see
 * `PLAN_PHASE_F2.md` §1 and `LetterService::sign`.
 *
 * No factory — same convention as `Document` (rows are built through the
 * service/API in tests, not `Letter::factory()`).
 */
class Letter extends Model
{
    use HasUuids;

    protected $table = 'letters';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'direction',
        'number',
        'external_org',
        'subject',
        'channel',
        'file_meta',
        'received_at',
        'deadline',
        'routed_to_unit_uuid',
        'assigned_employee_uuid',
        'requires_signature',
        'execution_comment',
        'response_file_meta',
        'response_document_uuid',
        'linked_incoming_uuid',
        'status',
        'registered_by_uuid',
        'dispatched_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_meta' => 'array',
            'received_at' => 'date:Y-m-d',
            'deadline' => 'date:Y-m-d',
            'requires_signature' => 'boolean',
            'response_file_meta' => 'array',
            'dispatched_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
