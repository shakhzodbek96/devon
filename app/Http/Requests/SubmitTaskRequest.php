<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /tasks/{uuid}/submit` — structural validation only. Assignee-only
 * (`not-assignee`) and status gate (`wrong-status`) live in
 * `TaskService::submit`, not here. `file` already carries `uploadedAt` —
 * same passthrough convention as `CreateTaskRequest`.
 */
class SubmitTaskRequest extends FormRequest
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
            'summary' => ['required', 'string'],
            'file' => ['nullable', 'array'],
            'file.fileName' => ['required_with:file', 'string'],
            'file.fileSize' => ['required_with:file', 'integer'],
            'file.mimeType' => ['required_with:file', 'string'],
            'file.uploadedAt' => ['required_with:file', 'string'],
            'documentUuid' => ['nullable', 'string', 'uuid', 'exists:documents,uuid'],
        ];
    }
}
