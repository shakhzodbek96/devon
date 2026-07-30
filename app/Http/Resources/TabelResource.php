<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the planned frontend `Tabel`. `entries` is only populated when
 * the `entries` relation has been eager-loaded (`TabelController::show`).
 */
class TabelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'unitUuid' => $this->unit_uuid,
            'period' => $this->period,
            'status' => $this->status,
            'createdBy' => $this->created_by,
            'headNote' => $this->head_note,
            'hrNote' => $this->hr_note,
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'headSignedAt' => $this->head_signed_at?->toIso8601String(),
            'hrAcceptedAt' => $this->hr_accepted_at?->toIso8601String(),
            'entries' => $this->whenLoaded('entries', fn () => TabelEntryResource::collection($this->entries)),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
