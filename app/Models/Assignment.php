<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee → unit assignment (PLAN_PHASE_C.md §1.2). Mirrors the frontend
 * `Assignment` (src/types/domain.ts). Phase C only creates the hire-time
 * PRIMARY row; transfer/combination/close-old-assignment logic is Phase D.
 * No `updated_at` — matches the frontend type (createdAt only).
 */
class Assignment extends Model
{
    use HasUuids;

    protected $table = 'employee_unit_assignments';

    public const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'employee_uuid',
        'unit_uuid',
        'position_id',
        'is_primary',
        'start_date',
        'end_date',
        'workload_percent',
        'type',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'workload_percent' => 'integer',
        ];
    }
}
