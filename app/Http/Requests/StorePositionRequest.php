<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /positions — creating a user-defined entry in the position catalogue
 * (the seeded 15 stay untouched). `id` is generated server-side, so the
 * client only supplies a name + the unit types the position is allowed on.
 */
class StorePositionRequest extends FormRequest
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
            'nameUz' => ['required', 'string', 'min:2', 'max:255'],
            'allowedUnitTypes' => ['required', 'array', 'min:1'],
            'allowedUnitTypes.*' => [Rule::in(['DEPARTMENT', 'DIRECTORATE', 'DIVISION', 'DEPARTMENT_SUB', 'SECTION', 'OTHER'])],
        ];
    }
}
