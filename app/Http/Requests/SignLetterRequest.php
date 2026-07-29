<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /letters/{uuid}/sign` — `signatureHex` arrives from the frontend's
 * client-side `FakeEriSigner.ts` (BLOCKED(e-imzo): fake hex, no real
 * PKCS#7). Certificate ownership/ACTIVE-status (`cert-invalid`) and the
 * Rahbar/status policy (`not-rahbar` / `wrong-status`) are enforced in
 * `LetterService::sign`, not here.
 */
class SignLetterRequest extends FormRequest
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
            'certificateUuid' => ['required', 'string', 'uuid'],
            'signatureHex' => ['required', 'string'],
        ];
    }
}
