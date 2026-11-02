<?php

namespace App\Support;

use RuntimeException;

/**
 * Keyed hash ("blind index") so encrypted values such as NIC numbers can be
 * looked up by exact match / checked for duplicates without decryption.
 * Uses a key separate from APP_KEY so rotating one does not expose the other.
 */
class BlindIndex
{
    public static function hash(?string $value, string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = config('portal.blind_index_key');

        if (blank($key)) {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured.');
        }

        return hash_hmac('sha256', $context.'|'.$value, $key);
    }

    /** Sri Lankan NIC: old format 9 digits + V/X, new format 12 digits. */
    public static function normalizeNic(?string $nic): ?string
    {
        if ($nic === null) {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '', $nic));
    }

    public static function nic(?string $nic): ?string
    {
        return self::hash(self::normalizeNic($nic), 'nic');
    }

    public static function isValidNic(?string $nic): bool
    {
        return (bool) preg_match('/^(\d{9}[VX]|\d{12})$/', (string) self::normalizeNic($nic));
    }
}
