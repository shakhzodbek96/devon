<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Structural validation only — `participantUuids` shape (ordered,
     * non-empty, duplicate-free, creator-excluded) is a policy-layer check
     * in `DocumentService::create` (matches the mock's
     * `assertValidParticipants`), not here.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'source' => ['required', Rule::in(['TEMPLATE', 'UPLOAD'])],
            'templateUuid' => ['required_if:source,TEMPLATE', 'nullable', 'string', 'uuid', 'exists:document_templates,uuid'],
            'values' => ['required_if:source,TEMPLATE', 'nullable', 'array'],
            'values.*' => ['nullable', 'string'],
            'fileMeta' => ['required_if:source,UPLOAD', 'nullable', 'array'],
            'fileMeta.fileName' => ['required_if:source,UPLOAD', 'string'],
            'fileMeta.fileSize' => ['required_if:source,UPLOAD', 'integer'],
            'fileMeta.mimeType' => ['required_if:source,UPLOAD', 'string'],
            'confidentiality' => ['nullable', Rule::in(['ODDIY', 'MAXFIY'])],
            'recipientUuid' => ['required', 'string', 'uuid', 'exists:employees,uuid'],
            'signerUuid' => ['nullable', 'string', 'uuid', 'exists:employees,uuid'],
            'requiresApproval' => ['required', 'boolean'],
            'participantUuids' => ['required_if:requiresApproval,true', 'nullable', 'array'],
            'participantUuids.*' => ['string', 'uuid'],
        ];
    }
}
