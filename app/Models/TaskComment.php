<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a task's chronological clarification / feedback thread
 * (PLAN_PHASE_H.md §1, BP-2). Mirrors the frontend `TaskComment`
 * (src/types/domain.ts). Append-only — no `updated_at` column, matches the
 * `audit_logs` / `notifications` convention.
 */
class TaskComment extends Model
{
    use HasUuids;

    protected $table = 'task_comments';

    public const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'task_uuid',
        'author_uuid',
        'kind',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
