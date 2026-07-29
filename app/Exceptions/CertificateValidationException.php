<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for certificate uploads (PLAN_PHASE_E.md §2).
 * `errorCode` matches the frontend mock's `CertificateValidationCode`
 * (frontend/dashboard/src/lib/mock-backend/errors.ts) verbatim:
 * 'serial-taken' | 'pinfl-mismatch'.
 * Rendered as a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class CertificateValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Certificate validation failed: {$errorCode}");
    }
}
