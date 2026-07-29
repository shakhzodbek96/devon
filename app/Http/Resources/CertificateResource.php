<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `Certificate` (src/types/domain.ts) verbatim.
 */
class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'employeeUuid' => $this->employee_uuid,
            'serialNumber' => $this->serial_number,
            'thumbprint' => $this->thumbprint,
            'subjectPinfl' => $this->subject_pinfl,
            'subjectCommonName' => $this->subject_common_name,
            'subjectOrganization' => $this->subject_organization,
            'issuerName' => $this->issuer_name,
            'validFrom' => $this->valid_from?->toIso8601String(),
            'validTo' => $this->valid_to?->toIso8601String(),
            'keyUsage' => $this->key_usage,
            'certificateType' => $this->certificate_type,
            'status' => $this->status,
            'rejectionReason' => $this->rejection_reason,
            'uploadedByUuid' => $this->uploaded_by_uuid,
            'approvedByUuid' => $this->approved_by_uuid,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'revokedAt' => $this->revoked_at?->toIso8601String(),
            'revocationReason' => $this->revocation_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
