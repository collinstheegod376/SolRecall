<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * User Model
 *
 * Represents a platform account. Supports email/password, Google OAuth,
 * external Solana wallet connection, encrypted custodial keypair, and 2FA.
 *
 * @property int $id
 * @property string|null $username
 * @property string|null $email
 * @property string|null $password_hash
 * @property string|null $google_id
 * @property string|null $wallet_address
 * @property string|null $solana_deposit_address
 * @property string|null $encrypted_private_key
 * @property string|null $two_factor_secret
 * @property int $two_factor_enabled
 * @property int $is_admin
 * @property string $created_at
 * @property string $updated_at
 */
class User extends Model
{
    protected static string $table = 'users';
    protected static string $primaryKey = 'id';

    protected static array $fillable = [
        'username',
        'email',
        'password_hash',
        'google_id',
        'wallet_address',
        'solana_deposit_address',
        'encrypted_private_key',
        'two_factor_secret',
        'two_factor_enabled',
        'is_admin',
    ];

    /**
     * Find user by email address.
     */
    public static function findByEmail(string $email): ?self
    {
        return static::firstWhere('email', strtolower(trim($email)));
    }

    /**
     * Find user by username.
     */
    public static function findByUsername(string $username): ?self
    {
        return static::firstWhere('username', trim($username));
    }

    /**
     * Find user by connected external wallet address.
     */
    public static function findByWallet(string $walletAddress): ?self
    {
        return static::firstWhere('wallet_address', trim($walletAddress));
    }

    /**
     * Find user by Google OAuth identifier.
     */
    public static function findByGoogleId(string $googleId): ?self
    {
        return static::firstWhere('google_id', trim($googleId));
    }

    /**
     * Find user by generated custodial deposit address.
     */
    public static function findByDepositAddress(string $depositAddress): ?self
    {
        return static::firstWhere('solana_deposit_address', trim($depositAddress));
    }

    /**
     * Verify plain-text password against stored bcrypt hash.
     */
    public function verifyPassword(string $password): bool
    {
        if (empty($this->password_hash)) {
            return false;
        }
        return password_verify($password, $this->password_hash);
    }

    /**
     * Hash and store new password.
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Check if 2FA TOTP is active for user.
     */
    public function hasTwoFactor(): bool
    {
        return (int) $this->two_factor_enabled === 1 && !empty($this->two_factor_secret);
    }

    /**
     * Check if user possesses administrator privileges.
     */
    public function isAdmin(): bool
    {
        return (int) $this->is_admin === 1;
    }

    /**
     * Get all token balances for this user.
     *
     * @return array<string, UserBalance> Keyed by token symbol (e.g. 'SOL' => UserBalance)
     */
    public function getBalances(): array
    {
        return UserBalance::getAllByUser((int) $this->id);
    }

    /**
     * Get or initialize balance record for a specific token.
     */
    public function getBalance(string $token): UserBalance
    {
        return UserBalance::getOrCreate((int) $this->id, $token);
    }

    /**
     * Get active pending transfers for this user.
     *
     * @return array<PendingTransfer>
     */
    public function getPendingTransfers(): array
    {
        return PendingTransfer::getPendingForUser((int) $this->id);
    }

    /**
     * Get all transfer history for this user.
     *
     * @return array<PendingTransfer>
     */
    public function getAllTransfers(int $limit = 50): array
    {
        return PendingTransfer::getAllForUser((int) $this->id, $limit);
    }

    /**
     * Get deposit history for this user.
     *
     * @return array<Deposit>
     */
    public function getDeposits(int $limit = 50): array
    {
        return Deposit::getByUser((int) $this->id, $limit);
    }

    /**
     * Return safe array representation excluding sensitive credentials.
     */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        unset(
            $data['password_hash'],
            $data['encrypted_private_key'],
            $data['two_factor_secret']
        );
        return $data;
    }
}
