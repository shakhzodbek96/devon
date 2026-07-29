<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule / per-station authorization violation (PLAN_PHASE_G.md §2,
 * BP-3). `errorCode` matches the frontend mock's `LetterValidationCode`
 * (frontend/dashboard/src/lib/mock-backend/errors.ts) verbatim:
 * 'wrong-status' | 'not-devonxona' | 'not-rahbar' | 'not-unit-head' |
 * 'not-executor' | 'comment-required' | 'missing-response' | 'cert-invalid'.
 * Rendered as a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class LetterValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Letter validation failed: {$errorCode}");
    }
}
