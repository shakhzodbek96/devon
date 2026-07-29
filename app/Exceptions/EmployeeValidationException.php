<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for employee mutations (PLAN_PHASE_C.md §2).
 * `errorCode` matches the frontend mock's `EmployeeValidationCode`
 * (frontend/dashboard/src/lib/mock-backend/errors.ts) verbatim:
 * 'pinfl-taken' | 'email-taken' | 'order-extract-missing' |
 * 'position-instruction-missing' | 'termination-extract-missing'.
 * Rendered as a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class EmployeeValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Employee validation failed: {$errorCode}");
    }
}
