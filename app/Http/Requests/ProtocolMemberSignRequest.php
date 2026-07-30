<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /protocols/{uuid}/member-sign` — structural validation only. ERI
 * cert ownership/validity (`cert-invalid`) and participant authorization
 * (`not-participant`/`already-signed`) live in `ProtocolService::memberSign`.
 */
class ProtocolMemberSignRequest extends FormRequest
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
            'certificateUuid' => ['required', 'string', 'uuid'],
            'signatureHex' => ['required', 'string'],
        ];
    }
}
