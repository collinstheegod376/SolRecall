<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Database Singleton & Query Helper
 *
 * Manages PDO connection, atomic transactions, row-locking queries,
 * and parameterized SQL execution.
 */
class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private array $config;
    private int $transactionDepth = 0;

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('database', []);
    }

    /**
     * Prevent cloning of singleton instance.
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserializing of singleton instance.
     */
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton " . __CLASS__);
    }

    /**
     * Retrieve the database singleton instance.
     */
    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Reset instance (primarily for testing or reconnection).
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->pdo = null;
            self::$instance = null;
        }
    }

    /**
     * Obtain or lazily initialize the active PDO connection.
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Establish the PDO connection using stored configuration.
     */
    private function connect(): void
    {
        $driver = $this->config['driver'] ?? 'mysql';
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $database = $this->config['database'] ?? 'undo_transfers';
        $username = $this->config['username'] ?? 'root';
        $password = $this->config['password'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        $options = $this->config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $dsn = "sqlite:{$database}";
            unset($options[PDO::MYSQL_ATTR_INIT_COMMAND]);
        } elseif ($driver === 'pgsql') {
            $sslmode = $this->config['sslmode'] ?? 'prefer';
            $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}";
            unset($options[PDO::MYSQL_ATTR_INIT_COMMAND]);
        } else {
            $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";
            if (!isset($options[PDO::MYSQL_ATTR_INIT_COMMAND])) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
            if ($driver === 'sqlite') {
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
                $this->pdo->exec('PRAGMA journal_mode = WAL;');
            } elseif ($driver === 'pgsql') {
                $this->pdo->exec("SET NAMES 'UTF8';");
            }
        } catch (PDOException $e) {
            // If MySQL is unreachable in local environment, fall back to SQLite
            if ($driver === 'mysql' && (str_contains($e->getMessage(), '2002') || str_contains($e->getMessage(), 'refused'))) {
                $sqlitePath = dirname(__DIR__, 2) . '/database/undo_transfers.sqlite';
                $this->config['driver'] = 'sqlite';
                $this->config['database'] = $sqlitePath;
                $this->pdo = new PDO("sqlite:{$sqlitePath}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
                $this->pdo->exec('PRAGMA journal_mode = WAL;');
                $this->initializeSqliteSchema();
                return;
            }
            throw new RuntimeException("Database connection failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Initialize schema and seed data when using SQLite fallback.
     */
    private function initializeSqliteSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                email TEXT UNIQUE,
                password_hash TEXT,
                google_id TEXT,
                wallet_address TEXT,
                solana_deposit_address TEXT,
                encrypted_private_key TEXT,
                two_factor_secret TEXT,
                two_factor_enabled INTEGER DEFAULT 0,
                is_admin INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS user_balances (
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL,
                available NUMERIC NOT NULL DEFAULT 0,
                escrow NUMERIC NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, token),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS pending_transfers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL,
                amount NUMERIC NOT NULL,
                destination TEXT NOT NULL,
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                status TEXT DEFAULT 'pending',
                tx_signature TEXT,
                cancelled_at DATETIME,
                processed_at DATETIME,
                failure_reason TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS deposits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL,
                amount NUMERIC NOT NULL,
                tx_signature TEXT,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                confirmed_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                data TEXT,
                ip_address TEXT,
                user_agent TEXT,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Seed initial data if users table is empty
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) {
            $this->pdo->exec("
                INSERT INTO users (id, username, email, password_hash, wallet_address, solana_deposit_address, two_factor_enabled, is_admin)
                VALUES 
                (1, 'demo_user', 'demo@solanaundo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU', '4Nd1mBQtrMJVYVfKf2PJy9NZWMdEcES25HozHP84TX35', 0, 0),
                (2, 'admin', 'admin@solanaundo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'DYw8jCTfwHNRJhhmFcbXvVDTqWMEVFBX6ZKUmG5CNSKK', '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM', 0, 1);

                INSERT INTO user_balances (user_id, token, available, escrow) VALUES 
                (1, 'SOL',  5.25000000, 0.50000000),
                (1, 'USDC', 250.00000000, 25.00000000),
                (1, 'USDT', 150.00000000, 0.00000000),
                (2, 'SOL',  50.00000000, 0.00000000),
                (2, 'USDC', 2500.00000000, 0.00000000),
                (2, 'USDT', 1500.00000000, 0.00000000);
            ");
        }
    }

    /**
     * Begin an atomic database transaction (with nested savepoint support).
     */
    public function beginTransaction(): bool
    {
        $pdo = $this->getPdo();
        if ($this->transactionDepth === 0) {
            $result = $pdo->beginTransaction();
            $this->transactionDepth = 1;
            return $result;
        }

        $this->transactionDepth++;
        $pdo->exec("SAVEPOINT trans_{$this->transactionDepth}");
        return true;
    }

    /**
     * Commit the current transaction (or release savepoint).
     */
    public function commit(): bool
    {
        $pdo = $this->getPdo();
        if ($this->transactionDepth === 0) {
            return false;
        }

        if ($this->transactionDepth === 1) {
            $result = $pdo->commit();
            $this->transactionDepth = 0;
            return $result;
        }

        $pdo->exec("RELEASE SAVEPOINT trans_{$this->transactionDepth}");
        $this->transactionDepth--;
        return true;
    }

    /**
     * Roll back the transaction (or roll back to savepoint).
     */
    public function rollBack(): bool
    {
        $pdo = $this->getPdo();
        if ($this->transactionDepth === 0) {
            return false;
        }

        if ($this->transactionDepth === 1) {
            $result = $pdo->rollBack();
            $this->transactionDepth = 0;
            return $result;
        }

        $pdo->exec("ROLLBACK TO SAVEPOINT trans_{$this->transactionDepth}");
        $this->transactionDepth--;
        return true;
    }

    /**
     * Check if currently within a database transaction.
     */
    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    /**
     * Execute a callback inside an atomic transaction.
     * Automatically commits on success and rolls back on exception.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Prepare and execute a parameterized query, returning the statement.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Execute an INSERT, UPDATE, or DELETE query and return the number of affected rows.
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Fetch a single row as an associative array.
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch all matching rows as an array of associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single column value from the first row.
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = $this->query($sql, $params);
        $val = $stmt->fetchColumn($column);
        return $val !== false ? $val : null;
    }

    /**
     * Retrieve the last inserted ID.
     */
    public function lastInsertId(?string $name = null): string|int
    {
        $id = $this->getPdo()->lastInsertId($name);
        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * Execute a row-locking query (SELECT ... FOR UPDATE) for a single record.
     * Note: Must be executed within an active transaction.
     */
    public function selectForUpdate(string $sql, array $params = []): ?array
    {
        $driver = $this->config['driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            $sql = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql);
        } elseif (!str_contains(strtoupper($sql), 'FOR UPDATE')) {
            $sql = rtrim($sql, ';') . ' FOR UPDATE';
        }
        return $this->fetch($sql, $params);
    }

    /**
     * Execute a row-locking query (SELECT ... FOR UPDATE) for multiple records.
     * Note: Must be executed within an active transaction.
     */
    public function selectAllForUpdate(string $sql, array $params = []): array
    {
        $driver = $this->config['driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            $sql = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql);
        } elseif (!str_contains(strtoupper($sql), 'FOR UPDATE')) {
            $sql = rtrim($sql, ';') . ' FOR UPDATE';
        }
        return $this->fetchAll($sql, $params);
    }
}
