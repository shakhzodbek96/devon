<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /tabels/{uuid}/entries` — structural validation only. Editable-
 * window gating (`not-editable`) and authorization live in
 * `TabelService::updateEntries`.
 */
class UpdateTabelEntriesRequest extends FormRequest
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
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.employeeUuid' => ['required', 'string', 'uuid'],
            'entries.*.workDate' => ['required', 'date'],
            'entries.*.status' => ['required', Rule::in([
                'OK', 'LATE', 'EARLY_EXIT', 'ABSENT', 'HOLIDAY_WORK', 'AUTO_CLOSED', 'MANUAL',
                'VACATION', 'SICK_LEAVE', 'BUSINESS_TRIP', 'DAY_OFF',
            ])],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
