<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevokeCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `EMPLOYEE_TERMINATED` is intentionally excluded — it is reserved for
     * `EmployeeService::terminate`'s cascade and is never a manually-chosen
     * reason, matching the frontend mock's `RevokeDialog`
     * (`src/features/certificates/RevokeDialog.tsx`), which excludes it
     * from the reason picker for the same rationale.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(['EXPIRED', 'COMPROMISED', 'REPLACED', 'MANUAL'])],
        ];
    }
}
