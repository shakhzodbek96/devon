<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /tabels/{uuid}/hr-decide` — structural validation only.
 * `attendance.manage` authorization and status gating live in
 * `TabelService::hrDecide`.
 */
class TabelHrDecideRequest extends FormRequest
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
            'approve' => ['required', 'boolean'],
            'note' => ['required_if:approve,false', 'nullable', 'string', 'max:1000'],
        ];
    }
}
