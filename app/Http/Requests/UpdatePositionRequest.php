<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /positions/{id} — partial update. Only keys present are applied
 * (mirrors the Unit patch semantics); `id` and `allowedUnitTypes` shape are
 * validated the same as on create when supplied.
 */
class UpdatePositionRequest extends FormRequest
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
            'nameUz' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'allowedUnitTypes' => ['sometimes', 'required', 'array', 'min:1'],
            'allowedUnitTypes.*' => [Rule::in(['DEPARTMENT', 'DIRECTORATE', 'DIVISION', 'DEPARTMENT_SUB', 'SECTION', 'OTHER'])],
        ];
    }
}
