<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for daily attendance check-in/check-out
 * (PLAN_tabel-davomat.md §2, §3). `errorCode`: 'network-blocked' |
 * 'already-checked-in' | 'already-closed'. Rendered as a 422
 * `{ code, message }` response — see `bootstrap/app.php`.
 */
class AttendanceValidationException extends Exception
{
    /**
     * Named `errorCode` (not `code`) — PHP's base `Exception` already
     * declares a non-readonly `$code` property, so promoting a readonly
     * `$code` here would collide with it.
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Attendance validation failed: {$errorCode}");
    }
}
