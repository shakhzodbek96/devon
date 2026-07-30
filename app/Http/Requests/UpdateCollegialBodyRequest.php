<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateCollegialBodyRequest extends StoreCollegialBodyRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'chairmanEmployeeUuid' => ['sometimes', 'required', 'string', 'uuid', Rule::exists('employees', 'uuid')->where('status', 'ACTIVE')],
            'secretaryEmployeeUuid' => ['sometimes', 'required', 'string', 'uuid', Rule::exists('employees', 'uuid')->where('status', 'ACTIVE')],
            'quorumMin' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
