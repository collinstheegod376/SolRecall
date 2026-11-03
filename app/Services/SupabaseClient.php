<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Native Supabase REST / PostgREST Client
 *
 * Allows full CRUD operations and query building directly against Supabase
 * using only SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY / SUPABASE_ANON_KEY.
 * Eliminates the requirement for direct database passwords or connection poolers.
 */
class SupabaseClient
{
    private static ?self $instance = null;
    private string $url;
    private string $key;

    public function __construct(?string $url = null, ?string $key = null)
    {
        $this->url = rtrim($url ?? (string)(env('SUPABASE_URL') ?? ''), '/');
        $this->key = $key ?? (string)(env('SUPABASE_SERVICE_ROLE_KEY') ?? env('SUPABASE_ANON_KEY') ?? '');

        if (empty($this->url) || empty($this->key)) {
            throw new RuntimeException("Supabase credentials not configured. SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY required.");
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function from(string $table): SupabaseQueryBuilder
    {
        return new SupabaseQueryBuilder($this->url, $this->key, $table);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}

/**
 * Fluent Query Builder for PostgREST
 */
class SupabaseQueryBuilder
{
    private string $baseUrl;
    private string $apiKey;
    private string $table;

    private string $selectColumns = '*';
    private array $filters = [];
    private ?string $orderColumn = null;
    private string $orderDirection = 'asc';
    private ?int $limitCount = null;
    private ?int $offsetCount = null;
    private array $headers = [];

    public function __construct(string $baseUrl, string $apiKey, string $table)
    {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->table = trim($table, '"`');
    }

    public function select(string $columns = '*'): self
    {
        $this->selectColumns = $columns;
        return $this;
    }

    public function eq(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $this->filters[] = "{$col}=eq." . urlencode((string)$value);
        return $this;
    }

    public function neq(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $this->filters[] = "{$col}=neq." . urlencode((string)$value);
        return $this;
    }

    public function gt(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $this->filters[] = "{$col}=gt." . urlencode((string)$value);
        return $this;
    }

    public function gte(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $this->filters[] = "{$col}=gte." . urlencode((string)$value);
        return $this;
    }

    public function lt(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $this->filters[] = "{$col}=lt." . urlencode((string)$value);
        return $this;
    }

    public function lte(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $this->filters[] = "{$col}=lte." . urlencode((string)$value);
        return $this;
    }

    public function is(string $column, mixed $value): self
    {
        $col = trim($column, '"`');
        $val = $value === null ? 'null' : (string)$value;
        $this->filters[] = "{$col}=is." . urlencode($val);
        return $this;
    }

    public function order(string $column, string $direction = 'asc'): self
    {
        $col = trim($column, '"`');
        $this->orderColumn = $col;
        $this->orderDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limitCount = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetCount = $offset;
        return $this;
    }

    /**
     * Execute a SELECT query and return an array of rows.
     */
    public function get(): array
    {
        $queryParts = ["select=" . urlencode($this->selectColumns)];
        foreach ($this->filters as $filter) {
            $queryParts[] = $filter;
        }
        if ($this->orderColumn !== null) {
            $queryParts[] = "order=" . urlencode("{$this->orderColumn}.{$this->orderDirection}");
        }
        if ($this->limitCount !== null) {
            $queryParts[] = "limit=" . $this->limitCount;
        }
        if ($this->offsetCount !== null) {
            $queryParts[] = "offset=" . $this->offsetCount;
        }

        $url = "{$this->baseUrl}/rest/v1/{$this->table}?" . implode('&', $queryParts);
        $res = $this->request('GET', $url);
        return is_array($res) ? $res : [];
    }

    /**
     * Fetch a single row or null.
     */
    public function first(): ?array
    {
        $this->limit(1);
        $rows = $this->get();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Count rows matching the filters.
     */
    public function count(): int
    {
        $queryParts = ["select=count"];
        foreach ($this->filters as $filter) {
            $queryParts[] = $filter;
        }
        $url = "{$this->baseUrl}/rest/v1/{$this->table}?" . implode('&', $queryParts);
        $res = $this->request('GET', $url, null, ['Prefer: count=exact']);
        if (is_array($res) && isset($res[0]['count'])) {
            return (int) $res[0]['count'];
        }
        return count($this->get());
    }

    /**
     * Insert one or multiple rows into Supabase.
     */
    public function insert(array $data): ?array
    {
        $url = "{$this->baseUrl}/rest/v1/{$this->table}";
        $res = $this->request('POST', $url, $data, [
            'Prefer: return=representation'
        ]);
        if (is_array($res) && !empty($res)) {
            return isset($res[0]) && is_array($res[0]) ? $res[0] : $res;
        }
        return null;
    }

    /**
     * Upsert a row with conflict resolution.
     */
    public function upsert(array $data, ?string $onConflict = null): ?array
    {
        $url = "{$this->baseUrl}/rest/v1/{$this->table}";
        if ($onConflict !== null) {
            $url .= "?on_conflict=" . urlencode($onConflict);
        }
        $res = $this->request('POST', $url, $data, [
            'Prefer: return=representation,resolution=merge-duplicates'
        ]);
        if (is_array($res) && !empty($res)) {
            return isset($res[0]) && is_array($res[0]) ? $res[0] : $res;
        }
        return null;
    }

    /**
     * Update rows matching current filters.
     */
    public function update(array $data): ?array
    {
        if (empty($this->filters)) {
            throw new RuntimeException("Refusing to update table '{$this->table}' without at least one filter.");
        }
        $url = "{$this->baseUrl}/rest/v1/{$this->table}?" . implode('&', $this->filters);
        $res = $this->request('PATCH', $url, $data, [
            'Prefer: return=representation'
        ]);
        if (is_array($res) && !empty($res)) {
            return isset($res[0]) && is_array($res[0]) ? $res[0] : $res;
        }
        return null;
    }

    /**
     * Delete rows matching current filters.
     */
    public function delete(): bool
    {
        if (empty($this->filters)) {
            throw new RuntimeException("Refusing to delete from table '{$this->table}' without at least one filter.");
        }
        $url = "{$this->baseUrl}/rest/v1/{$this->table}?" . implode('&', $this->filters);
        $this->request('DELETE', $url);
        return true;
    }

    /**
     * Low-level cURL HTTP request handler.
     */
    private function request(string $method, string $url, mixed $body = null, array $extraHeaders = []): mixed
    {
        $ch = curl_init($url);
        $headers = array_merge([
            "apikey: {$this->apiKey}",
            "Authorization: Bearer {$this->apiKey}",
            "Content-Type: application/json",
            "Accept: application/json",
        ], $extraHeaders);

        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ];

        if ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException("Supabase HTTP request failed: {$curlErr} [URL: {$url}]");
        }

        if ($httpCode >= 400) {
            $parsed = json_decode((string)$response, true);
            $msg = $parsed['message'] ?? $parsed['error'] ?? "HTTP error {$httpCode}";
            $details = $parsed['details'] ?? '';
            throw new RuntimeException("Supabase API Error ({$httpCode}): {$msg}" . ($details ? " - {$details}" : ""));
        }

        if ($response === '' || $response === false) {
            return null;
        }

        return json_decode($response, true);
    }
}
