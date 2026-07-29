<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ERI (digital-signature) certificate metadata (PLAN_PHASE_E.md §1). Mirrors
 * the frontend `Certificate` (src/types/domain.ts). Business rules
 * (PINFL-ownership, serial-uniqueness, approve/reject/revoke lifecycle) live
 * in `App\Services\CertificateService` — this model is intentionally thin.
 *
 * BLOCKED(e-imzo): only certificate metadata is ever persisted here — the
 * private key never reaches the server (frontend's `FakePfxParser.ts` stays
 * client-side). Real PFX crypto validation is out of scope for this phase.
 */
class Certificate extends Model
{
    /** @use HasFactory<\Database\Factories\CertificateFactory> */
    use HasFactory, HasUuids;

    protected $table = 'digital_certificates';

    /** No `updated_at` column — matches the frontend type (createdAt only). */
    const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'employee_uuid',
        'serial_number',
        'thumbprint',
        'subject_pinfl',
        'subject_common_name',
        'subject_organization',
        'issuer_name',
        'valid_from',
        'valid_to',
        'key_usage',
        'certificate_type',
        'status',
        'rejection_reason',
        'uploaded_by_uuid',
        'approved_by_uuid',
        'approved_at',
        'revoked_at',
        'revocation_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'key_usage' => 'array',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
