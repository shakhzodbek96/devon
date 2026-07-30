<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `reviewers`/`participants` are only populated when those relations have
 * been eager-loaded (every `ProtocolService` method returns
 * `->fresh(['reviewers', 'participants'])`, and `ProtocolController::show`
 * loads them explicitly).
 */
class ProtocolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'collegialBodyUuid' => $this->collegial_body_uuid,
            'protocolDate' => $this->protocol_date?->format('Y-m-d'),
            'templateKey' => $this->template_key,
            'agenda' => $this->agenda,
            'status' => $this->status,
            'reviewNote' => $this->review_note,
            'chairmanNote' => $this->chairman_note,
            'createdBy' => $this->created_by,
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'membersCompletedAt' => $this->members_completed_at?->toIso8601String(),
            'chairmanSignedAt' => $this->chairman_signed_at?->toIso8601String(),
            'finalizedAt' => $this->finalized_at?->toIso8601String(),
            'reviewers' => $this->whenLoaded('reviewers', fn () => ProtocolReviewerResource::collection($this->reviewers)),
            'participants' => $this->whenLoaded('participants', fn () => ProtocolParticipantResource::collection($this->participants)),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
