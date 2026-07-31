<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use App\Services\PositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PositionController extends Controller
{
    public function __construct(private readonly PositionService $positions)
    {
    }

    /**
     * GET /positions — the position catalogue (seeded defaults + any
     * user-created entries), readable by every authenticated role.
     */
    public function index(): JsonResponse
    {
        return response()->json(PositionResource::collection(Position::orderBy('id')->get()));
    }

    /**
     * POST /positions — gated by `positions.manage`.
     */
    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = $this->positions->create($request->validated(), $request->user());

        return response()->json(new PositionResource($position), 201);
    }

    /**
     * PATCH /positions/{id} — gated by `positions.manage`.
     */
    public function update(UpdatePositionRequest $request, string $id): JsonResponse
    {
        $position = $this->positions->update($id, $request->validated(), $request->user());

        return response()->json(new PositionResource($position));
    }

    /**
     * DELETE /positions/{id} — hard delete, gated by `positions.manage`.
     * Refused (422 `in-use`) when an employee/assignment still references it.
     */
    public function destroy(Request $request, string $id): Response
    {
        $this->positions->delete($id, $request->user());

        return response()->noContent();
    }
}
