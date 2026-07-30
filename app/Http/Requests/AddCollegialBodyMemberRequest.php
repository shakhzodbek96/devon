<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddCollegialBodyMemberRequest extends FormRequest
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
            'employeeUuid' => ['required', 'string', 'uuid'],
        ];
    }
}
