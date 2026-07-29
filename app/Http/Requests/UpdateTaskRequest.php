<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /tasks/{uuid}` — structural validation only. Assigner-only
 * (`not-assigner`) and non-terminal (`wrong-status`) live in
 * `TaskService::update`, not here. `sometimes` throughout — only present
 * keys are applied (patch semantics, mirrors `UpdateUnitRequest`).
 */
class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'priority' => ['sometimes', Rule::in(['HIGH', 'MEDIUM', 'STANDARD'])],
            'deadline' => ['sometimes', 'date'],
        ];
    }
}
