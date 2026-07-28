<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `AuditEntry` (src/types/domain.ts) verbatim.
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'actorUuid' => $this->actor_uuid,
            'actorName' => $this->actor_name,
            'action' => $this->action,
            'resourceType' => $this->resource_type,
            'resourceUuid' => $this->resource_uuid,
            'resourceLabel' => $this->resource_label,
            'changes' => $this->changes,
            'context' => $this->context,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
