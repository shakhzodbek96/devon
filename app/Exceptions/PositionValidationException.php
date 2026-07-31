<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for position-catalogue mutations. `code` is a
 * stable string the frontend maps to a localized message:
 * 'duplicate-name' (another position already uses this name, case-insensitive)
 * | 'in-use' (position is referenced by an employee/assignment and cannot be
 * deleted). Rendered as a 422 `{ code, message }` response — see
 * `bootstrap/app.php`.
 */
class PositionValidationException extends Exception
{
    /**
     * Named `errorCode` (not `code`) — PHP's base `Exception` already
     * declares a non-readonly `$code`, so promoting a readonly `$code`
     * here would collide with it (same rationale as UnitValidationException).
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Position validation failed: {$errorCode}");
    }
}
