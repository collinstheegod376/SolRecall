<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;
use RuntimeException;

/**
 * PendingTransfer Model
 *
 * Represents an outgoing transfer held in the temporary escrow hold window.
 * Supports countdown tracking, user cancellations, and cron broadcast processing.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $amount
 * @property string $destination
 * @property string|null $note
 * @property string $created_at
 * @property string $expires_at
 * @property string $status
 * @property string|null $tx_signature
 * @property string|null $cancelled_at
 * @property string|null $processed_at
 * @property string|null $failure_reason
 */
class PendingTransfer extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    protected static string $table = 'pending_transfers';
    protected static string $primaryKey = 'id';

    protected static array $fillable = [
        'user_id',
        'token',
        'amount',
        'destination',
        'note',
        'expires_at',
        'status',
        'tx_signature',
        'cancelled_at',
        'processed_at',
        'failure_reason',
    ];

    /**
     * Get active pending transfers for a specific user.
     *
     * @return array<self>
     */
    public static function getPendingForUser(int $userId): array
    {
        return static::query(
            "SELECT * FROM pending_transfers 
             WHERE user_id = ? AND status = ? 
             ORDER BY expires_at ASC",
            [$userId, self::STATUS_PENDING]
        );
    }

    /**
     * Get all transfer history for a specific user.
     *
     * @return array<self>
     */
    public static function getAllForUser(int $userId, int $limit = 50): array
    {
        return static::query(
            "SELECT * FROM pending_transfers 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Get all active pending transfers across all users (admin view).
     *
     * @return array<self>
     */
    public static function getAllPending(int $limit = 100): array
    {
        return static::query(
            "SELECT * FROM pending_transfers 
             WHERE status = ? 
             ORDER BY expires_at ASC 
             LIMIT ?",
            [self::STATUS_PENDING, $limit]
        );
    }

    /**
     * Retrieve expired pending transfers ready for on-chain broadcast.
     *
     * @return array<self>
     */
    public static function getExpiredPending(int $limit = 50): array
    {
        return static::query(
            "SELECT * FROM pending_transfers 
             WHERE status = ? AND expires_at <= NOW() 
             ORDER BY expires_at ASC 
             LIMIT ?",
            [self::STATUS_PENDING, $limit]
        );
    }

    /**
     * Atomically lock a pending transfer for processing.
     * Must be executed within an active database transaction.
     */
    public static function lockForProcessing(int $id): ?self
    {
        $row = static::getDb()->selectForUpdate(
            "SELECT * FROM pending_transfers WHERE id = ? AND status = ? LIMIT 1",
            [$id, self::STATUS_PENDING]
        );

        return $row ? new static($row, true) : null;
    }

    /**
     * Check if the transfer is currently in pending status.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the transfer hold window has expired.
     */
    public function isExpired(): bool
    {
        return strtotime($this->expires_at) <= time();
    }

    /**
     * Check if user is eligible to cancel this transfer.
     */
    public function isCancellable(): bool
    {
        return $this->isPending() && !$this->isExpired();
    }

    /**
     * Calculate remaining time in seconds for the undo window.
     */
    public function getRemainingSeconds(): int
    {
        $remaining = strtotime($this->expires_at) - time();
        return max(0, $remaining);
    }

    /**
     * Total undo duration in seconds.
     */
    public function getTotalDurationSeconds(): int
    {
        $start = strtotime($this->created_at);
        $end = strtotime($this->expires_at);
        return max(1, $end - $start);
    }

    /**
     * Calculate elapsed progress percentage (0.0 to 100.0).
     */
    public function getProgressPercent(): float
    {
        $total = $this->getTotalDurationSeconds();
        $remaining = $this->getRemainingSeconds();
        $elapsed = $total - $remaining;
        return round(min(100.0, max(0.0, ($elapsed / $total) * 100.0)), 2);
    }

    /**
     * Cancel the pending transfer and release funds back to available balance.
     * Guaranteed atomic via transaction.
     */
    public function cancel(): bool
    {
        if (!$this->isCancellable()) {
            return false;
        }

        $db = static::getDb();

        return $db->transaction(function () use ($db) {
            // Lock and update transfer
            $updated = $db->execute(
                "UPDATE pending_transfers 
                 SET status = ?, cancelled_at = NOW() 
                 WHERE id = ? AND status = ?",
                [self::STATUS_CANCELLED, $this->id, self::STATUS_PENDING]
            );

            if ($updated === 0) {
                return false;
            }

            // Return escrow balance back to available
            $balance = UserBalance::getByUserAndToken((int) $this->user_id, $this->token);
            if ($balance === null) {
                throw new RuntimeException("Balance record not found for user {$this->user_id} token {$this->token}");
            }

            $released = $balance->releaseFromEscrow($this->amount);
            if (!$released) {
                throw new RuntimeException("Failed to release {$this->amount} {$this->token} from escrow");
            }

            $this->status = self::STATUS_CANCELLED;
            $this->cancelled_at = date('Y-m-d H:i:s');
            return true;
        });
    }

    /**
     * Mark transfer as actively broadcasting on-chain.
     */
    public function markProcessing(): bool
    {
        $db = static::getDb();
        $updated = $db->execute(
            "UPDATE pending_transfers 
             SET status = ? 
             WHERE id = ? AND status = ?",
            [self::STATUS_PROCESSING, $this->id, self::STATUS_PENDING]
        );

        if ($updated > 0) {
            $this->status = self::STATUS_PROCESSING;
            return true;
        }

        return false;
    }

    /**
     * Mark transfer as successfully broadcast and confirmed on Solana.
     * Decreases escrow balance permanently.
     */
    public function markCompleted(string $txSignature): bool
    {
        $db = static::getDb();

        return $db->transaction(function () use ($db, $txSignature) {
            $updated = $db->execute(
                "UPDATE pending_transfers 
                 SET status = ?, tx_signature = ?, processed_at = NOW() 
                 WHERE id = ? AND status IN (?, ?)",
                [self::STATUS_COMPLETED, $txSignature, $this->id, self::STATUS_PROCESSING, self::STATUS_PENDING]
            );

            if ($updated === 0) {
                return false;
            }

            // Burn escrow balance
            $balance = UserBalance::getByUserAndToken((int) $this->user_id, $this->token);
            if ($balance !== null) {
                $balance->burnEscrow($this->amount);
            }

            $this->status = self::STATUS_COMPLETED;
            $this->tx_signature = $txSignature;
            $this->processed_at = date('Y-m-d H:i:s');
            return true;
        });
    }

    /**
     * Mark transfer as failed (e.g. RPC error or destination invalid).
     * Reverts escrow funds back to available.
     */
    public function markFailed(string $reason = ''): bool
    {
        $db = static::getDb();

        return $db->transaction(function () use ($db, $reason) {
            $updated = $db->execute(
                "UPDATE pending_transfers 
                 SET status = ?, failure_reason = ?, processed_at = NOW() 
                 WHERE id = ? AND status IN (?, ?)",
                [self::STATUS_FAILED, $reason, $this->id, self::STATUS_PROCESSING, self::STATUS_PENDING]
            );

            if ($updated === 0) {
                return false;
            }

            // Refund from escrow back to available
            $balance = UserBalance::getByUserAndToken((int) $this->user_id, $this->token);
            if ($balance !== null) {
                $balance->releaseFromEscrow($this->amount);
            }

            $this->status = self::STATUS_FAILED;
            $this->failure_reason = $reason;
            $this->processed_at = date('Y-m-d H:i:s');
            return true;
        });
    }
}
