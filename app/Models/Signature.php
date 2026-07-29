<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ERI signature record (PLAN_PHASE_F2.md §1). Mirrors the frontend
 * `SignatureRecord` (src/types/domain.ts). Shared by documents AND letters
 * (`resource_type` disambiguates) — only document signing writes here in
 * this phase; letter signing lands in phase G against the same table.
 *
 * BLOCKED(e-imzo): `signature_hex` is a client-generated fake (the
 * frontend's `FakeEriSigner.ts` never sends a private key here) — this
 * model only ever persists signature *metadata*, which IS real.
 */
class Signature extends Model
{
    use HasFactory, HasUuids;

    /** No `updated_at` column — matches the frontend type (signedAt only). */
    const UPDATED_AT = null;

    /** No `created_at` column either — `signed_at` is the single timestamp. */
    const CREATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'resource_type',
        'resource_uuid',
        'employee_uuid',
        'certificate_uuid',
        'algorithm',
        'signature_hex',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }
}
