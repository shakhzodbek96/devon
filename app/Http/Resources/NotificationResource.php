<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `AppNotification` (src/types/domain.ts) verbatim.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'recipientEmployeeUuid' => $this->recipient_employee_uuid,
            'type' => $this->type,
            'titleKey' => $this->title_key,
            'params' => $this->params,
            'resourceType' => $this->resource_type,
            'resourceUuid' => $this->resource_uuid,
            'isRead' => (bool) $this->is_read,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
