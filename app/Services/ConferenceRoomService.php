<?php

namespace App\Services;

use App\Models\ConferenceRoom;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the conference-room registry (PLAN_conference-room.md
 * §3) — admin CRUD (create/update/archive), no tree, simpler than
 * `UnitService`. `AuditLogger::log(...)` on every mutation.
 */
class ConferenceRoomService
{
    public function create(array $input, User $actor): ConferenceRoom
    {
        return DB::transaction(function () use ($input, $actor) {
            $room = ConferenceRoom::create([
                'uuid' => (string) Str::uuid(),
                'name' => $input['name'],
                'capacity' => $input['capacity'],
                'location' => $input['location'] ?? null,
                'status' => 'ACTIVE',
                'created_by' => $actor->uuid,
                'updated_by' => $actor->uuid,
            ]);

            AuditLogger::log($actor, 'CREATE', 'conference-room', $room->uuid, $room->name);

            return $room;
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $uuid, array $patch, User $actor): ConferenceRoom
    {
        return DB::transaction(function () use ($uuid, $patch, $actor) {
            $room = ConferenceRoom::where('uuid', $uuid)->firstOrFail();

            $fieldMap = [
                'name' => 'name',
                'capacity' => 'capacity',
                'location' => 'location',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $room->{$column} = $patch[$frontKey];
                }
            }

            $room->updated_by = $actor->uuid;
            $room->save();

            AuditLogger::log($actor, 'UPDATE', 'conference-room', $room->uuid, $room->name);

            return $room->fresh();
        });
    }

    public function archive(string $uuid, User $actor): void
    {
        DB::transaction(function () use ($uuid, $actor) {
            $room = ConferenceRoom::where('uuid', $uuid)->firstOrFail();
            $room->status = 'ARCHIVED';
            $room->updated_by = $actor->uuid;
            $room->save();

            AuditLogger::log($actor, 'ARCHIVE', 'conference-room', $room->uuid, $room->name);
        });
    }
}
