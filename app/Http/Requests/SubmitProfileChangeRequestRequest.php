<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitProfileChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `employeeUuid` is intentionally NOT validated here — the controller
     * resolves it from the acting user's own linked employee (never trusted
     * from client input); see `EmployeeService::submitProfileChangeRequest`.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
        ];
    }
}
