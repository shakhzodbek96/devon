<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `SignatureRecord` (src/types/domain.ts) verbatim.
 */
class SignatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'resourceType' => $this->resource_type,
            'resourceUuid' => $this->resource_uuid,
            'employeeUuid' => $this->employee_uuid,
            'certificateUuid' => $this->certificate_uuid,
            'algorithm' => $this->algorithm,
            'signatureHex' => $this->signature_hex,
            'signedAt' => $this->signed_at?->toIso8601String(),
        ];
    }
}
