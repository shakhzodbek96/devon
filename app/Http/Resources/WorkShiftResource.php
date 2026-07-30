<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'unitUuid' => $this->unit_uuid,
            'startTime' => substr((string) $this->start_time, 0, 5),
            'endTime' => substr((string) $this->end_time, 0, 5),
            'lateGraceMin' => $this->late_grace_min,
            'earlyExitGraceMin' => $this->early_exit_grace_min,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
