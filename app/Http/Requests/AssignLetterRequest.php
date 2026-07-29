<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /letters/{uuid}/assign` — ROUTED -> ASSIGNED. Unit-head policy
 * check (`not-unit-head`) and subtree-membership check live in
 * `LetterService::assign`, not here.
 */
class AssignLetterRequest extends FormRequest
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
            'employeeUuid' => ['required', 'string', 'uuid', 'exists:employees,uuid'],
        ];
    }
}
