<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /tasks/{uuid}/clarification-request` and `.../clarification-answer`
 * — structural validation only (both share the same `body` shape). Persona
 * gate (`not-assignee`/`not-assigner`), status gate (`wrong-status`), and
 * the non-empty check (`reason-required`) live in `TaskService`, not here.
 */
class TaskClarificationRequest extends FormRequest
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
            // `nullable` (not `required`) — an empty/whitespace body must
            // reach `TaskService`'s own trim-check to produce the domain
            // `reason-required` code, not a generic Laravel validation error.
            'body' => ['nullable', 'string'],
        ];
    }
}
