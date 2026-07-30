<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /tabels/generate` — structural validation only. Authorization
 * (unit head or `attendance.manage`) and idempotency live in
 * `TabelService::generate`.
 */
class GenerateTabelRequest extends FormRequest
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
            'unitUuid' => ['required', 'string', 'uuid'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }
}
