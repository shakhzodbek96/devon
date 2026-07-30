<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard work shift, unit-level (PLAN_tabel-davomat.md §2, §0). `null`
 * unit_uuid = the organization-wide default (exactly one such row is
 * expected). Business rules (late/early-exit calculation) live in
 * `App\Services\AttendanceService` — this model is intentionally thin.
 */
class WorkShift extends Model
{
    /** @use HasFactory<\Database\Factories\WorkShiftFactory> */
    use HasFactory, HasUuids;

    protected $table = 'work_shifts';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'unit_uuid',
        'start_time',
        'end_time',
        'late_grace_min',
        'early_exit_grace_min',
    ];

    protected function casts(): array
    {
        return [
            'late_grace_min' => 'integer',
            'early_exit_grace_min' => 'integer',
        ];
    }
}
