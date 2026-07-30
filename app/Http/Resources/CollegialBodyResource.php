<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollegialBodyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'chairmanEmployeeUuid' => $this->chairman_employee_uuid,
            'secretaryEmployeeUuid' => $this->secretary_employee_uuid,
            'quorumMin' => $this->quorum_min,
            'status' => $this->status,
            'memberEmployeeUuids' => $this->whenLoaded('members', fn () => $this->members->pluck('employee_uuid')->values()),
            'createdBy' => $this->created_by,
            'updatedBy' => $this->updated_by,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
