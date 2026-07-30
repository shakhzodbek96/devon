<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollegialBodyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'chairmanEmployeeUuid' => ['required', 'string', 'uuid'],
            'secretaryEmployeeUuid' => ['required', 'string', 'uuid'],
            'quorumMin' => ['required', 'integer', 'min:1'],
            'memberEmployeeUuids' => ['sometimes', 'array'],
            'memberEmployeeUuids.*' => ['string', 'uuid'],
        ];
    }
}
