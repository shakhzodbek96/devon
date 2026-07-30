<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Wi-Fi perimeter CIDR configuration (PLAN_tabel-davomat.md §2). Business
 * rules (CIDR matching) live in `App\Services\AttendanceService` — this
 * model is intentionally thin.
 */
class OfficeNetwork extends Model
{
    /** @use HasFactory<\Database\Factories\OfficeNetworkFactory> */
    use HasFactory, HasUuids;

    protected $table = 'office_networks';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'name',
        'cidr',
        'unit_uuid',
        'status',
    ];
}
