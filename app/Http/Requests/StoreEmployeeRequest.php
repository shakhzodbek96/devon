<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Structural validation only — the policy-layer checks that must render
     * as `EmployeeValidationException` (order-extract-missing,
     * position-instruction-missing, pinfl-taken, email-taken) live in
     * `EmployeeService::createFull`, not here, so their 422 body keeps the
     * `{ code, message }` shape the frontend mock's error vocabulary
     * expects instead of Laravel's default `{ message, errors }` shape.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee' => ['required', 'array'],
            'employee.lastName' => ['required', 'string', 'max:255'],
            'employee.firstName' => ['required', 'string', 'max:255'],
            'employee.middleName' => ['nullable', 'string', 'max:255'],
            'employee.gender' => ['required', Rule::in(['M', 'F'])],
            'employee.birthDate' => ['nullable', 'date'],
            'employee.pinfl' => ['required', 'string', 'regex:/^[1-6]\d{13}$/'],
            'employee.passportSeries' => ['nullable', 'string', 'max:20'],
            'employee.workPhone' => ['nullable', 'string', 'max:30'],
            'employee.internalExtension' => ['nullable', 'string', 'max:10'],
            'employee.mobilePhone' => ['required', 'string', 'max:30'],
            'employee.corporateEmail' => ['required', 'string', 'email', 'regex:/@devon\.uz$/i'],
            'employee.personalEmail' => ['nullable', 'string', 'email'],
            'employee.primaryUnitUuid' => ['required', 'string', 'uuid', 'exists:structural_units,uuid'],
            'employee.positionId' => ['required', 'string', 'exists:positions,id'],
            'employee.employmentType' => ['required', Rule::in(['FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERN'])],
            'employee.hireDate' => ['required', 'date'],

            'orderExtract' => ['nullable', 'array'],
            'orderExtract.fileName' => ['nullable', 'string'],
            'orderExtract.fileSize' => ['nullable', 'numeric'],
            'orderExtract.mimeType' => ['nullable', 'string'],

            'positionInstruction' => ['nullable', 'array'],
            'positionInstruction.fileName' => ['nullable', 'string'],
            'positionInstruction.fileSize' => ['nullable', 'numeric'],
            'positionInstruction.mimeType' => ['nullable', 'string'],

            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in(['ROLE_EMPLOYEE', 'ROLE_UNIT_HEAD', 'ROLE_HR_OPERATOR', 'ROLE_AUDITOR'])],
        ];
    }
}
