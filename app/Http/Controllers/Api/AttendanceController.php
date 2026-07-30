<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManualAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daily check-in/check-out (PLAN_tabel-davomat.md §3). Check-in/check-out/
 * me act as the AUTHENTICATED user's own employee — `actingEmployee()`
 * below, never a client-supplied uuid (same guard convention as
 * `TaskController`). `index`/`manual` are gated by
 * `attendance.view`/`attendance.manage` route middleware (HR monitoring).
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    private function actingEmployee(Request $request): Employee
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return Employee::where('uuid', $uuid)->firstOrFail();
    }

    /**
     * POST /attendance/check-in.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $record = $this->attendance->checkIn($this->actingEmployee($request), $request->ip());

        return response()->json(new AttendanceRecordResource($record), 201);
    }

    /**
     * POST /attendance/check-out.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $record = $this->attendance->checkOut($this->actingEmployee($request));

        return response()->json(new AttendanceRecordResource($record));
    }

    /**
     * GET /attendance/me?month=YYYY-MM.
     */
    public function me(Request $request): JsonResponse
    {
        $employee = $this->actingEmployee($request);
        $month = $request->query('month') ?: now()->format('Y-m');

        return response()->json(AttendanceRecordResource::collection(
            $this->attendance->myHistory($employee->uuid, $month),
        ));
    }

    /**
     * GET /attendance?unitUuid=&date= — HR monitoring, `can:attendance.view`.
     */
    public function index(Request $request): JsonResponse
    {
        $records = $this->attendance->list([
            'unitUuid' => $request->query('unitUuid'),
            'date' => $request->query('date'),
        ]);

        return response()->json(AttendanceRecordResource::collection($records));
    }

    /**
     * POST /attendance/manual — HR correction/creation, `can:attendance.manage`.
     */
    public function manual(ManualAttendanceRequest $request): JsonResponse
    {
        $record = $this->attendance->manualEntry($request->validated(), $request->user());

        return response()->json(new AttendanceRecordResource($record), 201);
    }
}
