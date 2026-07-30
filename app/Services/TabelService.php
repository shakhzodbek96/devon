<?php

namespace App\Services;

use App\Exceptions\TabelValidationException;
use App\Models\AttendanceRecord;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Signature;
use App\Models\Tabel;
use App\Models\TabelEntry;
use App\Models\TabelRegistryDispatch;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monthly timesheet approval-chain engine (PLAN_tabel-davomat.md §1, §3):
 * DRAFT -> SENT_TO_HEAD -> (auto-signed) SENT_TO_HR -> HR_ACCEPTED, with
 * HEAD_REJECTED/HR_REJECTED bouncing back to an editable state. Every
 * mutation validates against the *acting employee/user* — resolved by
 * `TabelController` and passed in here — never a client-supplied "act as
 * anyone" (same guard convention as `LetterService`/`TaskService`).
 *
 * "Bo'linma rahbari" = `Unit.head_employee_uuid` of the tabel's own unit —
 * ANY level, no root-only restriction (unlike `LetterService::isRahbar`,
 * per PLAN_tabel-davomat.md §6 clarified answer #1).
 *
 * ERI signing (`headDecide` approve path) reuses the SHARED `signatures`
 * table (`resource_type='tabel'`) — same ACTIVE-cert-owned-by-actor
 * pattern as `LetterService::sign`. BLOCKED(e-imzo): `signatureHex` is the
 * frontend's client-side fake — only signature *metadata* is persisted.
 */
class TabelService
{
    private const EDITABLE_STATUSES = ['DRAFT', 'HEAD_REJECTED', 'HR_REJECTED'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * `generate` — idempotent: returns the existing `Tabel` for
     * unit+period if one already exists. Otherwise creates a DRAFT tabel
     * and fills `tabel_entries` for every ACTIVE employee whose
     * `primary_unit_uuid` is this unit, one row per calendar day of the
     * period, copying status from `attendance_records` where present,
     * else DAY_OFF (Sat/Sun) or ABSENT (workday, no record yet).
     */
    public function generate(string $unitUuid, string $period, User $actorUser): Tabel
    {
        return DB::transaction(function () use ($unitUuid, $period, $actorUser) {
            $unit = Unit::where('uuid', $unitUuid)->firstOrFail();

            if (! $this->canManageUnit($unit, $actorUser)) {
                throw new TabelValidationException('not-authorized');
            }

            $existing = Tabel::where('unit_uuid', $unitUuid)->where('period', $period)->first();
            if ($existing) {
                return $existing;
            }

            $tabel = Tabel::create([
                'uuid' => (string) Str::uuid(),
                'unit_uuid' => $unitUuid,
                'period' => $period,
                'status' => 'DRAFT',
                'created_by' => $actorUser->employee_uuid,
            ]);

            $employees = Employee::where('primary_unit_uuid', $unitUuid)->where('status', 'ACTIVE')->get();
            $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            foreach ($employees as $employee) {
                for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                    $workDate = $date->format('Y-m-d');
                    $record = AttendanceRecord::where('employee_uuid', $employee->uuid)
                        ->where('work_date', $workDate)
                        ->first();

                    if ($record) {
                        $status = $record->status;
                    } elseif ($date->isWeekend()) {
                        $status = 'DAY_OFF';
                    } else {
                        $status = 'ABSENT';
                    }

                    TabelEntry::create([
                        'uuid' => (string) Str::uuid(),
                        'tabel_uuid' => $tabel->uuid,
                        'employee_uuid' => $employee->uuid,
                        'work_date' => $workDate,
                        'status' => $status,
                    ]);
                }
            }

            AuditLogger::log($actorUser, 'TABEL_GENERATED', 'tabel', $tabel->uuid, $this->label($tabel));

            return $tabel->fresh();
        });
    }

    /**
     * `updateEntries` — only while DRAFT/HEAD_REJECTED/HR_REJECTED
     * (`not-editable`). Only present cells are touched.
     *
     * @param  array<int, array{employeeUuid: string, workDate: string, status: string, note?: string|null}>  $entries
     */
    public function updateEntries(string $tabelUuid, array $entries, User $actorUser): Tabel
    {
        return DB::transaction(function () use ($tabelUuid, $entries, $actorUser) {
            $tabel = Tabel::where('uuid', $tabelUuid)->firstOrFail();

            if (! in_array($tabel->status, self::EDITABLE_STATUSES, true)) {
                throw new TabelValidationException('not-editable');
            }

            $unit = Unit::where('uuid', $tabel->unit_uuid)->first();
            if (! $unit || ! $this->canManageUnit($unit, $actorUser)) {
                throw new TabelValidationException('not-authorized');
            }

            foreach ($entries as $entry) {
                TabelEntry::where('tabel_uuid', $tabelUuid)
                    ->where('employee_uuid', $entry['employeeUuid'])
                    ->where('work_date', $entry['workDate'])
                    ->update([
                        'status' => $entry['status'],
                        'note' => $entry['note'] ?? null,
                    ]);
            }

            AuditLogger::log($actorUser, 'TABEL_ENTRIES_UPDATED', 'tabel', $tabel->uuid, $this->label($tabel));

            return $tabel->fresh();
        });
    }

    /**
     * `submitToHead` — DRAFT/HEAD_REJECTED/HR_REJECTED -> SENT_TO_HEAD.
     * `entries-incomplete` guards against submitting a tabel with no
     * generated cells at all (programmer/UI error otherwise).
     */
    public function submitToHead(string $tabelUuid, User $actorUser): Tabel
    {
        return DB::transaction(function () use ($tabelUuid, $actorUser) {
            $tabel = Tabel::where('uuid', $tabelUuid)->firstOrFail();

            if (! in_array($tabel->status, self::EDITABLE_STATUSES, true)) {
                throw new TabelValidationException('wrong-status');
            }

            $unit = Unit::where('uuid', $tabel->unit_uuid)->first();
            if (! $unit || ! $this->canManageUnit($unit, $actorUser)) {
                throw new TabelValidationException('not-authorized');
            }

            if (TabelEntry::where('tabel_uuid', $tabelUuid)->count() === 0) {
                throw new TabelValidationException('entries-incomplete');
            }

            $tabel->status = 'SENT_TO_HEAD';
            $tabel->submitted_at = now();
            $tabel->save();

            if ($unit->head_employee_uuid) {
                $this->notifications->append(
                    $unit->head_employee_uuid,
                    'TABEL_SUBMITTED',
                    'dashboard:notifications.title.TABEL_SUBMITTED',
                    ['period' => $tabel->period],
                    'tabel',
                    $tabel->uuid,
                );
            }

            AuditLogger::log($actorUser, 'TABEL_SUBMITTED', 'tabel', $tabel->uuid, $this->label($tabel));

            return $tabel->fresh();
        });
    }

    /**
     * `headDecide` — the tabel's OWN unit head only (`not-rahbar`), while
     * SENT_TO_HEAD (`wrong-status`). `approve=true` requires an ACTIVE
     * certificate owned by the actor (`cert-invalid`, folding "not found"
     * / "not owned" / "not active" into one code — mirrors
     * `LetterService::sign`), writes to the shared `signatures` table, and
     * auto-advances straight to SENT_TO_HR (the transient HEAD_SIGNED
     * value exists in the schema but is not persisted — the transition is
     * immediate, per PLAN §1 node 6). `approve=false` -> HEAD_REJECTED
     * with `note`.
     */
    public function headDecide(
        string $tabelUuid,
        ?string $certificateUuid,
        ?string $signatureHex,
        User $actorUser,
        string $actorEmployeeUuid,
        bool $approve,
        ?string $note,
    ): Tabel {
        return DB::transaction(function () use ($tabelUuid, $certificateUuid, $signatureHex, $actorUser, $actorEmployeeUuid, $approve, $note) {
            $tabel = Tabel::where('uuid', $tabelUuid)->firstOrFail();

            if ($tabel->status !== 'SENT_TO_HEAD') {
                throw new TabelValidationException('wrong-status');
            }

            $unit = Unit::where('uuid', $tabel->unit_uuid)->first();
            if (! $unit || $unit->head_employee_uuid !== $actorEmployeeUuid) {
                throw new TabelValidationException('not-rahbar');
            }

            if ($approve) {
                $cert = $certificateUuid ? Certificate::where('uuid', $certificateUuid)->first() : null;
                if (! $cert || $cert->status !== 'ACTIVE' || $cert->employee_uuid !== $actorEmployeeUuid) {
                    throw new TabelValidationException('cert-invalid');
                }

                Signature::create([
                    'uuid' => (string) Str::uuid(),
                    'resource_type' => 'tabel',
                    'resource_uuid' => $tabelUuid,
                    'employee_uuid' => $actorEmployeeUuid,
                    'certificate_uuid' => $certificateUuid,
                    'algorithm' => 'RSA-PKCS7',
                    'signature_hex' => $signatureHex,
                    'signed_at' => now(),
                ]);

                $tabel->status = 'SENT_TO_HR';
                $tabel->head_signed_at = now();
                $tabel->head_note = null;
                $tabel->save();

                AuditLogger::log($actorUser, 'TABEL_HEAD_SIGNED', 'tabel', $tabel->uuid, $this->label($tabel), null, [
                    'certificateSerial' => $cert->serial_number,
                ]);
            } else {
                $tabel->status = 'HEAD_REJECTED';
                $tabel->head_note = $note;
                $tabel->save();

                AuditLogger::log($actorUser, 'TABEL_HEAD_REJECTED', 'tabel', $tabel->uuid, $this->label($tabel));
            }

            return $tabel->fresh();
        });
    }

    /**
     * `hrDecide` — HR only (`attendance.manage`, `not-authorized`
     * otherwise), while SENT_TO_HR (`wrong-status`). `approve=true` ->
     * HR_ACCEPTED, then `maybeDispatchRegistry`. `approve=false` ->
     * HR_REJECTED with `note`.
     */
    public function hrDecide(string $tabelUuid, User $actorUser, bool $approve, ?string $note): Tabel
    {
        return DB::transaction(function () use ($tabelUuid, $actorUser, $approve, $note) {
            $tabel = Tabel::where('uuid', $tabelUuid)->firstOrFail();

            if ($tabel->status !== 'SENT_TO_HR') {
                throw new TabelValidationException('wrong-status');
            }
            if (! $actorUser->can('attendance.manage')) {
                throw new TabelValidationException('not-authorized');
            }

            if ($approve) {
                $tabel->status = 'HR_ACCEPTED';
                $tabel->hr_accepted_at = now();
                $tabel->hr_note = null;
                $tabel->save();

                AuditLogger::log($actorUser, 'TABEL_HR_ACCEPTED', 'tabel', $tabel->uuid, $this->label($tabel));

                $this->maybeDispatchRegistry($tabel->period, $actorUser);
            } else {
                $tabel->status = 'HR_REJECTED';
                $tabel->hr_note = $note;
                $tabel->save();

                AuditLogger::log($actorUser, 'TABEL_HR_REJECTED', 'tabel', $tabel->uuid, $this->label($tabel));
            }

            return $tabel->fresh();
        });
    }

    /**
     * `maybeDispatchRegistry` — once EVERY ACTIVE unit has an HR_ACCEPTED
     * `Tabel` for `period`, writes a single `TabelRegistryDispatch` row
     * (idempotent — a no-op if one already exists for the period).
     */
    public function maybeDispatchRegistry(string $period, User $actorUser): void
    {
        if (TabelRegistryDispatch::where('period', $period)->exists()) {
            return;
        }

        $activeUnitUuids = Unit::where('status', 'ACTIVE')->pluck('uuid');
        if ($activeUnitUuids->isEmpty()) {
            return;
        }

        $acceptedCount = Tabel::where('period', $period)
            ->where('status', 'HR_ACCEPTED')
            ->whereIn('unit_uuid', $activeUnitUuids)
            ->count();

        if ($acceptedCount < $activeUnitUuids->count()) {
            return;
        }

        TabelRegistryDispatch::create([
            'period' => $period,
            'dispatched_at' => now(),
            'dispatched_by' => $actorUser->employee_uuid,
        ]);

        AuditLogger::log($actorUser, 'TABEL_REGISTRY_DISPATCHED', 'tabel-registry', $period, "Reestr {$period}");
    }

    /**
     * `list` — filters: unitUuid, period, box=mine (units the acting
     * employee heads).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Tabel>
     */
    public function list(array $filters): Collection
    {
        $query = Tabel::query();

        if (! empty($filters['unitUuid'])) {
            $query->where('unit_uuid', $filters['unitUuid']);
        }
        if (! empty($filters['period'])) {
            $query->where('period', $filters['period']);
        }
        if (($filters['box'] ?? null) === 'mine' && ! empty($filters['actingUuid'])) {
            $headedUnits = Unit::where('head_employee_uuid', $filters['actingUuid'])->pluck('uuid');
            $query->whereIn('unit_uuid', $headedUnits);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * `registryFor` — Accounting's cross-unit view of a period: every
     * ACTIVE unit's tabel (or null if not yet generated) plus the dispatch
     * marker.
     *
     * @return array{units: array<int, array{unit: Unit, tabel: ?Tabel}>, dispatch: ?TabelRegistryDispatch}
     */
    public function registryFor(string $period): array
    {
        $units = Unit::where('status', 'ACTIVE')->orderBy('name_uz')->get();
        $tabelsByUnit = Tabel::where('period', $period)->get()->keyBy('unit_uuid');

        $rows = $units->map(fn (Unit $unit) => [
            'unit' => $unit,
            'tabel' => $tabelsByUnit->get($unit->uuid),
        ])->values()->all();

        return [
            'units' => $rows,
            'dispatch' => TabelRegistryDispatch::where('period', $period)->first(),
        ];
    }

    /** Unit head (of THIS unit, any level) or an `attendance.manage` holder (HR). */
    private function canManageUnit(Unit $unit, User $actorUser): bool
    {
        return $unit->head_employee_uuid === $actorUser->employee_uuid || $actorUser->can('attendance.manage');
    }

    private function label(Tabel $tabel): string
    {
        return "Tabel {$tabel->period} · {$tabel->unit_uuid}";
    }
}
