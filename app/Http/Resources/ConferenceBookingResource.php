<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the planned frontend `ConferenceBooking` (src/types/domain.ts).
 */
class ConferenceBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'roomUuid' => $this->room_uuid,
            'bookedBy' => $this->booked_by,
            'purpose' => $this->purpose,
            'date' => $this->date?->format('Y-m-d'),
            'startTime' => substr((string) $this->start_time, 0, 5),
            'endTime' => substr((string) $this->end_time, 0, 5),
            'status' => $this->status,
            'cancelledBy' => $this->cancelled_by,
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
