<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `ApprovalStep` (src/types/domain.ts) verbatim —
 * `order_no` (DB column, avoids the reserved word `order`) is exposed as
 * `order` here.
 */
class ApprovalStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'documentUuid' => $this->document_uuid,
            'round' => $this->round,
            'order' => $this->order_no,
            'employeeUuid' => $this->employee_uuid,
            'decision' => $this->decision,
            'comment' => $this->comment,
            'decidedAt' => $this->decided_at?->toIso8601String(),
        ];
    }
}
