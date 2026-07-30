<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkShiftRequest extends FormRequest
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
            'unitUuid' => ['nullable', 'string', 'uuid'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
            'lateGraceMin' => ['nullable', 'integer', 'min:0'],
            'earlyExitGraceMin' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
