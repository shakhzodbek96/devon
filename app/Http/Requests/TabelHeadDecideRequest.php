<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /tabels/{uuid}/head-decide` — structural validation only.
 * `approve=true` requires `certificateUuid`+`signatureHex` (ERI, mirrors
 * `LetterController`'s sign payload); cert ownership/validity
 * (`cert-invalid`) and rahbar authorization (`not-rahbar`) live in
 * `TabelService::headDecide`.
 */
class TabelHeadDecideRequest extends FormRequest
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
