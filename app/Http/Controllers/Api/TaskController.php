<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTaskRequest;
use App\Http\Requests\ReviewTaskRequest;
use App\Http\Requests\SubmitTaskRequest;
use App\Http\Requests\TaskClarificationRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\Unit;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Task delegation / Kanban (PLAN_PHASE_H.md §3, BP-2 — the last mock
 * module). Every mutation acts as the AUTHENTICATED user's own employee —
 * `actingEmployeeUuid()` below, never a client-supplied uuid (same
 * privilege-escalation guard as `DocumentController`/`LetterController`).
 * Per-task authorization (assigner/assignee, subtree scope) lives in
 * `TaskService`, not here.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /tasks — filters: box (assigned-by-me|assigned-to-me, required),
     * status, priority, search, overdueOnly. Matches the mock's `listTasks`.
     */
    public function index(Request $request): JsonResponse
    {
        $tasks = $this->tasks->list([
            'box' => $request->query('box'),
            'actingUuid' => $this->actingEmployeeUuid($request),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'search' => $request->query('search'),
            'overdueOnly' => $request->boolean('overdueOnly'),
        ])->load('comments');

        return response()->json(TaskResource::collection($tasks));
    }

    /**
     * GET /tasks/{uuid} — flattened task + comments + resolved names.
     * Matches the mock's `getTask` (a `TaskDetail` IS a `TaskEntity` with
     * extra fields — flattened, not nested).
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $task = Task::where('uuid', $uuid)->with('comments')->first();
        if (! $task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $primary = Assignment::where('employee_uuid', $task->assignee_uuid)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->first();
        $unit = $primary ? Unit::where('uuid', $primary->unit_uuid)->first() : null;
        $position = $primary ? Position::where('id', $primary->position_id)->first() : null;

        $attached = $task->attached_document_uuid
            ? Document::where('uuid', $task->attached_document_uuid)->first()
            : null;
        $deliverableDocUuid = $task->deliverable['documentUuid'] ?? null;
        $deliverableDoc = $deliverableDocUuid
            ? Document::where('uuid', $deliverableDocUuid)->first()
            : null;

        $commentAuthors = [];
        foreach ($task->comments as $comment) {
            if (! isset($commentAuthors[$comment->author_uuid])) {
                $commentAuthors[$comment->author_uuid] = Employee::where('uuid', $comment->author_uuid)
                    ->value('full_name_generated') ?? $comment->author_uuid;
            }
        }

        $detail = (new TaskResource($task))->resolve($request) + [
            'assignerName' => Employee::where('uuid', $task->assigner_uuid)->value('full_name_generated'),
            'assigneeName' => Employee::where('uuid', $task->assignee_uuid)->value('full_name_generated'),
            'assigneePositionUz' => $position?->name_uz,
            'assigneeUnitNameUz' => $unit?->name_uz,
            'attachedDocumentNumber' => $attached?->number,
            'attachedDocumentTitle' => $attached?->title,
            'deliverableDocumentNumber' => $deliverableDoc?->number,
            'commentAuthors' => (object) $commentAuthors,
        ];

        return response()->json($detail);
    }

    /**
     * GET /me/task-stats. Matches the mock's `getTaskStats`.
     */
    public function stats(Request $request): JsonResponse
    {
        return response()->json($this->tasks->stats($this->actingEmployeeUuid($request)));
    }

    /**
     * POST /tasks. Matches the mock's `createTask`.
     */
    public function store(CreateTaskRequest $request): JsonResponse
    {
        $task = $this->tasks->create(
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        )->load('comments');

        return response()->json(new TaskResource($task), 201);
    }

    /**
     * PATCH /tasks/{uuid}. Matches the mock's `updateTask`.
     */
    public function update(UpdateTaskRequest $request, string $uuid): JsonResponse
    {
        $task = $this->tasks->update(
            $uuid,
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        )->load('comments');

        return response()->json(new TaskResource($task));
    }

    /**
     * POST /tasks/{uuid}/start — NEW -> IN_PROGRESS. Matches the mock's `startTask`.
     */
    public function start(Request $request, string $uuid): JsonResponse
    {
        $task = $this->tasks->start($uuid, $this->actingEmployeeUuid($request), $request->user())
            ->load('comments');

        return response()->json(new TaskResource($task));
    }

    /**
     * POST /tasks/{uuid}/clarification-request. Matches the mock's `requestClarification`.
     */
    public function requestClarification(TaskClarificationRequest $request, string $uuid): JsonResponse
    {
        $task = $this->tasks->requestClarification(
            $uuid,
            (string) $request->validated('body'),
            $this->actingEmployeeUuid($request),
            $request->user(),
        )->load('comments');

        return response()->json(new TaskResource($task));
    }

    /**
     * POST /tasks/{uuid}/clarification-answer. Matches the mock's `answerClarification`.
     */
    public function answerClarification(TaskClarificationRequest $request, string $uuid): JsonResponse
    {
        $task = $this->tasks->answerClarification(
            $uuid,
            (string) $request->validated('body'),
            $this->actingEmployeeUuid($request),
            $request->user(),
        )->load('comments');

        return response()->json(new TaskResource($task));
    }

    /**
     * POST /tasks/{uuid}/submit — IN_PROGRESS/UNDER_REVIEW -> UNDER_REVIEW.
     * Matches the mock's `submitDeliverable`.
     */
    public function submit(SubmitTaskRequest $request, string $uuid): JsonResponse
    {
        $task = $this->tasks->submit(
            $uuid,
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        )->load('comments');

        return response()->json(new TaskResource($task));
    }

    /**
     * POST /tasks/{uuid}/review — UNDER_REVIEW -> DONE|IN_PROGRESS|REJECTED.
     * Matches the mock's `reviewTask`.
     */
    public function review(ReviewTaskRequest $request, string $uuid): JsonResponse
    {
        $task = $this->tasks->review(
            $uuid,
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        )->load('comments');

        return response()->json(new TaskResource($task));
    }
}
