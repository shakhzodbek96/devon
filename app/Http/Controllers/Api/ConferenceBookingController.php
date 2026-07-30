<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConferenceBookingRequest;
use App\Http\Resources\ConferenceBookingResource;
use App\Services\ConferenceBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conference-room bookings (PLAN_conference-room.md §3). Every mutation
 * acts as the AUTHENTICATED user's own employee — `actingEmployeeUuid()`
 * below, never a client-supplied uuid (same privilege-escalation guard as
 * `TaskController`/`DocumentController`). No `can:` route middleware — any
 * authenticated employee may book a room; per-booking authorization
 * (cancel: owner or `conference-rooms.manage`) lives in
 * `ConferenceBookingService`, not here.
 */
class ConferenceBookingController extends Controller
{
    public function __construct(private readonly ConferenceBookingService $bookings)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /conference-bookings — filters: roomUuid, date, box=mine.
     */
    public function index(Request $request): JsonResponse
    {
        $bookings = $this->bookings->list([
            'roomUuid' => $request->query('roomUuid'),
            'date' => $request->query('date'),
            'box' => $request->query('box'),
            'actingUuid' => $this->actingEmployeeUuid($request),
        ]);

        return response()->json(ConferenceBookingResource::collection($bookings));
    }

    /**
     * POST /conference-bookings.
     */
    public function store(StoreConferenceBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->create(
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new ConferenceBookingResource($booking), 201);
    }

    /**
     * POST /conference-bookings/{uuid}/cancel.
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $booking = $this->bookings->cancel($uuid, $this->actingEmployeeUuid($request), $request->user());

        return response()->json(new ConferenceBookingResource($booking));
    }
}
