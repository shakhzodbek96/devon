<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Seeded position catalogue (PLAN_PHASE_AB.md §1.2). Mirrors the frontend
 * `Position` (src/types/domain.ts). `id` is the string code used verbatim
 * by the frontend (e.g. 'POS-DIR') — the model's primary key.
 */
class Position extends Model
{
    protected $table = 'positions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name_uz',
        'allowed_unit_types',
    ];

    protected function casts(): array
    {
        return [
            'allowed_unit_types' => 'array',
        ];
    }
}
