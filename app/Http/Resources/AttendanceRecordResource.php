<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'employeeUuid' => $this->employee_uuid,
            'workDate' => $this->work_date?->format('Y-m-d'),
            'checkInAt' => $this->check_in_at?->toIso8601String(),
            'checkOutAt' => $this->check_out_at?->toIso8601String(),
            'status' => $this->status,
            'lateMinutes' => $this->late_minutes,
            'earlyExitMinutes' => $this->early_exit_minutes,
            'networkOk' => (bool) $this->network_ok,
            'faceStatus' => $this->face_status,
            'source' => $this->source,
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
