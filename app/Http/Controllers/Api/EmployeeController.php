<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\TerminateEmployeeRequest;
use App\Http\Requests\TransferEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\UserResource;
use App\Models\Assignment;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees)
    {
    }

    /**
     * GET /employees — filters: search, unitUuid, status, positionId. Gated
     * by `employees.view` (all 7 roles). Matches the mock's `listEmployees`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query();

        if ($search = $request->query('search')) {
            $q = mb_strtolower($search);
            $query->where(function ($w) use ($q, $search) {
                $w->whereRaw('LOWER(full_name_generated) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(corporate_email) LIKE ?', ["%{$q}%"])
                    ->orWhere('pinfl', 'like', "%{$search}%");
            });
        }
        if ($unitUuid = $request->query('unitUuid')) {
            $query->where('primary_unit_uuid', $unitUuid);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($positionId = $request->query('positionId')) {
            $query->where('position_id', $positionId);
        }

        return response()->json(EmployeeResource::collection($query->orderBy('full_name_generated')->get()));
    }

    /**
     * GET /employees/{uuid}. Gated by `employees.view`.
     */
    public function show(string $uuid): JsonResponse
    {
        $employee = Employee::where('uuid', $uuid)->first();
        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        return response()->json(new EmployeeResource($employee));
    }

    /**
     * POST /employees — gated by `employees.create`. Transactional
     * user + employee + primary assignment + role create. Matches the
     * mock's `createEmployeeFull`.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $result = $this->employees->createFull($request->validated(), $request->user());

        return response()->json([
            'employee' => new EmployeeResource($result['employee']),
            'user' => new UserResource($result['user']),
            'assignment' => new AssignmentResource($result['assignment']),
        ], 201);
    }

    /**
     * PATCH /employees/{uuid} — gated by `employees.update`. Matches the
     * mock's `updateEmployee` (pinfl/extracts immutable).
     */
    public function update(UpdateEmployeeRequest $request, string $uuid): JsonResponse
    {
        $employee = $this->employees->update($uuid, $request->validated(), $request->user());

        return response()->json(new EmployeeResource($employee));
    }

    /**
     * POST /employees/{uuid}/terminate — gated by `employees.terminate`.
     * Certified termination-order extract required. Matches the mock's
     * `terminateEmployee` (cert-cascade deferred to phase E).
     */
    public function terminate(TerminateEmployeeRequest $request, string $uuid): JsonResponse
    {
        $employee = $this->employees->terminate($uuid, $request->user(), $request->validated('orderExtract'));

        return response()->json(new EmployeeResource($employee));
    }

    /**
     * POST /employees/{uuid}/transfer — gated by `employees.update`. Flow 3:
     * workload-cap + primary-invariant transfer, matching the mock's
     * `transferEmployee`.
     */
    public function transfer(TransferEmployeeRequest $request, string $uuid): JsonResponse
    {
        $assignment = $this->employees->transfer($uuid, $request->validated(), $request->user());

        return response()->json(new AssignmentResource($assignment), 201);
    }

    /**
     * GET /employees/{uuid}/assignments — gated by `employees.view`.
     * Matches the mock's `listAssignments`. Phase C only ever seeds/creates
     * one PRIMARY row per employee (transfer history is Phase D).
     */
    public function assignments(string $uuid): JsonResponse
    {
        $rows = Assignment::where('employee_uuid', $uuid)->orderByDesc('start_date')->get();

        return response()->json(AssignmentResource::collection($rows));
    }

    /**
     * GET /validate/pinfl/{pinfl} — gated by `employees.view`. `taken=true`
     * when a NON-TERMINATED employee already holds this PINFL, matching
     * the mock's live-uniqueness check semantics.
     */
    public function validatePinfl(string $pinfl): JsonResponse
    {
        $taken = Employee::where('pinfl', $pinfl)->where('status', '!=', 'TERMINATED')->exists();

        return response()->json(['taken' => $taken]);
    }
}
