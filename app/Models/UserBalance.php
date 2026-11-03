<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use RuntimeException;

/**
 * UserBalance Model
 *
 * Tracks multi-token available and escrow ledger balances for SOL, USDC, and USDT.
 * Provides atomic, race-condition safe balance movement operations.
 *
 * @property int $user_id
 * @property string $token
 * @property string $available
 * @property string $escrow
 * @property string $updated_at
 */
class UserBalance extends Model
{
    protected static string $table = 'user_balances';
    protected static string $primaryKey = 'user_id'; // Note: Composite key (user_id, token) handled via custom logic

    protected static array $fillable = [
        'user_id',
        'token',
        'available',
        'escrow',
    ];

    /**
     * Retrieve balance record for given user and token symbol.
     */
    public static function getByUserAndToken(int $userId, string $token): ?self
    {
        $token = strtoupper(trim($token));
        $row = static::getDb()->fetch(
            "SELECT * FROM user_balances WHERE user_id = ? AND token = ? LIMIT 1",
            [$userId, $token]
        );
        return $row ? new static($row, true) : null;
    }

    /**
     * Retrieve and lock balance record for update (SELECT ... FOR UPDATE).
     * Must be called within an active transaction.
     */
    public static function lockByUserAndToken(int $userId, string $token): ?self
    {
        $token = strtoupper(trim($token));
        $row = static::getDb()->selectForUpdate(
            "SELECT * FROM user_balances WHERE user_id = ? AND token = ? LIMIT 1",
            [$userId, $token]
        );
        return $row ? new static($row, true) : null;
    }

    /**
     * Retrieve all balances for a user, keyed by token symbol.
     *
     * @return array<string, self>
     */
    public static function getAllByUser(int $userId): array
    {
        $rows = static::getDb()->fetchAll(
            "SELECT * FROM user_balances WHERE user_id = ? ORDER BY token ASC",
            [$userId]
        );

        $balances = [];
        foreach ($rows as $row) {
            $balances[$row['token']] = new static($row, true);
        }

        // Guarantee keys exist for default supported tokens
        $supported = ['SOL', 'USDC', 'USDT'];
        foreach ($supported as $token) {
            if (!isset($balances[$token])) {
                $balances[$token] = static::getOrCreate($userId, $token);
            }
        }

        return $balances;
    }

    /**
     * Get existing balance or initialize record with 0.00000000.
     */
    public static function getOrCreate(int $userId, string $token): self
    {
        $token = strtoupper(trim($token));
        $balance = static::getByUserAndToken($userId, $token);
        if ($balance !== null) {
            return $balance;
        }

        $db = static::getDb();
        $driver = method_exists($db, 'getDriver') ? $db->getDriver() : 'mysql';

        if ($driver === 'sqlite') {
            $sql = "INSERT OR IGNORE INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, 0.00000000, 0.00000000)";
        } elseif ($driver === 'pgsql') {
            $sql = "INSERT INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, 0.00000000, 0.00000000) ON CONFLICT (user_id, token) DO NOTHING";
        } else {
            $sql = "INSERT IGNORE INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, 0.00000000, 0.00000000)";
        }

        try {
            $db->execute($sql, [$userId, $token]);
        } catch (\Throwable) {
            // Concurrent insert or conflict is safe to ignore
        }

        return static::getByUserAndToken($userId, $token) ?? new static([
            'user_id' => $userId,
            'token' => $token,
            'available' => '0.00000000',
            'escrow' => '0.00000000',
        ], true);
    }

    /**
     * Credit available balance (e.g. from confirmed deposit).
     */
    public function creditAvailable(float|string $amount): bool
    {
        $amount = (string) $amount;
        $db = static::getDb();

        $rows = $db->execute(
            "UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?",
            [$amount, $this->user_id, $this->token]
        );

        if ($rows > 0) {
            $this->refreshComposite();
            return true;
        }

        return false;
    }

    /**
     * Debit available balance (e.g. for immediate non-undo transfer or withdrawal).
     */
    public function debitAvailable(float|string $amount): bool
    {
        $amount = (string) $amount;
        $db = static::getDb();

        $rows = $db->execute(
            "UPDATE user_balances SET available = available - ? WHERE user_id = ? AND token = ? AND available >= ?",
            [$amount, $this->user_id, $this->token, $amount]
        );

        if ($rows > 0) {
            $this->refreshComposite();
            return true;
        }

        return false;
    }

    /**
     * Move funds from available to escrow (undo hold initiated).
     * Atomic statement prevents race conditions and overdrafts.
     */
    public function moveToEscrow(float|string $amount): bool
    {
        $amount = (string) $amount;
        $db = static::getDb();

        $rows = $db->execute(
            "UPDATE user_balances 
             SET available = available - ?, escrow = escrow + ? 
             WHERE user_id = ? AND token = ? AND available >= ?",
            [$amount, $amount, $this->user_id, $this->token, $amount]
        );

        if ($rows > 0) {
            $this->refreshComposite();
            return true;
        }

        return false;
    }

    /**
     * Release funds from escrow back to available (user cancelled transfer).
     */
    public function releaseFromEscrow(float|string $amount): bool
    {
        $amount = (string) $amount;
        $db = static::getDb();

        $rows = $db->execute(
            "UPDATE user_balances 
             SET escrow = escrow - ?, available = available + ? 
             WHERE user_id = ? AND token = ? AND escrow >= ?",
            [$amount, $amount, $this->user_id, $this->token, $amount]
        );

        if ($rows > 0) {
            $this->refreshComposite();
            return true;
        }

        return false;
    }

    /**
     * Burn escrow funds upon successful on-chain transaction broadcast.
     */
    public function burnEscrow(float|string $amount): bool
    {
        $amount = (string) $amount;
        $db = static::getDb();

        $rows = $db->execute(
            "UPDATE user_balances 
             SET escrow = escrow - ? 
             WHERE user_id = ? AND token = ? AND escrow >= ?",
            [$amount, $this->user_id, $this->token, $amount]
        );

        if ($rows > 0) {
            $this->refreshComposite();
            return true;
        }

        return false;
    }

    /**
     * Reload state from database using composite key.
     */
    public function refreshComposite(): static
    {
        $row = static::getDb()->fetch(
            "SELECT * FROM user_balances WHERE user_id = ? AND token = ? LIMIT 1",
            [$this->user_id, $this->token]
        );

        if ($row) {
            $this->attributes = $row;
            $this->original = $row;
        }

        return $this;
    }

    /**
     * Calculate total balance (available + escrow).
     */
    public function getTotal(): string
    {
        return bcadd((string) $this->available, (string) $this->escrow, 8);
    }
}
