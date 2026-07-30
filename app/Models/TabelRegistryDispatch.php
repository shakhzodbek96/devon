<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Organization-level "the whole registry was sent to Accounting" marker
 * (PLAN_tabel-davomat.md §1 node 11, §2) — one row per period. `period`
 * (YYYY-MM) IS the primary key, so this model is non-incrementing with a
 * string key. Written once by `App\Services\TabelService::maybeDispatchRegistry`
 * — this model is intentionally thin.
 */
class TabelRegistryDispatch extends Model
{
    protected $table = 'tabel_registry_dispatches';

    protected $primaryKey = 'period';

    public $incrementing = false;

    protected $keyType = 'string';

    /** No `updated_at` column — `dispatched_at` is the single timestamp. */
    const UPDATED_AT = null;

    const CREATED_AT = null;

    protected $fillable = [
        'period',
        'dispatched_at',
        'dispatched_by',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
        ];
    }
}
