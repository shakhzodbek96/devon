<?php

namespace App\Exceptions;

use Exception;

/**
 * Business-rule violation for conference-room booking mutations
 * (PLAN_conference-room.md §3). `errorCode` matches the frontend's
 * planned `ConferenceBookingValidationCode`: 'time-conflict' |
 * 'invalid-room' | 'room-archived' | 'invalid-range'. Rendered as a 422
 * `{ code, message }` response — see `bootstrap/app.php`.
 */
class ConferenceBookingValidationException extends Exception
{
    /**
     * Named `errorCode` (not `code`) — PHP's base `Exception` already
     * declares a non-readonly `$code` property, so promoting a readonly
     * `$code` here would collide with it.
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Conference booking validation failed: {$errorCode}");
    }
}
