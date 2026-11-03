<?php

declare(strict_types=1);

/**
 * Pure Supabase Database Configuration (PostgreSQL)
 *
 * Defaults to the Supabase transaction pooler (port 6543) in eu-west-1.
 * Automatically parses DATABASE_URL when deployed to Vercel or configured in .env.
 */

$databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: null;
$parsedUrl = $databaseUrl ? parse_url($databaseUrl) : null;

$driver = 'pgsql';
$host = $parsedUrl['host'] ?? ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'aws-0-eu-west-1.pooler.supabase.com');
$port = isset($parsedUrl['port']) ? (int) $parsedUrl['port'] : (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 6543);
$database = isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'postgres');
$username = $parsedUrl['user'] ?? ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'postgres.ouwxjoqredxaacdqmsht');
$password = $parsedUrl['pass'] ?? ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

// Parse query params (e.g. ?sslmode=require)
$sslmode = 'require';
if (isset($parsedUrl['query'])) {
    parse_str($parsedUrl['query'], $queryParams);
    if (isset($queryParams['sslmode'])) {
        $sslmode = $queryParams['sslmode'];
    }
}
$sslmode = $_ENV['DB_SSLMODE'] ?? getenv('DB_SSLMODE') ?: $sslmode;

return [
    'driver' => 'pgsql',
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'sslmode' => $sslmode,
    'charset' => 'utf8',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Crucial for Supabase transaction pooler (port 6543): prevents "prepared statement does not exist" errors
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ],
];
