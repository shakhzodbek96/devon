<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
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
            'nameUz' => ['required', 'string', 'min:3', 'max:255'],
            'nameRu' => ['nullable', 'string', 'max:255'],
            'shortName' => ['nullable', 'string', 'max:50'],
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{2,20}$/', 'unique:structural_units,code'],
            'type' => ['required', Rule::in(['DEPARTMENT', 'DIRECTORATE', 'DIVISION', 'DEPARTMENT_SUB', 'SECTION', 'OTHER'])],
            'parentUuid' => ['nullable', 'string', 'uuid'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
