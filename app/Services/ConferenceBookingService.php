<?php

namespace App\Services;

use App\Exceptions\ConferenceBookingValidationException;
use App\Models\ConferenceBooking;
use App\Models\ConferenceRoom;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conference-room booking workflow (PLAN_conference-room.md §1, §3). Every
 * mutation validates against the *acting employee* uuid — the authenticated
 * user's own employee, resolved by `ConferenceBookingController` and passed
 * in here — never a client-supplied "act as anyone".
 */
class ConferenceBookingService
{
    /**
     * `list` — filters: roomUuid, date, or box=mine (booked_by = acting).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ConferenceBooking>
     */
    public function list(array $filters): Collection
    {
        $query = ConferenceBooking::query();

        if (! empty($filters['roomUuid'])) {
            $query->where('room_uuid', $filters['roomUuid']);
        }
        if (! empty($filters['date'])) {
            $query->where('date', $filters['date']);
        }
        if (($filters['box'] ?? null) === 'mine') {
            $query->where('booked_by', $filters['actingUuid']);
        }

        return $query->orderBy('date')->orderBy('start_time')->get();
    }

    /**
     * `create` — the room must exist and be ACTIVE (`invalid-room` /
     * `room-archived`), `endTime > startTime` (`invalid-range`), and the
     * requested range must not overlap an existing BOOKED booking for the
     * same room + date (`time-conflict`).
     *
     * @param  array<string, mixed>  $input  Shape: roomUuid, purpose, date,
     *   startTime, endTime.
     */
    public function create(array $input, string $actorEmployeeUuid, User $actorUser): ConferenceBooking
    {
        return DB::transaction(function () use ($input, $actorEmployeeUuid, $actorUser) {
            $room = ConferenceRoom::where('uuid', $input['roomUuid'])->first();
            if (! $room) {
                throw new ConferenceBookingValidationException('invalid-room');
            }
            if ($room->status !== 'ACTIVE') {
                throw new ConferenceBookingValidationException('room-archived');
            }

            // Normalize H:i input to H:i:s so string comparisons against the
            // stored `time` column (which SQLite/Postgres render with
            // seconds) are byte-for-byte comparable — "10:00" is a lexical
            // *prefix* of "10:00:00" and would otherwise compare unequal.
            $startTime = strlen($input['startTime']) === 5 ? $input['startTime'].':00' : $input['startTime'];
            $endTime = strlen($input['endTime']) === 5 ? $input['endTime'].':00' : $input['endTime'];

            if ($endTime <= $startTime) {
                throw new ConferenceBookingValidationException('invalid-range');
            }

            $overlaps = ConferenceBooking::where('room_uuid', $room->uuid)
                ->where('date', $input['date'])
                ->where('status', 'BOOKED')
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->exists();
            if ($overlaps) {
                throw new ConferenceBookingValidationException('time-conflict');
            }

            $booking = ConferenceBooking::create([
                'uuid' => (string) Str::uuid(),
                'room_uuid' => $room->uuid,
                'booked_by' => $actorEmployeeUuid,
                'purpose' => $input['purpose'],
                'date' => $input['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => 'BOOKED',
            ]);

            AuditLogger::log(
                $actorUser, 'CREATE', 'conference-booking', $booking->uuid,
                "{$room->name} · {$booking->date->format('Y-m-d')} {$startTime}-{$endTime}",
            );

            return $booking->fresh();
        });
    }

    /**
     * `cancel` — only the booking's own `booked_by` or a user holding
     * `conference-rooms.manage` may cancel. Per-resource authorization
     * lives here, not in the controller.
     */
    public function cancel(string $uuid, string $actorEmployeeUuid, User $actorUser): ConferenceBooking
    {
        return DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $booking = ConferenceBooking::where('uuid', $uuid)->firstOrFail();

            if ($booking->booked_by !== $actorEmployeeUuid && ! $actorUser->can('conference-rooms.manage')) {
                throw new ConferenceBookingValidationException('not-authorized');
            }
            if ($booking->status !== 'BOOKED') {
                throw new ConferenceBookingValidationException('wrong-status');
            }

            $booking->status = 'CANCELLED';
            $booking->cancelled_by = $actorEmployeeUuid;
            $booking->cancelled_at = now();
            $booking->save();

            AuditLogger::log($actorUser, 'CANCEL', 'conference-booking', $booking->uuid, $booking->purpose);

            return $booking->fresh();
        });
    }
}
