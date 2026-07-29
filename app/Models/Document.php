<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document master record (PLAN_PHASE_F1.md §1, milestone 2 — BPMN 3.4 /
 * BP-4). Mirrors the frontend `DocumentEntity` (src/types/domain.ts).
 * Business rules (workflow engine, per-document authorization) live in
 * `App\Services\DocumentService` — this model is intentionally thin.
 *
 * ERI signing (SIGNED status) is milestone F2 — see `DocumentService` for
 * the exact scope line.
 */
class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory, HasUuids;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'number',
        'title',
        'source',
        'template_uuid',
        'values',
        'rendered_body',
        'file_meta',
        'confidentiality',
        'creator_uuid',
        'recipient_uuid',
        'signer_uuid',
        'requires_approval',
        'status',
        'round',
        'viewed_by',
        'sent_for_review_at',
        'approved_at',
        'signed_at',
        'closed_at',
        'archived_at',
        'emailed_to',
    ];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'file_meta' => 'array',
            'requires_approval' => 'boolean',
            'round' => 'integer',
            'viewed_by' => 'array',
            'sent_for_review_at' => 'datetime',
            'approved_at' => 'datetime',
            'signed_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
            'emailed_to' => 'array',
        ];
    }

    /** @return HasMany<ApprovalStep, $this> */
    public function approvalSteps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'document_uuid', 'uuid');
    }
}
