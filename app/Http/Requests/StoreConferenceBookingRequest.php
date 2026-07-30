<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /conference-bookings` — structural validation only. Room existence/
 * status, time-range ordering, and overlap conflicts live in
 * `ConferenceBookingService::create`, not here.
 */
class StoreConferenceBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roomUuid' => ['required', 'string', 'uuid'],
            'purpose' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
        ];
    }
}
