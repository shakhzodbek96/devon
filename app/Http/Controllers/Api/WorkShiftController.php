<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkShiftRequest;
use App\Http\Requests\UpdateWorkShiftRequest;
use App\Http\Resources\WorkShiftResource;
use App\Models\WorkShift;
use App\Services\WorkShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Standard work-shift registry (PLAN_tabel-davomat.md §3). All routes
 * gated by `can:attendance.manage-config`.
 */
class WorkShiftController extends Controller
{
    public function __construct(private readonly WorkShiftService $shifts)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(WorkShiftResource::collection(WorkShift::orderBy('unit_uuid')->get()));
    }

    public function store(StoreWorkShiftRequest $request): JsonResponse
    {
        $shift = $this->shifts->create($request->validated(), $request->user());

        return response()->json(new WorkShiftResource($shift), 201);
    }

    public function update(UpdateWorkShiftRequest $request, string $uuid): JsonResponse
    {
        $shift = $this->shifts->update($uuid, $request->validated(), $request->user());

        return response()->json(new WorkShiftResource($shift));
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $this->shifts->delete($uuid, $request->user());

        return response()->noContent();
    }
}
