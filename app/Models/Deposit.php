<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use RuntimeException;

/**
 * Deposit Model
 *
 * Tracks incoming on-chain Solana transfers detected by RPC polling/webhooks.
 * Confirmed deposits automatically credit the user's available token balance.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $amount
 * @property string|null $tx_signature
 * @property string $status
 * @property string $created_at
 * @property string|null $confirmed_at
 */
class Deposit extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED    = 'failed';

    protected static string $table = 'deposits';
    protected static string $primaryKey = 'id';

    protected static array $fillable = [
        'user_id',
        'token',
        'amount',
        'tx_signature',
        'status',
        'confirmed_at',
    ];

    /**
     * Retrieve deposit history for a user.
     *
     * @return array<self>
     */
    public static function getByUser(int $userId, int $limit = 50): array
    {
        return static::query(
            "SELECT * FROM deposits WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Find deposit by Solana transaction signature.
     */
    public static function findByTxSignature(string $txSignature): ?self
    {
        return static::firstWhere('tx_signature', trim($txSignature));
    }

    /**
     * Retrieve pending deposits awaiting confirmation.
     *
     * @return array<self>
     */
    public static function getPendingDeposits(int $limit = 50): array
    {
        return static::query(
            "SELECT * FROM deposits WHERE status = ? ORDER BY created_at ASC LIMIT ?",
            [self::STATUS_PENDING, $limit]
        );
    }

    /**
     * Check if deposit is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Confirm deposit and credit the user's available balance.
     * Guaranteed atomic via transaction.
     */
    public function confirm(): bool
    {
        if ($this->isConfirmed()) {
            return true;
        }

        $db = static::getDb();

        return $db->transaction(function () use ($db) {
            $updated = $db->execute(
                "UPDATE deposits 
                 SET status = ?, confirmed_at = NOW() 
                 WHERE id = ? AND status = ?",
                [self::STATUS_CONFIRMED, $this->id, self::STATUS_PENDING]
            );

            if ($updated === 0) {
                return false;
            }

            // Credit available balance
            $balance = UserBalance::getOrCreate((int) $this->user_id, $this->token);
            $credited = $balance->creditAvailable($this->amount);

            if (!$credited) {
                throw new RuntimeException("Failed to credit {$this->amount} {$this->token} to user {$this->user_id}");
            }

            $this->status = self::STATUS_CONFIRMED;
            $this->confirmed_at = date('Y-m-d H:i:s');
            return true;
        });
    }

    /**
     * Mark deposit as failed.
     */
    public function fail(): bool
    {
        $db = static::getDb();
        $updated = $db->execute(
            "UPDATE deposits SET status = ? WHERE id = ? AND status = ?",
            [self::STATUS_FAILED, $this->id, self::STATUS_PENDING]
        );

        if ($updated > 0) {
            $this->status = self::STATUS_FAILED;
            return true;
        }

        return false;
    }
}
