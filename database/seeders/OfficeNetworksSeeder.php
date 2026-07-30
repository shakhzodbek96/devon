<?php

namespace Database\Seeders;

use App\Models\OfficeNetwork;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo Wi-Fi perimeter (PLAN_tabel-davomat.md §3, §9, §6 clarified answer
 * #3) — one global (`unit_uuid=null`) CIDR `0.0.0.0/0`, i.e. wide open, so
 * demo logins (from any IP) always pass the network check. A real office
 * IP range can be configured later from the admin panel. Idempotent —
 * guarded by `OfficeNetwork::exists()`.
 */
class OfficeNetworksSeeder extends Seeder
{
    public function run(): void
    {
        if (OfficeNetwork::exists()) {
            return;
        }

        OfficeNetwork::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Demo — barcha tarmoqlar',
            'cidr' => '0.0.0.0/0',
            'unit_uuid' => null,
            'status' => 'ACTIVE',
        ]);
    }
}
