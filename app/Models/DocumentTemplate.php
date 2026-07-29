<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Document template catalogue (PLAN_PHASE_F1.md §1). Mirrors the frontend
 * `DocumentTemplate` (src/types/domain.ts). Seeded only — not user-created
 * in the demo (same convention as `Position`).
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentTemplateFactory> */
    use HasFactory, HasUuids;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'code',
        'name_uz',
        'description_uz',
        'body_template',
        'fields',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }
}
