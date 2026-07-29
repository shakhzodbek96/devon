<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule / per-task authorization violation (PLAN_PHASE_H.md §2,
 * BP-2). `errorCode` matches the frontend mock's `TaskValidationCode`
 * (frontend/dashboard/src/lib/mock-backend/errors.ts) verbatim:
 * 'not-assigner' | 'not-assignee' | 'out-of-scope' | 'wrong-status' |
 * 'reason-required' | 'self-assign'. Rendered as a 422 `{ code, message }`
 * response — see `bootstrap/app.php`.
 */
class TaskValidationException extends Exception
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Task validation failed: {$errorCode}");
    }
}
