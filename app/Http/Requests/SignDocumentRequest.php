<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /documents/{uuid}/sign` — `signatureHex` arrives from the frontend's
 * client-side `FakeEriSigner.ts` (BLOCKED(e-imzo): fake hex, no real
 * PKCS#7). Certificate ownership/ACTIVE-status checks (`cert-invalid`) and
 * the signer/status policy (`not-signer` / `wrong-status`) are enforced in
 * `DocumentService::sign`, not here, so the 422 body carries the mock's
 * `{ code, message }` shape.
 */
class SignDocumentRequest extends FormRequest
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
