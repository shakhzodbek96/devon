<?php

namespace App\Services;

use App\Exceptions\AttendanceValidationException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OfficeNetwork;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Daily check-in/check-out engine (PLAN_tabel-davomat.md §0, §2, §3). Every
 * mutation acts on the *acting employee* — the authenticated user's own
 * employee, resolved by `AttendanceController` and passed in here — never
 * a client-supplied "act as anyone" (same guard convention as
 * `TaskService`/`LetterService`).
 *
 * Wi-Fi perimeter check is REAL (simple CIDR match against the request
 * IP). Face ID is BLOCKED(face-recognition) — `face_status` only ever
 * records "the employee tapped confirm on the client", never real
 * biometric verification (PLAN §0).
 */
class AttendanceService
{
    /**
     * `checkIn` — creates today's record. A SECOND check-in attempt on an
     * already-open record is interpreted as an implicit check-out
     * (mirrors the prototype). A check-in against an already-CLOSED
     * today's record is rejected (`already-closed`). Network perimeter
     * failure rejects outright — no record is created (`network-blocked`).
     */
    public function checkIn(Employee $actor, string $requestIp): AttendanceRecord
    {
        return DB::transaction(function () use ($actor, $requestIp) {
            $today = now()->format('Y-m-d');
            $existing = AttendanceRecord::where('employee_uuid', $actor->uuid)
                ->where('work_date', $today)
                ->first();

            if ($existing) {
                if ($existing->check_out_at !== null) {
                    throw new AttendanceValidationException('already-closed');
                }

                // Second attempt on an open record == the employee is
                // signaling they're leaving — treat as checkOut().
                return $this->checkOut($actor);
            }

            $networkOk = $this->isNetworkOk($actor, $requestIp);
            if (! $networkOk) {
                throw new AttendanceValidationException('network-blocked');
            }

            $now = now();
            $shift = $this->shiftFor($actor);
            $status = 'OK';
            $lateMinutes = null;

            if ($shift) {
                $shiftStart = Carbon::parse("{$today} {$shift->start_time}");
                $graceEnd = $shiftStart->copy()->addMinutes($shift->late_grace_min);
                if ($now->gt($graceEnd)) {
                    $lateMinutes = $shiftStart->diffInMinutes($now);
                    $status = 'LATE';
                }
            }

            $record = AttendanceRecord::create([
                'uuid' => (string) Str::uuid(),
                'employee_uuid' => $actor->uuid,
                'work_date' => $today,
                'check_in_at' => $now,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'network_ok' => true,
                'face_status' => 'SELF_CONFIRMED',
                'source' => 'SELF_CHECKIN',
            ]);

            $this->auditFor($actor, 'ATTENDANCE_CHECKED_IN', $record);

            return $record->fresh();
        });
    }

    /**
     * `checkOut` — closes today's open record. No open record for today
     * (never checked in, or already closed) is rejected
     * (`already-checked-in` — the precondition "you must already be
     * checked in" failed).
     */
    public function checkOut(Employee $actor): AttendanceRecord
    {
        return DB::transaction(function () use ($actor) {
            $today = now()->format('Y-m-d');
            $record = AttendanceRecord::where('employee_uuid', $actor->uuid)
                ->where('work_date', $today)
                ->whereNull('check_out_at')
                ->first();

            if (! $record) {
                throw new AttendanceValidationException('already-checked-in');
            }

            $now = now();
            $shift = $this->shiftFor($actor);
            $status = $record->status;
            $earlyExitMinutes = null;

            if ($shift) {
                $shiftEnd = Carbon::parse("{$today} {$shift->end_time}");
                $graceStart = $shiftEnd->copy()->subMinutes($shift->early_exit_grace_min);
                if ($now->lt($graceStart)) {
                    $earlyExitMinutes = $now->diffInMinutes($shiftEnd);
                    $status = 'EARLY_EXIT';
                }
            }

            $record->check_out_at = $now;
            $record->early_exit_minutes = $earlyExitMinutes;
            $record->status = $status;
            $record->save();

            $this->auditFor($actor, 'ATTENDANCE_CHECKED_OUT', $record);

            return $record->fresh();
        });
    }

    /**
     * `manualEntry` — HR correction/creation, `source=HR_MANUAL`. Upserts
     * on (employeeUuid, workDate).
     *
     * @param  array<string, mixed>  $input  Shape: employeeUuid, workDate,
     *   checkInAt?, checkOutAt?, status, lateMinutes?, earlyExitMinutes?,
     *   note?.
     */
    public function manualEntry(array $input, User $actorUser): AttendanceRecord
    {
        return DB::transaction(function () use ($input, $actorUser) {
            $employeeUuid = $input['employeeUuid'];
            $workDate = $input['workDate'];

            $record = AttendanceRecord::where('employee_uuid', $employeeUuid)
                ->where('work_date', $workDate)
                ->first();

            $attrs = [
                'employee_uuid' => $employeeUuid,
                'work_date' => $workDate,
                'check_in_at' => $input['checkInAt'] ?? null,
                'check_out_at' => $input['checkOutAt'] ?? null,
                'status' => $input['status'],
                'late_minutes' => $input['lateMinutes'] ?? null,
                'early_exit_minutes' => $input['earlyExitMinutes'] ?? null,
                'network_ok' => true,
                'face_status' => null,
                'source' => 'HR_MANUAL',
                'note' => $input['note'] ?? null,
            ];

            if ($record) {
                $record->fill($attrs);
                $record->save();
            } else {
                $record = AttendanceRecord::create(array_merge(['uuid' => (string) Str::uuid()], $attrs));
            }

            AuditLogger::log(
                $actorUser, 'ATTENDANCE_MANUAL_ENTRY', 'attendance', $record->uuid,
                "{$employeeUuid} · {$workDate}",
            );

            return $record->fresh();
        });
    }

    /**
     * `myHistory` — one calendar month of the acting employee's own
     * records, ordered by date.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function myHistory(string $employeeUuid, string $month): Collection
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->format('Y-m-d');
        $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->format('Y-m-d');

        return AttendanceRecord::where('employee_uuid', $employeeUuid)
            ->whereBetween('work_date', [$start, $end])
            ->orderBy('work_date')
            ->get();
    }

    /**
     * `list` — HR monitoring. Filters: unitUuid, date.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, AttendanceRecord>
     */
    public function list(array $filters): Collection
    {
        $query = AttendanceRecord::query();

        if (! empty($filters['date'])) {
            $query->where('work_date', $filters['date']);
        }
        if (! empty($filters['unitUuid'])) {
            $employeeUuids = Employee::where('primary_unit_uuid', $filters['unitUuid'])->pluck('uuid');
            $query->whereIn('employee_uuid', $employeeUuids);
        }

        return $query->orderByDesc('work_date')->get();
    }

    /**
     * Global (unit_uuid=null) OR the actor's own unit's ACTIVE office
     * network — a match against EITHER counts as network_ok=true.
     */
    private function isNetworkOk(Employee $actor, string $ip): bool
    {
        $networks = OfficeNetwork::where('status', 'ACTIVE')
            ->where(function ($q) use ($actor) {
                $q->whereNull('unit_uuid');
                if ($actor->primary_unit_uuid) {
                    $q->orWhere('unit_uuid', $actor->primary_unit_uuid);
                }
            })
            ->get();

        foreach ($networks as $network) {
            if ($this->ipInCidr($ip, $network->cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple IPv4 CIDR match — `ip2long` + bitmask, no external library
     * (PLAN_tabel-davomat.md §3). `/0` (e.g. the demo's `0.0.0.0/0`)
     * matches everything.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        if ($bits <= 0) {
            return true;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits >= 32 ? -1 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** The actor's own unit shift, falling back to the organization-wide default (unit_uuid=null). */
    private function shiftFor(Employee $actor): ?WorkShift
    {
        if ($actor->primary_unit_uuid) {
            $unitShift = WorkShift::where('unit_uuid', $actor->primary_unit_uuid)->first();
            if ($unitShift) {
                return $unitShift;
            }
        }

        return WorkShift::whereNull('unit_uuid')->first();
    }

    private function auditFor(Employee $actor, string $action, AttendanceRecord $record): void
    {
        $user = User::where('employee_uuid', $actor->uuid)->first();
        if (! $user) {
            return;
        }

        AuditLogger::log($user, $action, 'attendance', $record->uuid, "{$actor->full_name_generated} · {$record->work_date->format('Y-m-d')}");
    }
}
