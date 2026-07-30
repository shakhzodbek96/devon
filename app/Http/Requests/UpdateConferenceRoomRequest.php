<?php

namespace App\Http\Requests;

class UpdateConferenceRoomRequest extends StoreConferenceRoomRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
