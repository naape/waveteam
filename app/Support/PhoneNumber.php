<?php

namespace App\Support;

/**
 * Normalises phone numbers to E.164. Local Sri Lankan formats
 * (07X XXX XXXX, 7XXXXXXXX, 94…) become +94…; international numbers
 * entered with "+" or "00" are kept.
 */
class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $n = preg_replace('/[\s\-().]/', '', trim($input));

        if (str_starts_with($n, '00')) {
            $n = '+'.substr($n, 2);
        }

        if (! str_starts_with($n, '+')) {
            if (preg_match('/^0\d{9}$/', $n)) {        // 0771234567
                $n = '+94'.substr($n, 1);
            } elseif (preg_match('/^94\d{9}$/', $n)) { // 94771234567
                $n = '+'.$n;
            } elseif (preg_match('/^[1-9]\d{8}$/', $n)) { // 771234567
                $n = '+94'.$n;
            }
        }

        return $n;
    }

    public static function isValid(?string $input): bool
    {
        $n = self::normalize($input);

        if ($n === null || ! preg_match('/^\+[1-9]\d{7,14}$/', $n)) {
            return false;
        }

        // Sri Lankan numbers must have exactly 9 digits after +94.
        return ! str_starts_with($n, '+94') || preg_match('/^\+94\d{9}$/', $n) === 1;
    }
}
