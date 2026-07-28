<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Organizational structure node (PLAN_PHASE_AB.md §2.1). Mirrors the
 * frontend `Unit` (src/types/domain.ts). Business rules (max depth,
 * duplicate-name, cycle prevention, path/level recompute on move) live in
 * `App\Services\UnitService` — this model is intentionally thin.
 */
class Unit extends Model
{
    /** @use HasFactory<\Database\Factories\UnitFactory> */
    use HasFactory, HasUuids;

    protected $table = 'structural_units';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'name_uz',
        'name_ru',
        'short_name',
        'code',
        'type',
        'parent_uuid',
        'level',
        'path',
        'head_employee_uuid',
        'deputy_employee_uuid',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }
}
