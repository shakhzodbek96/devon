<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for the monthly timesheet approval chain
 * (PLAN_tabel-davomat.md §1, §3). `errorCode`: 'not-editable' |
 * 'wrong-status' | 'not-authorized' | 'entries-incomplete' |
 * 'not-all-units-ready' | 'not-rahbar' | 'cert-invalid'. Rendered as a 422
 * `{ code, message }` response — see `bootstrap/app.php`.
 */
class TabelValidationException extends Exception
{
    /**
     * Named `errorCode` (not `code`) — PHP's base `Exception` already
     * declares a non-readonly `$code` property, so promoting a readonly
     * `$code` here would collide with it.
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Tabel validation failed: {$errorCode}");
    }
}
