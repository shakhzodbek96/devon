<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /letters/{uuid}/execute` — ASSIGNED/IN_PROGRESS -> EXECUTED. BPMN
 * 7.1 (comment-only) vs 7.2 (response file/document) branch selection is a
 * PRESENCE check on `executionComment` (`array_key_exists`, matching the
 * mock's `input.executionComment !== undefined`) — `sometimes` here ensures
 * an absent field never lands in `validated()`.
 */
class ExecuteLetterRequest extends FormRequest
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
            'executionComment' => ['sometimes', 'nullable', 'string'],
            'responseFileMeta' => ['sometimes', 'nullable', 'array'],
            'responseFileMeta.fileName' => ['required_with:responseFileMeta', 'string'],
            'responseFileMeta.fileSize' => ['required_with:responseFileMeta', 'integer'],
            'responseFileMeta.mimeType' => ['required_with:responseFileMeta', 'string'],
            'responseDocumentUuid' => ['sometimes', 'nullable', 'string', 'uuid', 'exists:documents,uuid'],
        ];
    }
}
