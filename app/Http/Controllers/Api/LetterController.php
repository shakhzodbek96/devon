<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignLetterRequest;
use App\Http\Requests\DispatchLetterRequest;
use App\Http\Requests\ExecuteLetterRequest;
use App\Http\Requests\RegisterLetterRequest;
use App\Http\Requests\RouteLetterRequest;
use App\Http\Requests\SignLetterRequest;
use App\Http\Resources\LetterResource;
use App\Http\Resources\SignatureResource;
use App\Models\Employee;
use App\Models\Letter;
use App\Models\Signature;
use App\Models\Unit;
use App\Services\LetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Incoming/outgoing correspondence (PLAN_PHASE_G.md §3, BPMN 3.3 / BP-3).
 * Every mutation acts as the AUTHENTICATED user's own employee —
 * `actingEmployeeUuid()` below, never a client-supplied uuid (same
 * privilege-escalation guard as `DocumentController`). Per-station
 * authorization (Devonxona/Rahbar/unit-head/executor checks) lives in
 * `LetterService`, not here.
 */
class LetterController extends Controller
{
    public function __construct(private readonly LetterService $letters)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /letters — filters: direction, status, search, overdueOnly.
     * Matches the mock's `listLetters`.
     */
    public function index(Request $request): JsonResponse
    {
        $letters = $this->letters->list([
            'direction' => $request->query('direction'),
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'overdueOnly' => $request->boolean('overdueOnly'),
        ]);

        return response()->json(LetterResource::collection($letters));
    }

    /**
     * GET /letters/{uuid} — letter + signatures + linked incoming/outgoing +
     * routed unit/assignee/registrant names. Matches the mock's `getLetter`.
     */
    public function show(string $uuid): JsonResponse
    {
        $letter = Letter::where('uuid', $uuid)->first();
        if (! $letter) {
            return response()->json(['message' => 'Letter not found.'], 404);
        }

        $signatures = Signature::where('resource_type', 'letter')
            ->where('resource_uuid', $uuid)
            ->orderByDesc('signed_at')
            ->get();

        $linkedOutgoing = $letter->direction === 'INCOMING'
            ? Letter::where('linked_incoming_uuid', $uuid)->first()
            : null;
        $linkedIncoming = $letter->linked_incoming_uuid
            ? Letter::where('uuid', $letter->linked_incoming_uuid)->first()
            : null;
        $routedToUnitName = $letter->routed_to_unit_uuid
            ? Unit::where('uuid', $letter->routed_to_unit_uuid)->value('name_uz')
            : null;
        $assignedEmployeeName = $letter->assigned_employee_uuid
            ? Employee::where('uuid', $letter->assigned_employee_uuid)->value('full_name_generated')
            : null;
        $registeredByName = Employee::where('uuid', $letter->registered_by_uuid)->value('full_name_generated');

        return response()->json([
            'letter' => new LetterResource($letter),
            'signatures' => SignatureResource::collection($signatures),
            'linkedOutgoing' => $linkedOutgoing ? new LetterResource($linkedOutgoing) : null,
            'linkedIncoming' => $linkedIncoming ? new LetterResource($linkedIncoming) : null,
            'routedToUnitName' => $routedToUnitName,
            'assignedEmployeeName' => $assignedEmployeeName,
            'registeredByName' => $registeredByName,
        ]);
    }

    /**
     * POST /letters — register an INCOMING letter. Matches the mock's
     * `registerIncomingLetter`.
     */
    public function store(RegisterLetterRequest $request): JsonResponse
    {
        $letter = $this->letters->register(
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new LetterResource($letter), 201);
    }

    /**
     * POST /letters/{uuid}/route — REGISTERED -> ROUTED. Matches the
     * mock's `routeLetter`.
     */
    public function route(RouteLetterRequest $request, string $uuid): JsonResponse
    {
        $letter = $this->letters->route(
            $uuid,
            $request->validated('unitUuid'),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new LetterResource($letter));
    }

    /**
     * POST /letters/{uuid}/assign — ROUTED -> ASSIGNED. Matches the mock's
     * `assignLetterExecutor`.
     */
    public function assign(AssignLetterRequest $request, string $uuid): JsonResponse
    {
        $letter = $this->letters->assign(
            $uuid,
            $request->validated('employeeUuid'),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new LetterResource($letter));
    }

    /**
     * POST /letters/{uuid}/start — ASSIGNED -> IN_PROGRESS. Matches the
     * mock's `startLetterExecution`.
     */
    public function start(Request $request, string $uuid): JsonResponse
    {
        $letter = $this->letters->start($uuid, $this->actingEmployeeUuid($request), $request->user());

        return response()->json(new LetterResource($letter));
    }

    /**
     * POST /letters/{uuid}/execute — ASSIGNED/IN_PROGRESS -> EXECUTED.
     * Matches the mock's `submitLetterExecution`.
     */
    public function execute(ExecuteLetterRequest $request, string $uuid): JsonResponse
    {
        $letter = $this->letters->submit(
            $uuid,
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new LetterResource($letter));
    }

    /**
     * POST /letters/{uuid}/accept — EXECUTED -> CLOSED_NO_RESPONSE /
     * ON_SIGNATURE / RESPONDED. Matches the mock's `acceptLetterExecution`.
     */
    public function accept(Request $request, string $uuid): JsonResponse
    {
        $letter = $this->letters->accept($uuid, $this->actingEmployeeUuid($request), $request->user());

        return response()->json(new LetterResource($letter));
    }

    /**
     * POST /letters/{uuid}/sign — ON_SIGNATURE -> RESPONDED (shared
     * `signatures` table, PLAN_PHASE_F2.md §1). Matches the mock's
     * `signLetter`.
     */
    public function sign(SignLetterRequest $request, string $uuid): JsonResponse
    {
        $letter = $this->letters->sign(
            $uuid,
            $request->validated('certificateUuid'),
            $request->validated('signatureHex'),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new LetterResource($letter));
    }

    /**
     * POST /letters/{uuid}/dispatch — RESPONDED -> DISPATCHED, creates the
     * OUTGOING reply and closes the incoming letter. Matches the mock's
     * `dispatchLetter`.
     */
    public function dispatch(DispatchLetterRequest $request, string $uuid): JsonResponse
    {
        $result = $this->letters->dispatch(
            $uuid,
            $request->validated('channel'),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json([
            'incoming' => new LetterResource($result['incoming']),
            'outgoing' => new LetterResource($result['outgoing']),
        ]);
    }
}
