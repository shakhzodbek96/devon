<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOfficeNetworkRequest;
use App\Http\Requests\UpdateOfficeNetworkRequest;
use App\Http\Resources\OfficeNetworkResource;
use App\Models\OfficeNetwork;
use App\Services\OfficeNetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Wi-Fi perimeter registry (PLAN_tabel-davomat.md §3). All routes gated by
 * `can:attendance.manage-config`.
 */
class OfficeNetworkController extends Controller
{
    public function __construct(private readonly OfficeNetworkService $networks)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(OfficeNetworkResource::collection(OfficeNetwork::orderBy('name')->get()));
    }

    public function store(StoreOfficeNetworkRequest $request): JsonResponse
    {
        $network = $this->networks->create($request->validated(), $request->user());

        return response()->json(new OfficeNetworkResource($network), 201);
    }

    public function update(UpdateOfficeNetworkRequest $request, string $uuid): JsonResponse
    {
        $network = $this->networks->update($uuid, $request->validated(), $request->user());

        return response()->json(new OfficeNetworkResource($network));
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $this->networks->archive($uuid, $request->user());

        return response()->noContent();
    }
}
