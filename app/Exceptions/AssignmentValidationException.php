<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for the transfer/assignment operation
 * (PLAN_PHASE_D.md §1). `errorCode` matches the frontend mock's
 * `AssignmentValidationCode` (frontend/dashboard/src/lib/mock-backend/errors.ts)
 * verbatim: 'workload-exceeded'. Rendered as a 422 `{ code, message }`
 * response — see `bootstrap/app.php`.
 */
class AssignmentValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Assignment validation failed: {$errorCode}");
    }
}
