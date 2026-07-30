<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reviewer decision row on a `Protocol` (PLAN_kollegial-organ.md §3) —
 * `approval_steps`-shaped: PENDING/APPROVED/REJECTED. Business rules live
 * in `App\Services\ProtocolService`.
 */
class ProtocolReviewer extends Model
{
    use HasUuids;

    protected $table = 'protocol_reviewers';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'protocol_uuid',
        'employee_uuid',
        'decision',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }
}
