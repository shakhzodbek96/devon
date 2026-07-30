<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for the collegial-body / protocol approval chain
 * (PLAN_kollegial-organ.md §3, §4) — covers BOTH `CollegialBodyService`
 * (org CRUD/member management) and `ProtocolService` (the full
 * secretary -> reviewers -> members -> chairman workflow), same as PLAN §4
 * lists a single exception class for the whole module. `errorCode`:
 * 'not-editable' | 'wrong-status' | 'not-authorized' | 'not-reviewer' |
 * 'not-participant' | 'not-chairman' | 'already-decided' |
 * 'already-signed' | 'quorum-not-met' | 'not-body-member' | 'cert-invalid'.
 * Rendered as a 422 `{ code, message }` response — see `bootstrap/app.php`.
 */
class ProtocolValidationException extends Exception
{
    /**
     * Named `errorCode` (not `code`) — PHP's base `Exception` already
     * declares a non-readonly `$code` property, so promoting a readonly
     * `$code` here would collide with it.
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Protocol validation failed: {$errorCode}");
    }
}
