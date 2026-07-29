<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of a document's sequential approval chain (PLAN_PHASE_F1.md §1,
 * BP-4). Mirrors the frontend `ApprovalStep` (src/types/domain.ts).
 * `order_no` is the DB column (avoids the reserved word `order`) — exposed
 * to the frontend as `order` only at the `ApprovalStepResource` boundary.
 */
class ApprovalStep extends Model
{
    /** @use HasFactory<\Database\Factories\ApprovalStepFactory> */
    use HasFactory, HasUuids;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'document_uuid',
        'round',
        'order_no',
        'employee_uuid',
        'decision',
        'comment',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'order_no' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
