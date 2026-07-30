<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily check-in/check-out record (PLAN_tabel-davomat.md §2). Business
 * rules (network CIDR check, late/early-exit calc, auto-checkout on second
 * attempt) live in `App\Services\AttendanceService` — this model is
 * intentionally thin. `face_status` is BLOCKED(face-recognition) — see
 * PLAN §0.
 */
class AttendanceRecord extends Model
{
    use HasUuids;

    protected $table = 'attendance_records';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'employee_uuid',
        'work_date',
        'check_in_at',
        'check_out_at',
        'status',
        'late_minutes',
        'early_exit_minutes',
        'network_ok',
        'face_status',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'late_minutes' => 'integer',
            'early_exit_minutes' => 'integer',
            'network_ok' => 'boolean',
        ];
    }
}
