<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Session Model
 *
 * Persists user session records in MySQL for session management across devices.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $expires_at
 * @property string $created_at
 * @property string $last_activity
 */
class Session extends Model
{
    protected static string $table = 'sessions';
    protected static string $primaryKey = 'id';

    protected static array $fillable = [
        'id',
        'user_id',
        'data',
        'ip_address',
        'user_agent',
        'expires_at',
    ];

    /**
     * Create or touch a session.
     */
    public static function createOrUpdate(string $id, int $userId, int $lifetimeSeconds, ?string $ip = null, ?string $ua = null): self
    {
        $db = static::getDb();
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeSeconds);

        $db->execute(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE 
                user_id = VALUES(user_id),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                expires_at = VALUES(expires_at),
                last_activity = CURRENT_TIMESTAMP",
            [$id, $userId, $ip, $ua, $expiresAt]
        );

        return static::find($id) ?? new static([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'expires_at' => $expiresAt,
        ], true);
    }

    /**
     * Purge all expired sessions.
     */
    public static function cleanExpired(): int
    {
        return static::getDb()->execute("DELETE FROM sessions WHERE expires_at < NOW()");
    }

    /**
     * Terminate all sessions for a specific user (e.g. on password change).
     */
    public static function destroyForUser(int $userId): int
    {
        return static::getDb()->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
    }
}
