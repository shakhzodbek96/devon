<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Conference-room booking (PLAN_conference-room.md §2). Mirrors the
 * frontend `ConferenceBooking` (src/types/domain.ts). Business rules
 * (overlap check, cancel authorization) live in
 * `App\Services\ConferenceBookingService` — this model is intentionally
 * thin.
 */
class ConferenceBooking extends Model
{
    use HasUuids;

    protected $table = 'conference_bookings';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'room_uuid',
        'booked_by',
        'purpose',
        'date',
        'start_time',
        'end_time',
        'status',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }
}
