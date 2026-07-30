<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable per-employee-per-day cell of a `Tabel` (PLAN_tabel-davomat.md
 * §2). Business rules (generation from `attendance_records`, edit-window
 * gating) live in `App\Services\TabelService` — this model is
 * intentionally thin.
 */
class TabelEntry extends Model
{
    use HasUuids;

    protected $table = 'tabel_entries';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'tabel_uuid',
        'employee_uuid',
        'work_date',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
        ];
    }
}
