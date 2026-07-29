<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee self-service profile-change request (PLAN_PHASE_C.md §2, TZ
 * §4.6). Mirrors the frontend `ProfileChangeRequest`
 * (src/types/domain.ts). No `updated_at` — matches the frontend type.
 */
class ProfileChangeRequest extends Model
{
    use HasUuids;

    protected $table = 'profile_change_requests';

    public const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'employee_uuid',
        'fields',
        'status',
        'rejection_reason',
        'reviewed_at',
        'reviewed_by_uuid',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
