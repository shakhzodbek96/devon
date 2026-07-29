<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /letters/{uuid}/dispatch` — RESPONDED -> DISPATCHED + creates the
 * OUTGOING reply. Devonxona-only policy check (`not-devonxona`) lives in
 * `LetterService::dispatch`, not here.
 */
class DispatchLetterRequest extends FormRequest
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
            'channel' => ['required', Rule::in(['POCHTA', 'EMAIL', 'KURYER', 'QOGOZ'])],
        ];
    }
}
