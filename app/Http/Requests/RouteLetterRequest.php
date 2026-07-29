<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /letters/{uuid}/route` — REGISTERED -> ROUTED. Rahbar-only policy
 * check (`not-rahbar`) lives in `LetterService::route`, not here.
 */
class RouteLetterRequest extends FormRequest
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
            'unitUuid' => ['required', 'string', 'uuid', 'exists:structural_units,uuid'],
        ];
    }
}
