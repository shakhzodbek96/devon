<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /tasks/{uuid}/review` — structural validation only. Assigner-only
 * (`not-assigner`), status gate (`wrong-status`) and the RETURN/REJECT
 * reason requirement (`reason-required`) live in `TaskService::review`, not
 * here.
 */
class ReviewTaskRequest extends FormRequest
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
            'decision' => ['required', Rule::in(['ACCEPT', 'ACCEPT_WITH_NOTE', 'RETURN', 'REJECT'])],
            'text' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
