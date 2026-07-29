<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TerminateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `orderExtract` presence/shape is enforced in `EmployeeService::terminate`
     * as an `EmployeeValidationException('termination-extract-missing')` —
     * kept nullable here so the 422 body carries the mock's `{ code,
     * message }` shape instead of Laravel's default validation-error shape.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'orderExtract' => ['nullable', 'array'],
            'orderExtract.fileName' => ['nullable', 'string'],
            'orderExtract.fileSize' => ['nullable', 'numeric'],
            'orderExtract.mimeType' => ['nullable', 'string'],
        ];
    }
}
