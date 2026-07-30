<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddCollegialBodyMemberRequest;
use App\Http\Requests\StoreCollegialBodyRequest;
use App\Http\Requests\UpdateCollegialBodyRequest;
use App\Http\Resources\CollegialBodyResource;
use App\Services\CollegialBodyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Standing collegial-body registry (PLAN_kollegial-organ.md §2.2, §4).
 * `index` is open to every authenticated user (protocol-creation flow
 * needs to list bodies); every mutation (CRUD + member management) is
 * gated by `can:collegial.manage`.
 */
class CollegialBodyController extends Controller
{
    public function __construct(private readonly CollegialBodyService $bodies)
    {
    }

    /**
     * GET /collegial-bodies.
     */
    public function index(): JsonResponse
    {
        return response()->json(CollegialBodyResource::collection($this->bodies->list()));
    }

    /**
     * POST /collegial-bodies — gated by `collegial.manage`.
     */
    public function store(StoreCollegialBodyRequest $request): JsonResponse
    {
        $body = $this->bodies->create($request->validated(), $request->user());

        return response()->json(new CollegialBodyResource($body), 201);
    }

    /**
     * PATCH /collegial-bodies/{uuid} — gated by `collegial.manage`.
     */
    public function update(UpdateCollegialBodyRequest $request, string $uuid): JsonResponse
    {
        $body = $this->bodies->update($uuid, $request->validated(), $request->user());

        return response()->json(new CollegialBodyResource($body));
    }

    /**
     * DELETE /collegial-bodies/{uuid} — soft archive. Gated by
     * `collegial.manage`.
     */
    public function destroy(Request $request, string $uuid): Response
    {
        $this->bodies->archive($uuid, $request->user());

        return response()->noContent();
    }

    /**
     * POST /collegial-bodies/{uuid}/members — gated by `collegial.manage`.
     */
    public function addMember(AddCollegialBodyMemberRequest $request, string $uuid): JsonResponse
    {
        $body = $this->bodies->addMember($uuid, $request->validated('employeeUuid'), $request->user());

        return response()->json(new CollegialBodyResource($body));
    }

    /**
     * DELETE /collegial-bodies/{uuid}/members/{employeeUuid} — gated by
     * `collegial.manage`.
     */
    public function removeMember(Request $request, string $uuid, string $employeeUuid): JsonResponse
    {
        $body = $this->bodies->removeMember($uuid, $employeeUuid, $request->user());

        return response()->json(new CollegialBodyResource($body));
    }
}
