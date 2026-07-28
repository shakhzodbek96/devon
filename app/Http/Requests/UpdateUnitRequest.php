<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
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
            'nameUz' => ['sometimes', 'string', 'min:3', 'max:255'],
            'nameRu' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shortName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'code' => [
                'sometimes', 'string', 'regex:/^[A-Za-z0-9-]{2,20}$/',
                Rule::unique('structural_units', 'code')->ignore($this->route('uuid'), 'uuid'),
            ],
            'type' => ['sometimes', Rule::in(['DEPARTMENT', 'DIRECTORATE', 'DIVISION', 'DEPARTMENT_SUB', 'SECTION', 'OTHER'])],
            'parentUuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'headEmployeeUuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'deputyEmployeeUuid' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }
}
