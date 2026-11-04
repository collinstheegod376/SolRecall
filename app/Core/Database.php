<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Pure Supabase Database Singleton & Query Engine
 *
 * Dedicated PostgreSQL driver for Supabase transaction pooler and direct connections.
 * Enforces atomic transactions, row-locking queries (FOR UPDATE), and parameterized execution.
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
     * Establish the PDO connection to Supabase PostgreSQL.
     */
    private function connect(): void
    {
        $driver = $this->config['driver'] ?? 'pgsql';
        $options = $this->config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // CRITICAL FOR SUPABASE POOLER (PORT 6543):
            // Emulate prepares must be true so PgBouncer/Supavisor doesn't throw
            // "prepared statement pdo_stmt_... does not exist" when pooler switches connections.
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => true,
        ];

        if ($driver === 'sqlite') {
            $database = $this->config['database'] ?? ':memory:';
            $this->pdo = new PDO("sqlite:{$database}", null, null, $options);
            return;
        }

        $host = $this->config['host'] ?? 'aws-0-eu-west-1.pooler.supabase.com';
        $port = (int) ($this->config['port'] ?? 6543);
        $database = $this->config['database'] ?? 'postgres';
        $username = $this->config['username'] ?? 'postgres.ouwxjoqredxaacdqmsht';
        $password = $this->config['password'] ?? '';
        $sslmode = $this->config['sslmode'] ?? 'require';

        // Format PostgreSQL DSN for Supabase
        $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}";

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
            $this->pdo->exec("SET NAMES 'UTF8';");
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Supabase PostgreSQL database connection failed: " . $e->getMessage() . 
                " [Host: {$host}:{$port}, Database: {$database}, User: {$username}]. " .
                "Please verify your Supabase DB_PASSWORD in .env or Vercel dashboard.",
                (int) $e->getCode(),
                $e
            );
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
     * Get the active database driver ('pgsql' for Supabase).
     */
    public function getDriver(): string
    {
        return 'pgsql';
    }

    /**
     * Escape and quote an identifier (table or column name) safely in PostgreSQL syntax.
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Execute a row-locking query (SELECT ... FOR UPDATE) for a single record.
     * Note: Must be executed within an active transaction.
     */
    public function selectForUpdate(string $sql, array $params = []): ?array
    {
        if (($this->config['driver'] ?? 'pgsql') !== 'sqlite' && !str_contains(strtoupper($sql), 'FOR UPDATE')) {
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
        if (($this->config['driver'] ?? 'pgsql') !== 'sqlite' && !str_contains(strtoupper($sql), 'FOR UPDATE')) {
            $sql = rtrim($sql, ';') . ' FOR UPDATE';
        }
        return $this->fetchAll($sql, $params);
    }
}
