<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Structural validation only — the policy-layer checks that must render
     * as `CertificateValidationException` (pinfl-mismatch, serial-taken)
     * live in `CertificateService::upload`, not here, so their 422 body
     * keeps the `{ code, message }` shape the frontend mock's error
     * vocabulary expects instead of Laravel's default `{ message, errors }`
     * shape.
     *
     * BLOCKED(e-imzo): only metadata fields are accepted — there is no PFX
     * file / private key field here by design (see PLAN_PHASE_E.md §0).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employeeUuid' => ['required', 'string', 'uuid', 'exists:employees,uuid'],
            'serialNumber' => ['required', 'string', 'max:255'],
            'thumbprint' => ['required', 'string', 'max:255'],
            'subjectPinfl' => ['required', 'string', 'regex:/^[1-6]\d{13}$/'],
            'subjectCommonName' => ['required', 'string', 'max:255'],
            'subjectOrganization' => ['nullable', 'string', 'max:255'],
            'issuerName' => ['required', 'string', 'max:255'],
            'validFrom' => ['required', 'date'],
            'validTo' => ['required', 'date', 'after:validFrom'],
            'keyUsage' => ['required', 'array', 'min:1'],
            'keyUsage.*' => ['string'],
            'certificateType' => ['required', Rule::in(['SIGNING', 'ENCRYPTION', 'BOTH'])],
            'autoApprove' => ['nullable', 'boolean'],
        ];
    }
}
