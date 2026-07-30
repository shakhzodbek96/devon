<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /protocols/{uuid}` — structural validation only. `not-editable`,
 * `not-authorized`, `not-body-member`, `quorum-not-met` live in
 * `ProtocolService::update`.
 */
class UpdateProtocolRequest extends FormRequest
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
            'protocolDate' => ['sometimes', 'required', 'date'],
            'templateKey' => ['sometimes', 'required', 'string', 'max:255'],
            'agenda' => ['sometimes', 'required', 'string'],
            'participantUuids' => ['sometimes', 'array', 'min:1'],
            'participantUuids.*' => ['string', 'uuid'],
            'reviewerUuids' => ['sometimes', 'array'],
            'reviewerUuids.*' => ['string', 'uuid'],
        ];
    }
}
