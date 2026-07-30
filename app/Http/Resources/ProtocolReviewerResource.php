<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtocolReviewerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'protocolUuid' => $this->protocol_uuid,
            'employeeUuid' => $this->employee_uuid,
            'decision' => $this->decision,
            'note' => $this->note,
            'decidedAt' => $this->decided_at?->toIso8601String(),
        ];
    }
}
