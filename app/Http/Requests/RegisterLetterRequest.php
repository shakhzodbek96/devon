<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /letters` — register an INCOMING letter. Structural validation
 * only — the Devonxona-only policy check (`not-devonxona`) lives in
 * `LetterService::register`, not here.
 */
class RegisterLetterRequest extends FormRequest
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
            'externalOrg' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:1000'],
            'channel' => ['required', Rule::in(['POCHTA', 'EMAIL', 'KURYER', 'QOGOZ'])],
            'receivedAt' => ['required', 'date'],
            'deadline' => ['nullable', 'date'],
            'requiresSignature' => ['required', 'boolean'],
            'fileMeta' => ['nullable', 'array'],
            'fileMeta.fileName' => ['required_with:fileMeta', 'string'],
            'fileMeta.fileSize' => ['required_with:fileMeta', 'integer'],
            'fileMeta.mimeType' => ['required_with:fileMeta', 'string'],
        ];
    }
}
