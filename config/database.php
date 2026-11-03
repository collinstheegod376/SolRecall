<?php

declare(strict_types=1);

/**
 * Database Configuration
 *
 * Supports MySQL, PostgreSQL (Supabase), and SQLite.
 * Automatically parses DATABASE_URL if provided (standard on Supabase and Vercel).
 */

$databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: null;
$parsedUrl = $databaseUrl ? parse_url($databaseUrl) : null;

$driver = $parsedUrl['scheme'] ?? ($_ENV['DB_CONNECTION'] ?? $_ENV['DB_DRIVER'] ?? getenv('DB_CONNECTION') ?: 'mysql');
// Normalize postgres / postgresql scheme from Supabase connection strings
if ($driver === 'postgres' || $driver === 'postgresql') {
    $driver = 'pgsql';
}

$host = $parsedUrl['host'] ?? ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$port = isset($parsedUrl['port']) ? (int) $parsedUrl['port'] : (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306));
$database = isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'undo_transfers');
$username = $parsedUrl['user'] ?? ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
$password = $parsedUrl['pass'] ?? ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

// Parse query params (e.g. ?sslmode=require)
$sslmode = 'prefer';
if (isset($parsedUrl['query'])) {
    parse_str($parsedUrl['query'], $queryParams);
    if (isset($queryParams['sslmode'])) {
        $sslmode = $queryParams['sslmode'];
    }
}
$sslmode = $_ENV['DB_SSLMODE'] ?? getenv('DB_SSLMODE') ?: $sslmode;

return [
    'driver' => $driver,
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'sslmode' => $sslmode,
    'charset' => $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4',
    'collation' => $_ENV['DB_COLLATION'] ?? getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ],
];
