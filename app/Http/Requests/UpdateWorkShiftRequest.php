<?php

namespace App\Http\Requests;

class UpdateWorkShiftRequest extends StoreWorkShiftRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unitUuid' => ['nullable', 'string', 'uuid'],
            'startTime' => ['sometimes', 'required', 'date_format:H:i'],
            'endTime' => ['sometimes', 'required', 'date_format:H:i'],
            'lateGraceMin' => ['nullable', 'integer', 'min:0'],
            'earlyExitGraceMin' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
