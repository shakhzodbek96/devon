<?php

namespace App\Services;

use App\Models\OfficeNetwork;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the Wi-Fi perimeter registry (PLAN_tabel-davomat.md
 * §2) — admin CRUD (create/update/archive), no tree, same shape as
 * `ConferenceRoomService`. `AuditLogger::log(...)` on every mutation.
 */
class OfficeNetworkService
{
    public function create(array $input, User $actor): OfficeNetwork
    {
        return DB::transaction(function () use ($input, $actor) {
            $network = OfficeNetwork::create([
                'uuid' => (string) Str::uuid(),
                'name' => $input['name'],
                'cidr' => $input['cidr'],
                'unit_uuid' => $input['unitUuid'] ?? null,
                'status' => 'ACTIVE',
            ]);

            AuditLogger::log($actor, 'CREATE', 'office-network', $network->uuid, $network->name);

            return $network;
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $uuid, array $patch, User $actor): OfficeNetwork
    {
        return DB::transaction(function () use ($uuid, $patch, $actor) {
            $network = OfficeNetwork::where('uuid', $uuid)->firstOrFail();

            $fieldMap = [
                'name' => 'name',
                'cidr' => 'cidr',
                'unitUuid' => 'unit_uuid',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $network->{$column} = $patch[$frontKey];
                }
            }

            $network->save();

            AuditLogger::log($actor, 'UPDATE', 'office-network', $network->uuid, $network->name);

            return $network->fresh();
        });
    }

    public function archive(string $uuid, User $actor): void
    {
        DB::transaction(function () use ($uuid, $actor) {
            $network = OfficeNetwork::where('uuid', $uuid)->firstOrFail();
            $network->status = 'ARCHIVED';
            $network->save();

            AuditLogger::log($actor, 'ARCHIVE', 'office-network', $network->uuid, $network->name);
        });
    }
}
