<?php

namespace App\Services;

use App\Exceptions\TaskValidationException;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Task delegation / Kanban workflow engine (PLAN_PHASE_H.md §2, BP-2),
 * ported line-for-line from the frontend mock's `listTasks` / `getTask` /
 * `getTaskStats` / `createTask` / `updateTask` / `startTask` /
 * `requestClarification` / `answerClarification` / `submitDeliverable` /
 * `reviewTask` / `isTaskOverdue` / `assigneeInAssignerScope`
 * (frontend/dashboard/src/lib/mock-backend/index.ts, ~2568–2967). Every
 * mutation validates against the *acting employee* uuid — the authenticated
 * user's own employee, resolved by `TaskController` and passed in here —
 * never a client-supplied "act as anyone".
 *
 * BP-2 canon: NEW -> IN_PROGRESS -> UNDER_REVIEW -> DONE | REJECTED.
 * Clarification does NOT change status. `round` increments only on
 * return -> resubmit.
 */
class TaskService
{
    private const TERMINAL_STATUSES = ['DONE', 'REJECTED'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Year hardcoded per master §17 (documents/letters convention) — the
     * demo never crosses a year boundary.
     */
    public function nextTaskNumber(): string
    {
        $max = 0;
        foreach (Task::where('number', 'like', 'TOP-2026/%')->pluck('number') as $number) {
            if (preg_match('/^TOP-2026\/(\d{4})$/', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'TOP-2026/'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Overdue = deadline strictly before today AND the task is not terminal. */
    public function isOverdue(Task $task): bool
    {
        if (in_array($task->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return $task->deadline->format('Y-m-d') < now()->format('Y-m-d');
    }

    /**
     * True when `assigneeUuid`'s open PRIMARY assignment is within a
     * subtree headed by `assignerUuid` — mirrors the mock's
     * `assigneeInAssignerScope` verbatim (assignments table only, same as
     * the mock; does NOT fall back to `Employee.primary_unit_uuid`).
     */
    private function assigneeInScope(string $assignerUuid, string $assigneeUuid): bool
    {
        $headedUnits = Unit::where('head_employee_uuid', $assignerUuid)->get();
        if ($headedUnits->isEmpty()) {
            return false;
        }

        $primary = Assignment::where('employee_uuid', $assigneeUuid)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->first();
        if (! $primary) {
            return false;
        }

        $assigneeUnit = Unit::where('uuid', $primary->unit_uuid)->first();
        if (! $assigneeUnit) {
            return false;
        }

        return $headedUnits->contains(fn (Unit $h) => str_starts_with($assigneeUnit->path, $h->path));
    }

    /**
     * `listTasks` — `box` selects assigner vs assignee scope; filters:
     * status, priority, search (number/title), overdueOnly.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Task>
     */
    public function list(array $filters): Collection
    {
        $query = Task::query();

        if (($filters['box'] ?? null) === 'assigned-by-me') {
            $query->where('assigner_uuid', $filters['actingUuid']);
        } else {
            $query->where('assignee_uuid', $filters['actingUuid']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['search'])) {
            $q = mb_strtolower((string) $filters['search']);
            $query->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(number) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(title) LIKE ?', ["%{$q}%"]);
            });
        }

        $tasks = $query->orderByDesc('created_at')->get();

        if (! empty($filters['overdueOnly'])) {
            $tasks = $tasks->filter(fn (Task $t) => $this->isOverdue($t))->values();
        }

        return $tasks;
    }

    /**
     * `getTaskStats` — over tasks the actor ASSIGNED (mirrors the mock:
     * `readTasks().filter(t => t.assignerUuid === actingUuid)`).
     *
     * @return array{byStatus: array<string, int>, overdueCount: int, loadPerAssignee: array<int, array{assigneeUuid: string, assigneeName: string, openCount: int}>}
     */
    public function stats(string $actingUuid): array
    {
        $mine = Task::where('assigner_uuid', $actingUuid)->get();
        $byStatus = ['NEW' => 0, 'IN_PROGRESS' => 0, 'UNDER_REVIEW' => 0, 'DONE' => 0, 'REJECTED' => 0];
        $overdueCount = 0;
        $load = [];
        foreach ($mine as $t) {
            $byStatus[$t->status]++;
            if ($this->isOverdue($t)) {
                $overdueCount++;
            }
            if (! in_array($t->status, self::TERMINAL_STATUSES, true)) {
                $load[$t->assignee_uuid] = ($load[$t->assignee_uuid] ?? 0) + 1;
            }
        }

        $loadPerAssignee = collect($load)
            ->map(fn ($openCount, $assigneeUuid) => [
                'assigneeUuid' => $assigneeUuid,
                'assigneeName' => $this->employeeNameFor($assigneeUuid),
                'openCount' => $openCount,
            ])
            ->sortByDesc('openCount')
            ->values()
            ->all();

        return ['byStatus' => $byStatus, 'overdueCount' => $overdueCount, 'loadPerAssignee' => $loadPerAssignee];
    }

    /**
     * `createTask` — assignee must be within the assigner's org subtree
     * (`out-of-scope`); no self-assign (`self-assign`). Auto `TOP-2026/NNNN`.
     *
     * @param  array<string, mixed>  $input  Shape: title, description,
     *   priority, assigneeUuid, deadline, attachedDocumentUuid?,
     *   attachedFile? (already carries `uploadedAt` — the frontend stamps
     *   it client-side, mirrors the mock's passthrough).
     */
    public function create(array $input, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($input, $actorEmployeeUuid, $actorUser) {
            if ($input['assigneeUuid'] === $actorEmployeeUuid) {
                throw new TaskValidationException('self-assign');
            }
            if (! $this->assigneeInScope($actorEmployeeUuid, $input['assigneeUuid'])) {
                throw new TaskValidationException('out-of-scope');
            }

            $task = Task::create([
                'uuid' => (string) Str::uuid(),
                'number' => $this->nextTaskNumber(),
                'title' => $input['title'],
                'description' => $input['description'],
                'priority' => $input['priority'],
                'status' => 'NEW',
                'assigner_uuid' => $actorEmployeeUuid,
                'assignee_uuid' => $input['assigneeUuid'],
                'deadline' => $input['deadline'],
                'attached_document_uuid' => $input['attachedDocumentUuid'] ?? null,
                'attached_file' => $input['attachedFile'] ?? null,
                'round' => 1,
            ]);

            AuditLogger::log(
                $actorUser, 'TASK_CREATED', 'task', $task->uuid, $this->label($task), null,
                [
                    'title' => $task->title,
                    'priority' => $task->priority,
                    'deadline' => $task->deadline->format('Y-m-d'),
                    'assigneeUuid' => $task->assignee_uuid,
                ],
            );
            $this->notify([$task->assignee_uuid], 'TASK_ASSIGNED', $task, [
                'taskNumber' => $task->number,
                'actorName' => $actorUser->full_name,
            ]);

            return $task->fresh();
        });
    }

    /**
     * `updateTask` — assigner-only (`not-assigner`), only while non-terminal
     * (`wrong-status`). Only present keys are applied.
     *
     * @param  array<string, mixed>  $input  Shape: title?, description?,
     *   priority?, deadline?.
     */
    public function update(string $uuid, array $input, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($uuid, $input, $actorEmployeeUuid, $actorUser) {
            $task = Task::where('uuid', $uuid)->firstOrFail();
            if ($task->assigner_uuid !== $actorEmployeeUuid) {
                throw new TaskValidationException('not-assigner');
            }
            if ($this->isTerminal($task)) {
                throw new TaskValidationException('wrong-status');
            }

            $changes = [];
            $deadlineChanged = array_key_exists('deadline', $input)
                && $input['deadline'] !== $task->deadline->format('Y-m-d');
            if (array_key_exists('title', $input) && $input['title'] !== $task->title) {
                $changes['title'] = ['from' => $task->title, 'to' => $input['title']];
            }
            if (array_key_exists('description', $input) && $input['description'] !== $task->description) {
                $changes['description'] = ['from' => $task->description, 'to' => $input['description']];
            }
            if (array_key_exists('priority', $input) && $input['priority'] !== $task->priority) {
                $changes['priority'] = ['from' => $task->priority, 'to' => $input['priority']];
            }
            if ($deadlineChanged) {
                $changes['deadline'] = ['from' => $task->deadline->format('Y-m-d'), 'to' => $input['deadline']];
            }

            $task->title = $input['title'] ?? $task->title;
            $task->description = $input['description'] ?? $task->description;
            $task->priority = $input['priority'] ?? $task->priority;
            $task->deadline = $input['deadline'] ?? $task->deadline;
            $task->save();

            AuditLogger::log(
                $actorUser, 'TASK_UPDATED', 'task', $uuid, $this->label($task), $changes,
                ['kind' => $deadlineChanged ? 'deadline' : 'scope'],
            );

            return $task->fresh();
        });
    }

    /** `startTask` — NEW -> IN_PROGRESS. Assignee-only. No notification (mirrors the mock). */
    public function start(string $uuid, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $task = Task::where('uuid', $uuid)->firstOrFail();
            if ($task->assignee_uuid !== $actorEmployeeUuid) {
                throw new TaskValidationException('not-assignee');
            }
            if ($task->status !== 'NEW') {
                throw new TaskValidationException('wrong-status');
            }

            $task->status = 'IN_PROGRESS';
            $task->started_at = now();
            $task->save();

            AuditLogger::log($actorUser, 'TASK_STARTED', 'task', $uuid, $this->label($task));

            return $task->fresh();
        });
    }

    /**
     * `requestClarification` — assignee-only, while non-terminal. Appends a
     * CLARIFICATION_REQUEST comment; status is untouched.
     */
    public function requestClarification(string $uuid, string $body, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($uuid, $body, $actorEmployeeUuid, $actorUser) {
            $task = Task::where('uuid', $uuid)->firstOrFail();
            if ($task->assignee_uuid !== $actorEmployeeUuid) {
                throw new TaskValidationException('not-assignee');
            }
            if ($this->isTerminal($task)) {
                throw new TaskValidationException('wrong-status');
            }
            if (trim($body) === '') {
                throw new TaskValidationException('reason-required');
            }

            $this->appendComment($task, 'CLARIFICATION_REQUEST', trim($body), $actorEmployeeUuid);
            $task->touch();

            AuditLogger::log($actorUser, 'TASK_CLARIFICATION_REQUESTED', 'task', $uuid, $this->label($task));
            $this->notify([$task->assigner_uuid], 'TASK_CLARIFICATION_REQUESTED', $task, [
                'taskNumber' => $task->number,
                'actorName' => $actorUser->full_name,
            ]);

            return $task->fresh();
        });
    }

    /**
     * `answerClarification` — assigner-only, while non-terminal. Appends a
     * CLARIFICATION_REPLY comment; status is untouched.
     */
    public function answerClarification(string $uuid, string $body, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($uuid, $body, $actorEmployeeUuid, $actorUser) {
            $task = Task::where('uuid', $uuid)->firstOrFail();
            if ($task->assigner_uuid !== $actorEmployeeUuid) {
                throw new TaskValidationException('not-assigner');
            }
            if ($this->isTerminal($task)) {
                throw new TaskValidationException('wrong-status');
            }
            if (trim($body) === '') {
                throw new TaskValidationException('reason-required');
            }

            $this->appendComment($task, 'CLARIFICATION_REPLY', trim($body), $actorEmployeeUuid);
            $task->touch();

            AuditLogger::log($actorUser, 'TASK_CLARIFICATION_ANSWERED', 'task', $uuid, $this->label($task));
            $this->notify([$task->assignee_uuid], 'TASK_CLARIFICATION_ANSWERED', $task, [
                'taskNumber' => $task->number,
                'actorName' => $actorUser->full_name,
            ]);

            return $task->fresh();
        });
    }

    /**
     * `submitDeliverable` — IN_PROGRESS/UNDER_REVIEW -> UNDER_REVIEW.
     * Assignee-only. Re-submission while already UNDER_REVIEW replaces the
     * deliverable. `late_submission` flips true (and stays true) once the
     * deadline has passed at submit time.
     *
     * @param  array<string, mixed>  $input  Shape: summary, file?, documentUuid?.
     */
    public function submit(string $uuid, array $input, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($uuid, $input, $actorEmployeeUuid, $actorUser) {
            $task = Task::where('uuid', $uuid)->firstOrFail();
            if ($task->assignee_uuid !== $actorEmployeeUuid) {
                throw new TaskValidationException('not-assignee');
            }
            if (! in_array($task->status, ['IN_PROGRESS', 'UNDER_REVIEW'], true)) {
                throw new TaskValidationException('wrong-status');
            }

            $now = now();
            $late = $now->format('Y-m-d') > $task->deadline->format('Y-m-d');
            $wasUnderReview = $task->status === 'UNDER_REVIEW';

            $task->status = 'UNDER_REVIEW';
            $task->deliverable = [
                'summary' => $input['summary'],
                'file' => $input['file'] ?? null,
                'documentUuid' => $input['documentUuid'] ?? null,
                'submittedAt' => $now->toIso8601String(),
            ];
            $task->submitted_at = $now;
            $task->late_submission = $late || (bool) $task->late_submission;
            $task->save();

            AuditLogger::log(
                $actorUser, 'TASK_SUBMITTED', 'task', $uuid, $this->label($task), null,
                ['late' => $late, 'replaced' => $wasUnderReview],
            );
            $this->notify([$task->assigner_uuid], 'TASK_SUBMITTED', $task, [
                'taskNumber' => $task->number,
                'actorName' => $actorUser->full_name,
            ]);

            return $task->fresh();
        });
    }

    /**
     * `reviewTask` — assigner-only, UNDER_REVIEW only. ACCEPT/ACCEPT_WITH_NOTE
     * -> DONE; RETURN -> IN_PROGRESS (+round, RETURN_FEEDBACK comment);
     * REJECT -> REJECTED (REJECT_REASON comment). RETURN/REJECT require
     * `text` (`reason-required`).
     *
     * @param  array<string, mixed>  $input  Shape: decision, text?.
     */
    public function review(string $uuid, array $input, string $actorEmployeeUuid, User $actorUser): Task
    {
        return DB::transaction(function () use ($uuid, $input, $actorEmployeeUuid, $actorUser) {
            $task = Task::where('uuid', $uuid)->firstOrFail();
            if ($task->assigner_uuid !== $actorEmployeeUuid) {
                throw new TaskValidationException('not-assigner');
            }
            if ($task->status !== 'UNDER_REVIEW') {
                throw new TaskValidationException('wrong-status');
            }
            $decision = $input['decision'];
            $text = isset($input['text']) ? trim((string) $input['text']) : null;
            if (in_array($decision, ['RETURN', 'REJECT'], true) && ($text === null || $text === '')) {
                throw new TaskValidationException('reason-required');
            }

            $now = now();
            if (in_array($decision, ['ACCEPT', 'ACCEPT_WITH_NOTE'], true)) {
                if ($decision === 'ACCEPT_WITH_NOTE' && $text) {
                    $this->appendComment($task, 'NOTE', $text, $actorEmployeeUuid);
                }
                $task->status = 'DONE';
                $task->accepted_at = $now;
                $task->review_note = $decision === 'ACCEPT_WITH_NOTE' ? $text : null;
                $action = 'TASK_ACCEPTED';
                $notifyType = 'TASK_ACCEPTED';
            } elseif ($decision === 'RETURN') {
                $this->appendComment($task, 'RETURN_FEEDBACK', $text, $actorEmployeeUuid);
                $task->status = 'IN_PROGRESS';
                $task->round = $task->round + 1;
                $action = 'TASK_RETURNED';
                $notifyType = 'TASK_RETURNED';
            } else {
                $this->appendComment($task, 'REJECT_REASON', $text, $actorEmployeeUuid);
                $task->status = 'REJECTED';
                $task->rejected_at = $now;
                $action = 'TASK_REJECTED';
                $notifyType = 'TASK_REJECTED';
            }
            $task->save();

            AuditLogger::log(
                $actorUser, $action, 'task', $uuid, $this->label($task), null,
                ['decision' => $decision, 'round' => $task->round],
            );
            $this->notify([$task->assignee_uuid], $notifyType, $task, [
                'taskNumber' => $task->number,
                'actorName' => $actorUser->full_name,
            ]);

            return $task->fresh();
        });
    }

    private function isTerminal(Task $task): bool
    {
        return in_array($task->status, self::TERMINAL_STATUSES, true);
    }

    private function appendComment(Task $task, string $kind, string $body, string $authorUuid): TaskComment
    {
        return TaskComment::create([
            'uuid' => (string) Str::uuid(),
            'task_uuid' => $task->uuid,
            'author_uuid' => $authorUuid,
            'kind' => $kind,
            'body' => $body,
        ]);
    }

    private function employeeNameFor(string $employeeUuid): string
    {
        return Employee::where('uuid', $employeeUuid)->value('full_name_generated') ?? $employeeUuid;
    }

    private function label(Task $task): string
    {
        return "{$task->number} · {$task->title}";
    }

    /**
     * @param  array<int, string|null>  $recipients
     * @param  array<string, string>  $params
     */
    private function notify(array $recipients, string $type, Task $task, array $params): void
    {
        $unique = array_unique(array_filter($recipients));
        foreach ($unique as $recipientEmployeeUuid) {
            $this->notifications->append(
                $recipientEmployeeUuid,
                $type,
                "dashboard:notifications.title.{$type}",
                $params,
                'task',
                $task->uuid,
            );
        }
    }
}
