<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `DocumentEntity` (src/types/domain.ts) verbatim.
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'title' => $this->title,
            'source' => $this->source,
            'templateUuid' => $this->template_uuid,
            'values' => $this->values,
            'renderedBody' => $this->rendered_body,
            'fileMeta' => $this->file_meta,
            'confidentiality' => $this->confidentiality,
            'creatorUuid' => $this->creator_uuid,
            'recipientUuid' => $this->recipient_uuid,
            'signerUuid' => $this->signer_uuid,
            'requiresApproval' => (bool) $this->requires_approval,
            'status' => $this->status,
            'round' => $this->round,
            'viewedBy' => $this->viewed_by ?? [],
            'sentForReviewAt' => $this->sent_for_review_at?->toIso8601String(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'signedAt' => $this->signed_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'archivedAt' => $this->archived_at?->toIso8601String(),
            'emailedTo' => $this->emailed_to,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
