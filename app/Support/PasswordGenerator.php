<?php

namespace App\Support;

/**
 * Generates a secure temporary password that always satisfies the shared
 * frontend/backend password policy: min 8 chars, upper + lower case,
 * digit, special char (PLAN_AUTH_ROLES.md §1.5).
 */
class PasswordGenerator
{
    public static function generate(int $length = 10): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%^&*-_+=';

        $all = $upper.$lower.$digits.$special;

        // Guarantee at least one of each required character class.
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($chars);

        return implode('', $chars);
    }
}
