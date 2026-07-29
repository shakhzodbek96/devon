<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `TaskComment` (src/types/domain.ts) verbatim.
 */
class TaskCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'authorUuid' => $this->author_uuid,
            'kind' => $this->kind,
            'body' => $this->body,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
