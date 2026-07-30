<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permanent roster row of a `CollegialBody` (PLAN_kollegial-organ.md §3) —
 * a plain pivot, no own uuid (auto-increment `id` is the primary key).
 * Business rules live in `App\Services\CollegialBodyService`.
 */
class CollegialBodyMember extends Model
{
    protected $table = 'collegial_body_members';

    protected $fillable = [
        'collegial_body_uuid',
        'employee_uuid',
    ];
}
