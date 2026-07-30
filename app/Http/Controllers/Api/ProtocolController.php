<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtocolChairmanDecideRequest;
use App\Http\Requests\ProtocolMemberDeclineRequest;
use App\Http\Requests\ProtocolMemberSignRequest;
use App\Http\Requests\ProtocolReviewerDecideRequest;
use App\Http\Requests\StoreProtocolRequest;
use App\Http\Requests\UpdateProtocolRequest;
use App\Http\Resources\ProtocolResource;
use App\Models\Protocol;
use App\Services\ProtocolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bayonnoma (protocol) approval chain (PLAN_kollegial-organ.md §1, §3,
 * §4). Every mutation acts as the AUTHENTICATED user's own employee —
 * `actingEmployeeUuid()` below, never a client-supplied uuid. Per-station
 * authorization (secretary/reviewer/participant/chairman) lives in
 * `ProtocolService`, not here — no `can:` route middleware on any of these
 * endpoints (mirrors `TabelController`'s convention).
 */
class ProtocolController extends Controller
{
    public function __construct(private readonly ProtocolService $protocols)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /protocols — filters: bodyUuid, status, box=mine.
     */
    public function index(Request $request): JsonResponse
    {
        $protocols = $this->protocols->list([
            'bodyUuid' => $request->query('bodyUuid'),
            'status' => $request->query('status'),
            'box' => $request->query('box'),
            'actingUuid' => $this->actingEmployeeUuid($request),
        ]);

        return response()->json(ProtocolResource::collection($protocols));
    }

    /**
     * GET /protocols/{uuid} — with reviewers/participants.
     */
    public function show(string $uuid): JsonResponse
    {
        $protocol = Protocol::where('uuid', $uuid)->with(['reviewers', 'participants'])->firstOrFail();

        return response()->json(new ProtocolResource($protocol));
    }

    /**
     * POST /collegial-bodies/{bodyUuid}/protocols.
     */
    public function store(StoreProtocolRequest $request, string $bodyUuid): JsonResponse
    {
        $protocol = $this->protocols->create($bodyUuid, $request->validated(), $request->user());

        return response()->json(new ProtocolResource($protocol), 201);
    }

    /**
     * PATCH /protocols/{uuid}.
     */
    public function update(UpdateProtocolRequest $request, string $uuid): JsonResponse
    {
        $protocol = $this->protocols->update($uuid, $request->validated(), $request->user());

        return response()->json(new ProtocolResource($protocol));
    }

    /**
     * POST /protocols/{uuid}/submit.
     */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $protocol = $this->protocols->submitForReview($uuid, $request->user());

        return response()->json(new ProtocolResource($protocol));
    }

    /**
     * POST /protocols/{uuid}/reviewer-decide.
     */
    public function reviewerDecide(ProtocolReviewerDecideRequest $request, string $uuid): JsonResponse
    {
        $validated = $request->validated();
        $protocol = $this->protocols->reviewerDecide(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
            (bool) $validated['approve'],
            $validated['note'] ?? null,
        );

        return response()->json(new ProtocolResource($protocol));
    }

    /**
     * POST /protocols/{uuid}/member-sign.
     */
    public function memberSign(ProtocolMemberSignRequest $request, string $uuid): JsonResponse
    {
        $validated = $request->validated();
        $protocol = $this->protocols->memberSign(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
            $validated['certificateUuid'],
            $validated['signatureHex'] ?? null,
        );

        return response()->json(new ProtocolResource($protocol));
    }

    /**
     * POST /protocols/{uuid}/member-decline.
     */
    public function memberDecline(ProtocolMemberDeclineRequest $request, string $uuid): JsonResponse
    {
        $protocol = $this->protocols->memberDecline(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
            $request->validated('note'),
        );

        return response()->json(new ProtocolResource($protocol));
    }

    /**
     * POST /protocols/{uuid}/chairman-decide.
     */
    public function chairmanDecide(ProtocolChairmanDecideRequest $request, string $uuid): JsonResponse
    {
        $validated = $request->validated();
        $protocol = $this->protocols->chairmanDecide(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
            (bool) $validated['approve'],
            $validated['certificateUuid'] ?? null,
            $validated['signatureHex'] ?? null,
            $validated['note'] ?? null,
        );

        return response()->json(new ProtocolResource($protocol));
    }
}
