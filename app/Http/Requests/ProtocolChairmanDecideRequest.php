<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /protocols/{uuid}/chairman-decide` — structural validation only.
 * `approve=true` requires `certificateUuid`+`signatureHex` (ERI, mirrors
 * `TabelHeadDecideRequest`); cert ownership/validity (`cert-invalid`) and
 * chairman authorization (`not-chairman`) live in
 * `ProtocolService::chairmanDecide`.
 */
class ProtocolChairmanDecideRequest extends FormRequest
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
            'approve' => ['required', 'boolean'],
            'certificateUuid' => ['required_if:approve,true', 'nullable', 'string', 'uuid'],
            'signatureHex' => ['required_if:approve,true', 'nullable', 'string'],
            'note' => ['required_if:approve,false', 'nullable', 'string', 'max:1000'],
        ];
    }
}
