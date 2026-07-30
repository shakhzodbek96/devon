<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkShift;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the standard work-shift registry
 * (PLAN_tabel-davomat.md §2) — admin CRUD (create/update/archive-via-
 * delete), no tree, same shape as `ConferenceRoomService`.
 * `AuditLogger::log(...)` on every mutation.
 */
class WorkShiftService
{
    public function create(array $input, User $actor): WorkShift
    {
        return DB::transaction(function () use ($input, $actor) {
            $shift = WorkShift::create([
                'uuid' => (string) Str::uuid(),
                'unit_uuid' => $input['unitUuid'] ?? null,
                'start_time' => $input['startTime'],
                'end_time' => $input['endTime'],
                'late_grace_min' => $input['lateGraceMin'] ?? 10,
                'early_exit_grace_min' => $input['earlyExitGraceMin'] ?? 15,
            ]);

            AuditLogger::log($actor, 'CREATE', 'work-shift', $shift->uuid, $this->label($shift));

            return $shift;
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $uuid, array $patch, User $actor): WorkShift
    {
        return DB::transaction(function () use ($uuid, $patch, $actor) {
            $shift = WorkShift::where('uuid', $uuid)->firstOrFail();

            $fieldMap = [
                'unitUuid' => 'unit_uuid',
                'startTime' => 'start_time',
                'endTime' => 'end_time',
                'lateGraceMin' => 'late_grace_min',
                'earlyExitGraceMin' => 'early_exit_grace_min',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $shift->{$column} = $patch[$frontKey];
                }
            }

            $shift->save();

            AuditLogger::log($actor, 'UPDATE', 'work-shift', $shift->uuid, $this->label($shift));

            return $shift->fresh();
        });
    }

    public function delete(string $uuid, User $actor): void
    {
        DB::transaction(function () use ($uuid, $actor) {
            $shift = WorkShift::where('uuid', $uuid)->firstOrFail();
            $label = $this->label($shift);
            $shift->delete();

            AuditLogger::log($actor, 'DELETE', 'work-shift', $uuid, $label);
        });
    }

    private function label(WorkShift $shift): string
    {
        return "{$shift->start_time}-{$shift->end_time}".($shift->unit_uuid ? " ({$shift->unit_uuid})" : ' (global)');
    }
}
