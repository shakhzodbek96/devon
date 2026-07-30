<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Monthly timesheet, unit × period (PLAN_tabel-davomat.md §1, §2). Mirrors
 * the frontend `Tabel` (planned). Business rules (the full approval chain,
 * per-station authorization, registry dispatch) live in
 * `App\Services\TabelService` — this model is intentionally thin. ERI
 * signing (head-decide approve path) reuses the shared `signatures` table
 * with `resource_type='tabel'`.
 */
class Tabel extends Model
{
    use HasUuids;

    protected $table = 'tabels';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'unit_uuid',
        'period',
        'status',
        'created_by',
        'head_note',
        'hr_note',
        'submitted_at',
        'head_signed_at',
        'hr_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'head_signed_at' => 'datetime',
            'hr_accepted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TabelEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TabelEntry::class, 'tabel_uuid', 'uuid')
            ->orderBy('employee_uuid')
            ->orderBy('work_date');
    }
}
