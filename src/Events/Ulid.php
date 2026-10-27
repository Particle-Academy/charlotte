<?php

declare(strict_types=1);

namespace Charlotte\Events;

/** A 26-character Crockford ULID: 48-bit millisecond time, 80 random bits. */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $milliseconds = null): string
    {
        $time = $milliseconds ?? (int) floor(microtime(true) * 1000);
        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $out .= self::ALPHABET[intdiv($time, 32 ** $i) % 32];
        }
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $value) === 1;
    }
}
