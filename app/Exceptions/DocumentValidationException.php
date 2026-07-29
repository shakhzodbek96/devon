<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule / per-document authorization violation (PLAN_PHASE_F1.md
 * §2's "Avtorizatsiya" section). `errorCode` matches the frontend mock's
 * `DocumentValidationCode` (frontend/dashboard/src/lib/mock-backend/errors.ts)
 * verbatim: 'wrong-status' | 'not-creator' | 'not-participant' |
 * 'out-of-order' | 'already-decided' | 'comment-required' | 'not-recipient'
 * | 'not-editable' | 'not-deletable'. ('not-signer' / 'cert-invalid' are
 * F2-only — signDocument is not built here.)
 * Rendered as a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class DocumentValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Document validation failed: {$errorCode}");
    }
}
