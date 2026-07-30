<?php

namespace App\Http\Requests;

class UpdateOfficeNetworkRequest extends StoreOfficeNetworkRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'cidr' => ['sometimes', 'required', 'string', 'max:64'],
            'unitUuid' => ['nullable', 'string', 'uuid'],
        ];
    }
}
