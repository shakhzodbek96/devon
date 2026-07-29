<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `ProfileChangeRequest` (src/types/domain.ts) verbatim.
 */
class ProfileChangeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'employeeUuid' => $this->employee_uuid,
            'fields' => $this->fields,
            'status' => $this->status,
            'rejectionReason' => $this->rejection_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewedByUuid' => $this->reviewed_by_uuid,
        ];
    }
}
