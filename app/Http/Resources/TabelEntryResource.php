<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TabelEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'tabelUuid' => $this->tabel_uuid,
            'employeeUuid' => $this->employee_uuid,
            'workDate' => $this->work_date?->format('Y-m-d'),
            'status' => $this->status,
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
