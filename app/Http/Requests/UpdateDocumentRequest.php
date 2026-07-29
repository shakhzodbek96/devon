<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * All fields optional (patch) — the `not-editable` / `not-creator`
     * policy checks live in `DocumentService::updateDraft`.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'values' => ['sometimes', 'array'],
            'values.*' => ['nullable', 'string'],
            'fileMeta' => ['sometimes', 'array'],
            'fileMeta.fileName' => ['required_with:fileMeta', 'string'],
            'fileMeta.fileSize' => ['required_with:fileMeta', 'integer'],
            'fileMeta.mimeType' => ['required_with:fileMeta', 'string'],
            'recipientUuid' => ['sometimes', 'string', 'uuid', 'exists:employees,uuid'],
            'signerUuid' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:employees,uuid'],
            'confidentiality' => ['sometimes', Rule::in(['ODDIY', 'MAXFIY'])],
            'participantUuids' => ['sometimes', 'array'],
            'participantUuids.*' => ['string', 'uuid'],
        ];
    }
}
