<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule / per-document authorization violation (PLAN_PHASE_F1.md
 * §2's "Avtorizatsiya" section; PLAN_PHASE_F2.md §2 adds signing). `errorCode`
 * matches the frontend mock's `DocumentValidationCode`
 * (frontend/dashboard/src/lib/mock-backend/errors.ts) verbatim:
 * 'wrong-status' | 'not-creator' | 'not-participant' | 'out-of-order' |
 * 'already-decided' | 'comment-required' | 'not-signer' | 'not-recipient' |
 * 'cert-invalid' | 'not-editable' | 'not-deletable'.
 * Rendered as a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class DocumentValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Document validation failed: {$errorCode}");
    }
}
