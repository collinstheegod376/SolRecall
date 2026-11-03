<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Utils\Base58;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * KeyManagementService
 *
 * Manages custodial Solana Ed25519 keypairs, Base58 encoding/decoding,
 * AES-256-GCM authenticated encryption/decryption with master platform key,
 * signing key resolution for cron/broadcaster, and secure private key export
 * with 2FA enforcement and audit logging.
 */
class KeyManagementService
{
    private const CIPHER_ALGO = 'aes-256-gcm';
    private const GCM_IV_LEN = 12; // 96-bit IV recommended by NIST
    private const GCM_TAG_LEN = 16; // 128-bit authentication tag

    private string $encryptionKey;
    private ?TwoFactorService $twoFactorService = null;

    public function __construct(?string $masterKey = null, ?TwoFactorService $twoFactorService = null)
    {
        $key = $masterKey;
        if ($key === null || $key === '') {
            $key = config('app.encryption_key');
        }
        if ($key === null || $key === '') {
            $key = config('app.key');
        }
        if ($key === null || $key === '') {
            $key = env('APP_ENCRYPTION_KEY') ?: env('APP_KEY');
        }
        if ($key === null || $key === '') {
            throw new RuntimeException('CRITICAL: APP_ENCRYPTION_KEY or APP_KEY environment variable is required for cryptographic operations.');
        }

        // Support Laravel-style base64: prefix
        if (str_starts_with((string) $key, 'base64:')) {
            $decoded = base64_decode(substr((string) $key, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                $this->encryptionKey = $decoded;
            } else {
                $this->encryptionKey = hash('sha256', (string) $key, true);
            }
        } elseif (is_string($key) && strlen($key) === 64 && ctype_xdigit($key)) {
            $this->encryptionKey = (string) hex2bin($key);
        } elseif (is_string($key) && strlen($key) === 32) {
            $this->encryptionKey = $key;
        } else {
            $this->encryptionKey = hash('sha256', (string) $key, true);
        }

        $this->twoFactorService = $twoFactorService;
    }

    private function getTwoFactorService(): TwoFactorService
    {
        if ($this->twoFactorService === null) {
            $this->twoFactorService = new TwoFactorService();
        }
        return $this->twoFactorService;
    }

    // =========================================================================
    // 1. Solana Ed25519 Keypair Generation
    // =========================================================================

    /**
     * Generate a new Solana custodial keypair.
     *
     * @return array{
     *     publicKey: string,
     *     secretKey: string,
     *     publicKeyBytes: string,
     *     secretKeyBytes: string,
     *     secretKeyArray: array<int>,
     *     encryptedSecretKey: string
     * }
     */
    public function generateSolanaKeypair(): array
    {
        if (function_exists('sodium_crypto_sign_seed_keypair')) {
            $seed = random_bytes(32);
            $keypair = sodium_crypto_sign_seed_keypair($seed);
            $secretKeyBinary = sodium_crypto_sign_secretkey($keypair); // 64 bytes
            $publicKeyBinary = sodium_crypto_sign_publickey($keypair); // 32 bytes
        } elseif (function_exists('sodium_crypto_sign_keypair')) {
            $keypair = sodium_crypto_sign_keypair();
            $secretKeyBinary = sodium_crypto_sign_secretkey($keypair);
            $publicKeyBinary = sodium_crypto_sign_publickey($keypair);
        } else {
            throw new RuntimeException('CRITICAL: ext-sodium is required for Solana Ed25519 keypair derivation.');
        }

        $publicKeyBase58 = Base58::encode($publicKeyBinary);
        $secretKeyBase58 = Base58::encode($secretKeyBinary);
        $secretKeyArray = array_values(unpack('C*', $secretKeyBinary));
        $encryptedSecretKey = $this->encrypt($secretKeyBase58);

        return [
            'publicKey' => $publicKeyBase58,
            'secretKey' => $secretKeyBase58,
            'publicKeyBytes' => $publicKeyBinary,
            'secretKeyBytes' => $secretKeyBinary,
            'secretKeyArray' => $secretKeyArray,
            'encryptedSecretKey' => $encryptedSecretKey,
        ];
    }

    /**
     * Alias for generateSolanaKeypair().
     */
    public function generateKeypair(): array
    {
        return $this->generateSolanaKeypair();
    }

    /**
     * Generate custodial wallet for new user registration.
     *
     * @return array{
     *     publicKey: string,
     *     encryptedPrivateKey: string
     * }
     */
    public function generateCustodialWallet(): array
    {
        $kp = $this->generateSolanaKeypair();
        return [
            'publicKey' => $kp['publicKey'],
            'encryptedPrivateKey' => $kp['encryptedSecretKey'],
        ];
    }

    // =========================================================================
    // 2. Base58 Helper Wrappers
    // =========================================================================

    public function encodeBase58(string $data): string
    {
        return Base58::encode($data);
    }

    public function decodeBase58(string $base58): string
    {
        return Base58::decode($base58);
    }

    public function isValidSolanaAddress(string $address): bool
    {
        $address = trim($address);
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
            return false;
        }
        try {
            $bytes = Base58::decode($address);
            return strlen($bytes) === 32;
        } catch (Throwable) {
            return false;
        }
    }

    // =========================================================================
    // 3. AES-256-GCM Encryption & Decryption
    // =========================================================================

    /**
     * Encrypt plaintext string using AES-256-GCM.
     * Output format: base64(iv . tag . ciphertext)
     */
    public function encrypt(string $plaintext, ?string $masterKey = null): string
    {
        $key = $masterKey !== null ? $this->deriveKey($masterKey) : $this->encryptionKey;
        $iv = random_bytes(self::GCM_IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM encryption failed: ' . openssl_error_string());
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Alias for encrypt().
     */
    public function encryptPrivateKey(string $secretKey, ?string $masterKey = null): string
    {
        return $this->encrypt($secretKey, $masterKey);
    }

    /**
     * Decrypt AES-256-GCM payload.
     * Supports both base64(iv . tag . ciphertext) and JSON format.
     */
    public function decrypt(string $payload, ?string $masterKey = null): string
    {
        $key = $masterKey !== null ? $this->deriveKey($masterKey) : $this->encryptionKey;

        // Check if JSON format
        if (str_starts_with(trim($payload), '{')) {
            $data = json_decode($payload, true);
            if (is_array($data) && !empty($data['iv']) && !empty($data['tag']) && !empty($data['ct'])) {
                $iv = base64_decode((string) $data['iv'], true);
                $tag = base64_decode((string) $data['tag'], true);
                $ciphertext = base64_decode((string) $data['ct'], true);

                if ($iv !== false && $tag !== false && $ciphertext !== false) {
                    $decrypted = openssl_decrypt(
                        $ciphertext,
                        self::CIPHER_ALGO,
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv,
                        $tag,
                        $data['aad'] ?? ''
                    );
                    if ($decrypted !== false) {
                        return $decrypted;
                    }
                }
            }
        }

        // Standard format: base64(iv . tag . ciphertext)
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new InvalidArgumentException('Invalid base64 payload for decryption.');
        }

        $minLen = self::GCM_IV_LEN + self::GCM_TAG_LEN;
        if (strlen($raw) < $minLen) {
            throw new InvalidArgumentException('Ciphertext payload is truncated.');
        }

        $iv = substr($raw, 0, self::GCM_IV_LEN);
        $tag = substr($raw, self::GCM_IV_LEN, self::GCM_TAG_LEN);
        $ciphertext = substr($raw, $minLen);

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new RuntimeException('AES-256-GCM decryption failed (tampered data or wrong key).');
        }

        return $decrypted;
    }

    /**
     * Alias for decrypt().
     */
    public function decryptPrivateKey(string $payload, ?string $masterKey = null): string
    {
        return $this->decrypt($payload, $masterKey);
    }

    private function deriveKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            return (string) hex2bin($key);
        }
        if (strlen($key) === 32) {
            return $key;
        }
        return hash('sha256', $key, true);
    }

    // =========================================================================
    // 4. Hot Wallet & Signing Key Resolution (for Cron Broadcaster)
    // =========================================================================

    /**
     * Resolve the signing private key for a transfer.
     * Checks user's custodial encrypted key first; if null, falls back to platform hot wallet.
     */
    public function resolveSigningKey(?string $userEncryptedKey): string
    {
        if ($userEncryptedKey !== null && trim($userEncryptedKey) !== '') {
            try {
                return $this->decrypt($userEncryptedKey);
            } catch (Throwable $e) {
                error_log("Failed to decrypt user custodial key: " . $e->getMessage());
            }
        }

        $hotWalletKey = (string) config('solana.hot_wallet_private_key', '');
        if ($hotWalletKey !== '') {
            return $hotWalletKey;
        }

        // Deterministic fallback hot wallet private key for testing / dev
        $devSeed = hash('sha256', 'undo-escrow-dev-hot-wallet-seed', true);
        if (function_exists('sodium_crypto_sign_seed_keypair')) {
            $devKeypair = sodium_crypto_sign_seed_keypair($devSeed);
            return Base58::encode(sodium_crypto_sign_secretkey($devKeypair));
        }

        return Base58::encode($devSeed . $devSeed);
    }

    /**
     * Get platform hot wallet public address.
     */
    public function getHotWalletAddress(): string
    {
        $configured = (string) config('solana.hot_wallet_pubkey', '');
        if ($configured !== '') {
            return $configured;
        }

        $signingKey = $this->resolveSigningKey(null);
        $binaryKey = Base58::decode($signingKey);
        if (strlen($binaryKey) === 64) {
            $pubkey = substr($binaryKey, 32, 32);
            return Base58::encode($pubkey);
        }

        return '11111111111111111111111111111111';
    }

    // =========================================================================
    // 5. Secure Private Key Export with 2FA & Audit Logging
    // =========================================================================

    /**
     * Securely export a user's custodial private key.
     *
     * @param User $user The authenticated user
     * @param string $totpCode TOTP verification code from authenticator app
     * @param string $ipAddress Request IP address for audit trail
     * @param string $userAgent Request User-Agent for audit trail
     * @return array{
     *     depositAddress: string,
     *     privateKeyBase58: string,
     *     privateKeyArray: array<int>,
     *     warning: string,
     *     exportedAt: string
     * }
     */
    public function exportUserPrivateKey(
        User $user,
        string $totpCode,
        string $ipAddress = '',
        string $userAgent = ''
    ): array {
        if (!$user->hasTwoFactor()) {
            throw new RuntimeException("Two-Factor Authentication (2FA) must be enabled on your account before exporting custodial private keys.");
        }

        $twoFactor = $this->getTwoFactorService();
        $isValid2FA = $twoFactor->verifyCode((string) $user->two_factor_secret, $totpCode);
        if (!$isValid2FA) {
            $this->logSecurityAudit((int) $user->id, 'PRIVATE_KEY_EXPORT_FAILED', [
                'reason' => 'Invalid 2FA code supplied',
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
            throw new RuntimeException("Invalid Two-Factor Authentication (2FA) code. Verification failed.");
        }

        if (empty($user->encrypted_private_key)) {
            throw new RuntimeException("No custodial keypair is associated with this account.");
        }

        $decrypted = $this->decrypt((string) $user->encrypted_private_key);

        if (strlen($decrypted) === 64) {
            $secretKeyBase58 = Base58::encode($decrypted);
            $secretKeyArray = array_values(unpack('C*', $decrypted));
        } else {
            $secretKeyBase58 = $decrypted;
            $decoded = Base58::decode($decrypted);
            $secretKeyArray = array_values(unpack('C*', $decoded));
        }

        $this->logSecurityAudit((int) $user->id, 'PRIVATE_KEY_EXPORTED', [
            'deposit_address' => $user->solana_deposit_address,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'depositAddress' => (string) $user->solana_deposit_address,
            'privateKeyBase58' => $secretKeyBase58,
            'privateKeyArray' => $secretKeyArray,
            'warning' => 'CRITICAL SECURITY NOTICE: Anyone who possesses this private key has irrevocable control over all funds in this address. Never disclose this key to platform support, third parties, or unverified software.',
            'exportedAt' => gmdate('c'),
        ];
    }

    /**
     * Record a high-priority security audit log event.
     */
    public function logSecurityAudit(int $userId, string $event, array $context = []): void
    {
        $logEntry = sprintf(
            "[%s] [SECURITY AUDIT] User ID: %d | Event: %s | Details: %s\n",
            gmdate('Y-m-d H:i:s'),
            $userId,
            $event,
            json_encode($context, JSON_UNESCAPED_SLASHES)
        );

        error_log($logEntry);

        $logDir = base_path('storage/logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $auditLogFile = $logDir . '/security_audit.log';
        @file_put_contents($auditLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
