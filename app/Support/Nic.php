<?php

namespace App\Support;

/**
 * Sri Lankan NIC helpers. The NIC encodes the holder's birth year
 * (old: 19YY + 3-digit day + …V/X; new: YYYY + 3-digit day + …).
 */
class Nic
{
    public static function birthYear(?string $nic): ?int
    {
        $nic = BlindIndex::normalizeNic($nic);

        return match (true) {
            $nic === null => null,
            (bool) preg_match('/^(\d{2})\d{7}[VX]$/', $nic, $m) => 1900 + (int) $m[1],
            (bool) preg_match('/^(\d{4})\d{8}$/', $nic, $m) => (int) $m[1],
            default => null,
        };
    }
}
