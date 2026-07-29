<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `Assignment` (src/types/domain.ts) verbatim.
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'employeeUuid' => $this->employee_uuid,
            'unitUuid' => $this->unit_uuid,
            'positionId' => $this->position_id,
            'isPrimary' => (bool) $this->is_primary,
            'startDate' => $this->start_date,
            'endDate' => $this->end_date,
            'workloadPercent' => $this->workload_percent,
            'type' => $this->type,
            'reason' => $this->reason,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
