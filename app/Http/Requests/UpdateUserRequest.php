<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'fullName' => ['sometimes', 'string', 'max:255'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_keys(config('devon_roles')))],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'SUSPENDED'])],
        ];
    }
}
