<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConferenceRoomRequest;
use App\Http\Requests\UpdateConferenceRoomRequest;
use App\Http\Resources\ConferenceRoomResource;
use App\Models\ConferenceRoom;
use App\Services\ConferenceRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Conference-room registry (PLAN_conference-room.md §3). `index` is open to
 * every authenticated user (booking flow needs to list rooms); mutations
 * are gated by `can:conference-rooms.manage`.
 */
class ConferenceRoomController extends Controller
{
    public function __construct(private readonly ConferenceRoomService $rooms)
    {
    }

    /**
     * GET /conference-rooms.
     */
    public function index(): JsonResponse
    {
        return response()->json(ConferenceRoomResource::collection(ConferenceRoom::orderBy('name')->get()));
    }

    /**
     * POST /conference-rooms — gated by `conference-rooms.manage`.
     */
    public function store(StoreConferenceRoomRequest $request): JsonResponse
    {
        $room = $this->rooms->create($request->validated(), $request->user());

        return response()->json(new ConferenceRoomResource($room), 201);
    }

    /**
     * PATCH /conference-rooms/{uuid} — gated by `conference-rooms.manage`.
     */
    public function update(UpdateConferenceRoomRequest $request, string $uuid): JsonResponse
    {
        $room = $this->rooms->update($uuid, $request->validated(), $request->user());

        return response()->json(new ConferenceRoomResource($room));
    }

    /**
     * DELETE /conference-rooms/{uuid} — soft archive. Gated by
     * `conference-rooms.manage`.
     */
    public function destroy(Request $request, string $uuid): Response
    {
        $this->rooms->archive($uuid, $request->user());

        return response()->noContent();
    }
}
