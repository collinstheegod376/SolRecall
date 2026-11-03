<?php

declare(strict_types=1);

namespace App\Core;

use JsonSerializable;
use RuntimeException;

/**
 * Base Active Record Model
 *
 * Provides object-relational mapping, query utilities, dirty-tracking,
 * and CRUD operations backed by the PDO Database singleton.
 */
abstract class Model implements JsonSerializable
{
    /** @var string Table name associated with model */
    protected static string $table = '';

    /** @var string Primary key column name */
    protected static string $primaryKey = 'id';

    /** @var array<string> Whitelist of mass-assignable column attributes */
    protected static array $fillable = [];

    /** @var array<string, mixed> Current model attributes */
    protected array $attributes = [];

    /** @var array<string, mixed> Snapshot of original attributes for dirty-checking */
    protected array $original = [];

    /** @var bool Indicates if model exists in database */
    protected bool $exists = false;

    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->fill($attributes);
        if ($exists) {
            $this->original = $this->attributes;
            $this->exists = true;
        }
    }

    /**
     * Retrieve the Database singleton instance.
     */
    public static function getDb(): Database
    {
        return Database::getInstance();
    }

    /**
     * Resolve the table name for this model.
     */
    public static function getTableName(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        $class = (new \ReflectionClass(static::class))->getShortName();
        // Simple pluralization: User -> users, UserBalance -> user_balances
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
        return str_ends_with($snake, 's') ? $snake : $snake . 's';
    }

    /**
     * Resolve the primary key name.
     */
    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * Populate model attributes with an associative array.
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * Escape and quote an identifier using the active database driver.
     */
    protected static function quote(string $name): string
    {
        return static::getDb()->quoteIdentifier($name);
    }

    /**
     * Find a single record by primary key.
     */
    public static function find(mixed $id): ?static
    {
        $table = static::quote(static::getTableName());
        $pk = static::quote(static::getPrimaryKey());

        $row = static::getDb()->fetch("SELECT * FROM {$table} WHERE {$pk} = ? LIMIT 1", [$id]);
        return $row ? new static($row, true) : null;
    }

    /**
     * Find a record by primary key or throw RuntimeException.
     */
    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new RuntimeException("Record not found in " . static::getTableName() . " with ID {$id}");
        }
        return $model;
    }

    /**
     * Retrieve all records where column matches criteria.
     *
     * @return array<static>
     */
    public static function where(string $column, mixed $value, string $operator = '='): array
    {
        $table = static::quote(static::getTableName());
        $col = static::quote($column);
        $rows = static::getDb()->fetchAll("SELECT * FROM {$table} WHERE {$col} {$operator} ?", [$value]);

        $results = [];
        foreach ($rows as $row) {
            $results[] = new static($row, true);
        }
        return $results;
    }

    /**
     * Retrieve the first record matching criteria.
     */
    public static function firstWhere(string $column, mixed $value, string $operator = '='): ?static
    {
        $table = static::quote(static::getTableName());
        $col = static::quote($column);
        $row = static::getDb()->fetch("SELECT * FROM {$table} WHERE {$col} {$operator} ? LIMIT 1", [$value]);
        return $row ? new static($row, true) : null;
    }

    /**
     * Retrieve all records from the table.
     *
     * @return array<static>
     */
    public static function all(string $orderBy = '', string $direction = 'ASC'): array
    {
        $table = static::quote(static::getTableName());
        $sql = "SELECT * FROM {$table}";
        if ($orderBy !== '') {
            $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY " . static::quote($orderBy) . " {$dir}";
        }

        $rows = static::getDb()->fetchAll($sql);
        $results = [];
        foreach ($rows as $row) {
            $results[] = new static($row, true);
        }
        return $results;
    }

    /**
     * Count records matching optional condition.
     */
    public static function count(string $column = '*', ?string $whereColumn = null, mixed $whereValue = null): int
    {
        $table = static::quote(static::getTableName());
        $colTarget = ($column === '*') ? '*' : static::quote($column);
        $sql = "SELECT COUNT({$colTarget}) FROM {$table}";
        $params = [];
        if ($whereColumn !== null) {
            $sql .= " WHERE " . static::quote($whereColumn) . " = ?";
            $params[] = $whereValue;
        }

        return (int) static::getDb()->fetchColumn($sql, $params);
    }

    /**
     * Create and persist a new model instance.
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Execute a raw SQL query and map results to model instances.
     *
     * @return array<static>
     */
    public static function query(string $sql, array $params = []): array
    {
        $rows = static::getDb()->fetchAll($sql, $params);
        $results = [];
        foreach ($rows as $row) {
            $results[] = new static($row, true);
        }
        return $results;
    }

    /**
     * Save the model to the database (INSERT or UPDATE).
     */
    public function save(): bool
    {
        $db = static::getDb();
        $table = static::getTableName();
        $pk = static::getPrimaryKey();

        if ($this->exists) {
            // Check dirty attributes
            $dirty = $this->getDirty();
            if (empty($dirty)) {
                return true;
            }

            $qTable = $db->quoteIdentifier($table);
            $qPk = $db->quoteIdentifier($pk);

            $fields = [];
            $values = [];
            foreach ($dirty as $col => $val) {
                $fields[] = $db->quoteIdentifier($col) . " = ?";
                $values[] = $val;
            }

            $values[] = $this->attributes[$pk] ?? null;
            $sql = "UPDATE {$qTable} SET " . implode(', ', $fields) . " WHERE {$qPk} = ?";
            $db->execute($sql, $values);

            $this->original = $this->attributes;
            return true;
        }

        // Insert new record
        $filtered = $this->attributes;
        if (!empty(static::$fillable)) {
            $filtered = array_intersect_key($filtered, array_flip(static::$fillable));
        }

        $cols = array_keys($filtered);
        $placeholders = array_fill(0, count($cols), '?');

        $qTable = $db->quoteIdentifier($table);
        $quotedCols = array_map([$db, 'quoteIdentifier'], $cols);

        $sql = "INSERT INTO {$qTable} (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $db->execute($sql, array_values($filtered));

        $insertId = $db->lastInsertId();
        if ($insertId > 0 && !isset($this->attributes[$pk])) {
            $this->attributes[$pk] = $insertId;
        }

        $this->exists = true;
        $this->original = $this->attributes;
        return true;
    }

    /**
     * Update model attributes and immediately persist.
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    /**
     * Delete the record from database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $table = static::getTableName();
        $pk = static::getPrimaryKey();
        $id = $this->attributes[$pk] ?? null;

        if ($id === null) {
            return false;
        }

        $db = static::getDb();
        $qTable = $db->quoteIdentifier($table);
        $qPk = $db->quoteIdentifier($pk);
        $db->execute("DELETE FROM {$qTable} WHERE {$qPk} = ?", [$id]);
        $this->exists = false;
        return true;
    }

    /**
     * Refresh model attributes from database.
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $pk = static::getPrimaryKey();
        $id = $this->attributes[$pk] ?? null;

        if ($id !== null) {
            $fresh = static::find($id);
            if ($fresh !== null) {
                $this->attributes = $fresh->attributes;
                $this->original = $this->attributes;
            }
        }

        return $this;
    }

    /**
     * Check if an attribute or entire model has changed since hydration.
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
        }

        return !empty($this->getDirty());
    }

    /**
     * Get changed attributes.
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Convert model attributes to associative array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * JSON serialization format.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
}
