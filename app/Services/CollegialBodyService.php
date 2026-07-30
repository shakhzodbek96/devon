<?php

namespace App\Services;

use App\Exceptions\ProtocolValidationException;
use App\Models\CollegialBody;
use App\Models\CollegialBodyMember;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the standing "Kollegial organ" registry
 * (PLAN_kollegial-organ.md §2.2, §3) — admin CRUD (`UnitService`/
 * `ConferenceRoomService` pattern) plus permanent-roster member
 * management. Route-level authorization is `can:collegial.manage`
 * (`CollegialBodyController`) — nothing here re-checks that permission;
 * this service only enforces model-level invariants.
 */
class CollegialBodyService
{
    public function create(array $input, User $actor): CollegialBody
    {
        return DB::transaction(function () use ($input, $actor) {
            $body = CollegialBody::create([
                'uuid' => (string) Str::uuid(),
                'name' => $input['name'],
                'chairman_employee_uuid' => $input['chairmanEmployeeUuid'],
                'secretary_employee_uuid' => $input['secretaryEmployeeUuid'],
                'quorum_min' => $input['quorumMin'],
                'status' => 'ACTIVE',
                'created_by' => $actor->uuid,
                'updated_by' => $actor->uuid,
            ]);

            foreach (array_unique($input['memberEmployeeUuids'] ?? []) as $employeeUuid) {
                CollegialBodyMember::create([
                    'collegial_body_uuid' => $body->uuid,
                    'employee_uuid' => $employeeUuid,
                ]);
            }

            AuditLogger::log($actor, 'CREATE', 'collegial-body', $body->uuid, $body->name);

            return $body->fresh('members');
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $uuid, array $patch, User $actor): CollegialBody
    {
        return DB::transaction(function () use ($uuid, $patch, $actor) {
            $body = CollegialBody::where('uuid', $uuid)->firstOrFail();

            $fieldMap = [
                'name' => 'name',
                'chairmanEmployeeUuid' => 'chairman_employee_uuid',
                'secretaryEmployeeUuid' => 'secretary_employee_uuid',
                'quorumMin' => 'quorum_min',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $body->{$column} = $patch[$frontKey];
                }
            }

            $body->updated_by = $actor->uuid;
            $body->save();

            AuditLogger::log($actor, 'UPDATE', 'collegial-body', $body->uuid, $body->name);

            return $body->fresh('members');
        });
    }

    public function archive(string $uuid, User $actor): void
    {
        DB::transaction(function () use ($uuid, $actor) {
            $body = CollegialBody::where('uuid', $uuid)->firstOrFail();
            $body->status = 'ARCHIVED';
            $body->updated_by = $actor->uuid;
            $body->save();

            AuditLogger::log($actor, 'ARCHIVE', 'collegial-body', $body->uuid, $body->name);
        });
    }

    public function addMember(string $bodyUuid, string $employeeUuid, User $actor): CollegialBody
    {
        return DB::transaction(function () use ($bodyUuid, $employeeUuid, $actor) {
            $body = CollegialBody::where('uuid', $bodyUuid)->firstOrFail();

            CollegialBodyMember::firstOrCreate([
                'collegial_body_uuid' => $bodyUuid,
                'employee_uuid' => $employeeUuid,
            ]);

            $body->updated_by = $actor->uuid;
            $body->save();

            AuditLogger::log($actor, 'COLLEGIAL_MEMBER_ADDED', 'collegial-body', $body->uuid, $body->name, null, [
                'employeeUuid' => $employeeUuid,
            ]);

            return $body->fresh('members');
        });
    }

    public function removeMember(string $bodyUuid, string $employeeUuid, User $actor): CollegialBody
    {
        return DB::transaction(function () use ($bodyUuid, $employeeUuid, $actor) {
            $body = CollegialBody::where('uuid', $bodyUuid)->firstOrFail();

            $member = CollegialBodyMember::where('collegial_body_uuid', $bodyUuid)
                ->where('employee_uuid', $employeeUuid)
                ->first();
            if (! $member) {
                throw new ProtocolValidationException('not-body-member');
            }
            $member->delete();

            $body->updated_by = $actor->uuid;
            $body->save();

            AuditLogger::log($actor, 'COLLEGIAL_MEMBER_REMOVED', 'collegial-body', $body->uuid, $body->name, null, [
                'employeeUuid' => $employeeUuid,
            ]);

            return $body->fresh('members');
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, CollegialBody>
     */
    public function list(): \Illuminate\Support\Collection
    {
        return CollegialBody::with('members')->orderBy('name')->get();
    }
}
