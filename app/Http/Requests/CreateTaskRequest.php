<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /tasks` — structural validation only. Subtree authorization
 * (`out-of-scope`) and no-self-assign (`self-assign`) live in
 * `TaskService::create`, not here. `attachedFile` already carries
 * `uploadedAt` — the frontend stamps it client-side (mirrors the mock's
 * passthrough, unlike `letters`/`documents` where the backend stamps it).
 */
class CreateTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['HIGH', 'MEDIUM', 'STANDARD'])],
            'assigneeUuid' => ['required', 'string', 'uuid'],
            'deadline' => ['required', 'date'],
            'attachedDocumentUuid' => ['nullable', 'string', 'uuid'],
            'attachedFile' => ['nullable', 'array'],
            'attachedFile.fileName' => ['required_with:attachedFile', 'string'],
            'attachedFile.fileSize' => ['required_with:attachedFile', 'integer'],
            'attachedFile.mimeType' => ['required_with:attachedFile', 'string'],
            'attachedFile.uploadedAt' => ['required_with:attachedFile', 'string'],
        ];
    }
}
