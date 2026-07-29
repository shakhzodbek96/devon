<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Structural validation only — the workload-cap policy check
     * (`workload-exceeded`) lives in `EmployeeService::transfer`, not here,
     * so its 422 body keeps the `{ code, message }` shape the frontend
     * mock's error vocabulary expects instead of Laravel's default
     * `{ message, errors }` shape.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'newUnitUuid' => ['required', 'string', 'uuid', 'exists:structural_units,uuid'],
            'newPositionId' => ['required', 'string', 'exists:positions,id'],
            'startDate' => ['required', 'date'],
            'workloadPercent' => ['required', 'integer', 'min:1', 'max:150'],
            'type' => ['required', Rule::in(['PRIMARY', 'COMBINATION', 'ACTING', 'TEMPORARY'])],
            'closeOldAssignment' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
