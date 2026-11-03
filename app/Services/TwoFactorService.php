<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use Throwable;

/**
 * TwoFactorService
 *
 * Implements RFC 6238 Time-Based One-Time Password (TOTP) algorithm,
 * Base32 secret generation, otpauth:// URI formatting, and window-tolerant verification.
 */
class TwoFactorService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DEFAULT_PERIOD = 30; // 30-second standard step
    private const DEFAULT_DIGITS = 6;
    private const DEFAULT_ALGO = 'sha1';

    private string $issuer;

    public function __construct(?string $issuer = null)
    {
        $this->issuer = $issuer ?? (string) config('auth.two_factor_issuer', 'SolanaUndoEscrow');
    }

    // =========================================================================
    // 1. Secret Generation (RFC 4648 Base32)
    // =========================================================================

    /**
     * Generate an RFC 4648 compliant Base32 secret key.
     * Default 20 bytes (160 bits) yields exactly 32 Base32 characters.
     */
    public function generateSecret(int $byteLength = 20): string
    {
        $randomBytes = random_bytes($byteLength);
        return $this->base32Encode($randomBytes);
    }

    /**
     * Encode binary string into RFC 4648 Base32 string (without padding).
     */
    public function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binaryString = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $binaryString .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Split into 5-bit chunks
        $chunks = str_split($binaryString, 5);
        $encoded = '';

        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Decode RFC 4648 Base32 string back into raw binary bytes.
     *
     * @throws InvalidArgumentException
     */
    public function base32Decode(string $base32): string
    {
        // Strip spaces, dashes, and padding
        $clean = strtoupper(str_replace([' ', '-', '='], '', trim($base32)));
        if ($clean === '') {
            return '';
        }

        $binaryString = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $char = $clean[$i];
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                throw new InvalidArgumentException("Invalid character in Base32 secret: '{$char}'");
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        // Split into 8-bit bytes
        $bytes = str_split($binaryString, 8);
        $output = '';

        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr((int) bindec($byte));
            }
        }

        return $output;
    }

    // =========================================================================
    // 2. TOTP Generation & Verification (RFC 6238 / RFC 4226)
    // =========================================================================

    /**
     * Calculate TOTP code for a given secret at a specific Unix timestamp.
     */
    public function getCode(string $secret, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();
        $secretBytes = $this->base32Decode($secret);

        // Counter = floor(timestamp / period)
        $timeCounter = (int) floor($time / self::DEFAULT_PERIOD);

        // Convert counter to 8-byte big-endian binary
        $timeBytes = pack('N*', 0, $timeCounter);

        // Compute HMAC
        $hmac = hash_hmac(self::DEFAULT_ALGO, $timeBytes, $secretBytes, true);

        // Dynamic truncation (RFC 4226 Section 5.4)
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::DEFAULT_DIGITS);

        return str_pad((string) $otp, self::DEFAULT_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify user-supplied TOTP code against secret with window tolerance.
     * Tolerance 1 allows: previous window (-30s), current window, next window (+30s).
     *
     * @param string $secret Base32 TOTP secret
     * @param string $code 6-digit code submitted by user
     * @param int $window Window tolerance steps (default 1 = +/- 30 seconds)
     * @param int|null $timestamp Optional reference timestamp
     * @return bool
     */
    public function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $cleanCode = trim(str_replace([' ', '-'], '', $code));
        if (strlen($cleanCode) !== self::DEFAULT_DIGITS || !ctype_digit($cleanCode)) {
            return false;
        }

        // Try Composer robthree/twofactorauth if loaded
        if (class_exists(\RobThree\Auth\TwoFactorAuth::class)) {
            try {
                $tfa = new \RobThree\Auth\TwoFactorAuth($this->issuer);
                if ($tfa->verifyCode($secret, $cleanCode, $window, $timestamp)) {
                    return true;
                }
            } catch (Throwable) {
                // Fall back to native implementation
            }
        }

        $now = $timestamp ?? time();

        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $now + ($i * self::DEFAULT_PERIOD);
            try {
                $validCode = $this->getCode($secret, $checkTime);
                if (hash_equals($validCode, $cleanCode)) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    // =========================================================================
    // 3. QR Code & URI Generation (otpauth://)
    // =========================================================================

    /**
     * Generate standard otpauth:// URI for mobile authenticator apps.
     */
    public function getOtpAuthUri(string $secret, string $accountName, ?string $issuer = null): string
    {
        $issuerName = $issuer ?? $this->issuer;
        $cleanSecret = strtoupper(trim(str_replace([' ', '-'], '', $secret)));
        $encodedIssuer = rawurlencode($issuerName);
        $encodedAccount = rawurlencode($accountName);

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            $encodedIssuer,
            $encodedAccount,
            $cleanSecret,
            $encodedIssuer,
            strtoupper(self::DEFAULT_ALGO),
            self::DEFAULT_DIGITS,
            self::DEFAULT_PERIOD
        );
    }

    /**
     * Get a public QR code image URL for displaying to the user in dashboard/setup modal.
     */
    public function getQrCodeUrl(string $secret, string $accountName, ?string $issuer = null): string
    {
        $otpauth = $this->getOtpAuthUri($secret, $accountName, $issuer);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . rawurlencode($otpauth);
    }

    /**
     * Set the application/issuer name.
     */
    public function setIssuer(string $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    /**
     * Get current issuer name.
     */
    public function getIssuer(): string
    {
        return $this->issuer;
    }
}
