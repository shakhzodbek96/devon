<?php

namespace App\Http\Requests;

class UpdateCollegialBodyRequest extends StoreCollegialBodyRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'chairmanEmployeeUuid' => ['sometimes', 'required', 'string', 'uuid'],
            'secretaryEmployeeUuid' => ['sometimes', 'required', 'string', 'uuid'],
            'quorumMin' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
