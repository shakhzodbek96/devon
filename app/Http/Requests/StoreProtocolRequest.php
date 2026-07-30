<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /collegial-bodies/{bodyUuid}/protocols` — structural validation
 * only. Secretary/`collegial.manage` authorization, roster membership
 * (`not-body-member`) and quorum (`quorum-not-met`) live in
 * `ProtocolService::create`.
 */
class StoreProtocolRequest extends FormRequest
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
            'protocolDate' => ['required', 'date'],
            'templateKey' => ['required', 'string', 'max:255'],
            'agenda' => ['required', 'string'],
            'participantUuids' => ['required', 'array', 'min:1'],
            'participantUuids.*' => ['string', 'uuid', Rule::exists('employees', 'uuid')->where('status', 'ACTIVE')],
            'reviewerUuids' => ['sometimes', 'array'],
            'reviewerUuids.*' => ['string', 'uuid', Rule::exists('employees', 'uuid')->where('status', 'ACTIVE')],
        ];
    }
}
