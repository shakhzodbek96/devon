<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `Letter` (src/types/domain.ts) verbatim.
 */
class LetterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'direction' => $this->direction,
            'number' => $this->number,
            'externalOrg' => $this->external_org,
            'subject' => $this->subject,
            'channel' => $this->channel,
            'fileMeta' => $this->file_meta,
            'receivedAt' => $this->received_at?->format('Y-m-d'),
            'deadline' => $this->deadline?->format('Y-m-d'),
            'routedToUnitUuid' => $this->routed_to_unit_uuid,
            'assignedEmployeeUuid' => $this->assigned_employee_uuid,
            'requiresSignature' => (bool) $this->requires_signature,
            'executionComment' => $this->execution_comment,
            'responseFileMeta' => $this->response_file_meta,
            'responseDocumentUuid' => $this->response_document_uuid,
            'linkedIncomingUuid' => $this->linked_incoming_uuid,
            'status' => $this->status,
            'registeredByUuid' => $this->registered_by_uuid,
            'dispatchedAt' => $this->dispatched_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
