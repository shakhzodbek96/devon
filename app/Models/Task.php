<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Task delegation / Kanban master record (PLAN_PHASE_H.md §1, BP-2).
 * Mirrors the frontend `TaskEntity` (src/types/domain.ts). Business rules
 * (the full BP-2 lifecycle, subtree authorization, auto-numbering, round
 * increment) live in `App\Services\TaskService` — this model is
 * intentionally thin.
 *
 * No factory — same convention as `Document` / `Letter` (rows are built
 * through the service/API in tests, not `Task::factory()`).
 */
class Task extends Model
{
    use HasUuids;

    protected $table = 'tasks';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'number',
        'title',
        'description',
        'priority',
        'status',
        'assigner_uuid',
        'assignee_uuid',
        'deadline',
        'attached_document_uuid',
        'attached_file',
        'deliverable',
        'review_note',
        'round',
        'late_submission',
        'started_at',
        'submitted_at',
        'accepted_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date:Y-m-d',
            'attached_file' => 'array',
            'deliverable' => 'array',
            'round' => 'integer',
            'late_submission' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_uuid', 'uuid')->orderBy('created_at');
    }
}
