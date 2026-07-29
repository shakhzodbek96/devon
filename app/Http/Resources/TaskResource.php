<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `TaskEntity` (src/types/domain.ts) verbatim. The
 * mock's `TaskEntity.comments` is an embedded chronological array — here
 * it's the `comments` relation, eager-loaded by `TaskController` wherever
 * this resource is used (falls back to a lazy per-row load otherwise).
 */
class TaskResource extends JsonResource
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
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'assignerUuid' => $this->assigner_uuid,
            'assigneeUuid' => $this->assignee_uuid,
            'deadline' => $this->deadline?->format('Y-m-d'),
            'attachedDocumentUuid' => $this->attached_document_uuid,
            'attachedFile' => $this->attached_file,
            'deliverable' => $this->deliverable,
            'reviewNote' => $this->review_note,
            'comments' => TaskCommentResource::collection($this->comments),
            'round' => $this->round,
            'lateSubmission' => $this->late_submission,
            'startedAt' => $this->started_at?->toIso8601String(),
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'acceptedAt' => $this->accepted_at?->toIso8601String(),
            'rejectedAt' => $this->rejected_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
