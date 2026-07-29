<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `comment` required-when-REJECTED is enforced in `DocumentService::decide`
     * (`comment-required` error code) — kept there, not here, so the 422
     * body carries the mock's `{ code, message }` shape.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVED', 'APPROVED_WITH_COMMENT', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
