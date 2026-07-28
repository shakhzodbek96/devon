<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for unit mutations (PLAN_PHASE_AB.md §2.2).
 * `code` matches the frontend mock's `UnitValidationCode`
 * (frontend/dashboard/src/lib/mock-backend/errors.ts) verbatim:
 * 'cycle' | 'duplicate-name' | 'max-depth' | 'invalid-parent'. Rendered as
 * a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class UnitValidationException extends Exception
{
    /**
     * Named `errorCode` (not `code`) — PHP's base `Exception` already
     * declares a non-readonly `$code` property, so promoting a readonly
     * `$code` here would collide with it.
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Unit validation failed: {$errorCode}");
    }
}
