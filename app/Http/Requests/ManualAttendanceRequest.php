<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /attendance/manual` — HR correction/creation, `source=HR_MANUAL`.
 * Structural validation only.
 */
class ManualAttendanceRequest extends FormRequest
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
            'workDate' => ['required', 'date'],
            'checkInAt' => ['nullable', 'date'],
            'checkOutAt' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['OK', 'LATE', 'EARLY_EXIT', 'ABSENT', 'HOLIDAY_WORK', 'AUTO_CLOSED', 'MANUAL'])],
            'lateMinutes' => ['nullable', 'integer', 'min:0'],
            'earlyExitMinutes' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
