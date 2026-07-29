<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * pinfl / employmentOrderExtract / positionInstruction /
     * terminationOrderExtract are deliberately absent — immutable
     * post-creation (mirrors the mock's `Partial<Omit<Employee, ...>>`
     * patch type).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lastName' => ['sometimes', 'string', 'max:255'],
            'firstName' => ['sometimes', 'string', 'max:255'],
            'middleName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', Rule::in(['M', 'F'])],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            'passportSeries' => ['sometimes', 'nullable', 'string', 'max:20'],
            'workPhone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'internalExtension' => ['sometimes', 'nullable', 'string', 'max:10'],
            'mobilePhone' => ['sometimes', 'string', 'max:30'],
            'corporateEmail' => [
                'sometimes', 'string', 'email',
                Rule::unique('employees', 'corporate_email')->ignore($this->route('uuid'), 'uuid'),
            ],
            'personalEmail' => ['sometimes', 'nullable', 'string', 'email'],
            'primaryUnitUuid' => ['sometimes', 'string', 'uuid', 'exists:structural_units,uuid'],
            'positionId' => ['sometimes', 'string', 'exists:positions,id'],
            'employmentType' => ['sometimes', Rule::in(['FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERN'])],
            'hireDate' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'ACTIVE', 'ON_LEAVE', 'SUSPENDED', 'TERMINATED'])],
            'avatarUrl' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
