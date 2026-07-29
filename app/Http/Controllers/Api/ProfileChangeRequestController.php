<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideProfileChangeRequestRequest;
use App\Http\Requests\SubmitProfileChangeRequestRequest;
use App\Http\Resources\ProfileChangeRequestResource;
use App\Models\ProfileChangeRequest;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileChangeRequestController extends Controller
{
    public function __construct(private readonly EmployeeService $employees)
    {
    }

    /**
     * POST /me/profile-change-request — any authenticated user with a
     * linked employee. `employeeUuid` is resolved from the acting user's
     * own `employee_uuid`, never trusted from the request body (a small,
     * intentional hardening over the mock). Matches the mock's
     * `submitProfileChangeRequest`.
     */
    public function store(SubmitProfileChangeRequestRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->employee_uuid) {
            return response()->json(['message' => 'The acting user has no linked employee.'], 422);
        }

        $result = $this->employees->submitProfileChangeRequest(
            $actor->employee_uuid,
            $request->validated('fields'),
            $actor,
        );

        return response()->json(new ProfileChangeRequestResource($result), 201);
    }

    /**
     * GET /profile-change-requests?status= — gated by `employees.update`.
     * Matches the mock's `listProfileRequests`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProfileChangeRequest::query();
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            ProfileChangeRequestResource::collection($query->orderByDesc('created_at')->get()),
        );
    }

    /**
     * POST /profile-change-requests/{uuid}/decide — gated by
     * `employees.update`. Matches the mock's `approveProfileRequest`.
     */
    public function decide(DecideProfileChangeRequestRequest $request, string $uuid): JsonResponse
    {
        $result = $this->employees->decideProfileChangeRequest(
            $uuid,
            $request->user(),
            $request->validated('decision'),
            $request->validated('rejectionReason'),
        );

        return response()->json(new ProfileChangeRequestResource($result));
    }
}
