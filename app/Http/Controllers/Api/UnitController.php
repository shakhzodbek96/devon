<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\UnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $units)
    {
    }

    /**
     * GET /units — flat list (the frontend builds the tree client-side from
     * `parentUuid` + `path`). Gated by `units.view` (all 7 roles).
     */
    public function index(): JsonResponse
    {
        return response()->json(UnitResource::collection(Unit::orderBy('path')->get()));
    }

    /**
     * GET /units/{uuid}.
     */
    public function show(string $uuid): JsonResponse
    {
        $unit = Unit::where('uuid', $uuid)->first();
        if (! $unit) {
            return response()->json(['message' => 'Unit not found.'], 404);
        }

        return response()->json(new UnitResource($unit));
    }

    /**
     * POST /units — gated by `units.create`.
     */
    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = $this->units->create($request->validated(), $request->user());

        return response()->json(new UnitResource($unit), 201);
    }

    /**
     * PATCH /units/{uuid} — gated by `units.update`. A `parentUuid` change
     * is treated as a move: descendants' level/path recompute recursively.
     */
    public function update(UpdateUnitRequest $request, string $uuid): JsonResponse
    {
        $unit = $this->units->update($uuid, $request->validated(), $request->user());

        return response()->json(new UnitResource($unit));
    }

    /**
     * DELETE /units/{uuid} — soft archive (`status = ARCHIVED`). Gated by
     * `units.update`.
     */
    public function destroy(Request $request, string $uuid): Response
    {
        $this->units->archive($uuid, $request->user());

        return response()->noContent();
    }
}
