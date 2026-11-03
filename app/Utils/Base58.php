<?php

declare(strict_types=1);

namespace App\Utils;

use InvalidArgumentException;

/**
 * Pure PHP Base58 (Bitcoin/Solana Alphabet) Encoder & Decoder
 *
 * Solana addresses, private keys, and transaction signatures use
 * Bitcoin Base58 alphabet: 123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz
 */
class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * Encode binary string to Base58 string.
     */
    public static function encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        // Count leading zero bytes
        $origLen = strlen($binary);
        $leaderCount = 0;
        while ($leaderCount < $origLen && $binary[$leaderCount] === "\x00") {
            $leaderCount++;
        }

        // Fast path with GMP extension
        if (extension_loaded('gmp')) {
            $hex = bin2hex($binary);
            $num = gmp_init($hex, 16);
            $encoded = '';
            $base = gmp_init(58, 10);
            while (gmp_cmp($num, 0) > 0) {
                [$num, $rem] = gmp_div_qr($num, $base);
                $encoded = self::ALPHABET[gmp_intval($rem)] . $encoded;
            }
            return str_repeat('1', $leaderCount) . $encoded;
        }

        // Fast path with BCMath
        if (function_exists('bcadd')) {
            $decimal = '0';
            for ($i = 0; $i < $origLen; $i++) {
                $byteVal = (string) ord($binary[$i]);
                $decimal = bcadd(bcmul($decimal, '256'), $byteVal);
            }

            $result = '';
            while (bccomp($decimal, '0') > 0) {
                $rem = (int) bcmod($decimal, '58');
                $decimal = bcdiv($decimal, '58', 0);
                $result = self::ALPHABET[$rem] . $result;
            }

            return str_repeat('1', $leaderCount) . $result;
        }

        // Portable pure PHP byte-array division
        $bytes = array_values(unpack('C*', $binary));
        $encoded = '';
        while (!empty($bytes)) {
            $remainder = 0;
            $quotient = [];
            foreach ($bytes as $byte) {
                $accumulator = ($remainder << 8) + $byte;
                $digit = intdiv($accumulator, 58);
                $remainder = $accumulator % 58;
                if (!empty($quotient) || $digit > 0) {
                    $quotient[] = $digit;
                }
            }
            $encoded = self::ALPHABET[$remainder] . $encoded;
            $bytes = $quotient;
        }

        return str_repeat('1', $leaderCount) . $encoded;
    }

    /**
     * Decode Base58 string to raw binary string.
     */
    public static function decode(string $base58): string
    {
        $base58 = trim($base58);
        if ($base58 === '') {
            return '';
        }

        $base58Len = strlen($base58);
        $leaderCount = 0;
        while ($leaderCount < $base58Len && $base58[$leaderCount] === '1') {
            $leaderCount++;
        }

        // Fast path with GMP extension
        if (extension_loaded('gmp')) {
            $num = gmp_init(0, 10);
            $base = gmp_init(58, 10);
            for ($i = 0; $i < $base58Len; $i++) {
                $char = $base58[$i];
                $pos = strpos(self::ALPHABET, $char);
                if ($pos === false) {
                    throw new InvalidArgumentException("Invalid Base58 character: '{$char}'");
                }
                $num = gmp_add(gmp_mul($num, $base), gmp_init($pos, 10));
            }
            $hex = gmp_strval($num, 16);
            if (strlen($hex) % 2 !== 0) {
                $hex = '0' . $hex;
            }
            $bin = $hex !== '' && $hex !== '00' ? (hex2bin($hex) ?: '') : '';
            return str_repeat("\x00", $leaderCount) . $bin;
        }

        // Fast path with BCMath
        if (function_exists('bcadd')) {
            $decimal = '0';
            for ($i = 0; $i < $base58Len; $i++) {
                $char = $base58[$i];
                $pos = strpos(self::ALPHABET, $char);
                if ($pos === false) {
                    throw new InvalidArgumentException("Invalid Base58 character: '{$char}'");
                }
                $decimal = bcadd(bcmul($decimal, '58'), (string) $pos);
            }

            $bytes = '';
            while (bccomp($decimal, '0') > 0) {
                $byte = (int) bcmod($decimal, '256');
                $decimal = bcdiv($decimal, '256', 0);
                $bytes = chr($byte) . $bytes;
            }

            return str_repeat("\x00", $leaderCount) . $bytes;
        }

        // Portable pure PHP byte-array decoding
        $bytes = [0];
        for ($i = 0; $i < $base58Len; $i++) {
            $char = $base58[$i];
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException("Invalid Base58 character: '{$char}'");
            }

            $carry = $value;
            $bCount = count($bytes);
            for ($j = 0; $j < $bCount; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $binary = pack('C*', ...array_reverse($bytes));
        $trimmed = ltrim($binary, "\x00");

        return str_repeat("\x00", $leaderCount) . $trimmed;
    }

    /**
     * Check if a string is a valid Base58 string.
     */
    public static function isValid(string $string): bool
    {
        $string = trim($string);
        if ($string === '') {
            return false;
        }
        for ($i = 0, $len = strlen($string); $i < $len; $i++) {
            if (strpos(self::ALPHABET, $string[$i]) === false) {
                return false;
            }
        }
        return true;
    }
}
