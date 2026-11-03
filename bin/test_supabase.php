<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

echo "========================================================\n";
echo "  Testing Pure Supabase (PostgreSQL) Connection\n";
echo "========================================================\n\n";

$config = config('database', []);
echo "Driver:     " . ($config['driver'] ?? 'N/A') . "\n";
echo "Host:       " . ($config['host'] ?? 'N/A') . ":" . ($config['port'] ?? 'N/A') . "\n";
echo "Database:   " . ($config['database'] ?? 'N/A') . "\n";
echo "User:       " . ($config['username'] ?? 'N/A') . "\n";
echo "SSL Mode:   " . ($config['sslmode'] ?? 'N/A') . "\n";
echo "Password:   " . (!empty($config['password']) && $config['password'] !== 'YOUR_DB_PASSWORD' ? '[CONFIGURED]' : '[NOT SET / PLACEHOLDER]') . "\n\n";

if (empty($config['password']) || $config['password'] === 'YOUR_DB_PASSWORD') {
    echo "[ACTION REQUIRED] Please set your real Supabase database password in .env:\n";
    echo "  DB_PASSWORD=\"your-actual-supabase-password\"\n\n";
    echo "Or set the full connection string:\n";
    echo "  DATABASE_URL=\"postgres://postgres.ouwxjoqredxaacdqmsht:YOUR_PASSWORD@aws-0-eu-west-1.pooler.supabase.com:6543/postgres?sslmode=require\"\n\n";
    exit(1);
}

try {
    $db = Database::getInstance();
    $version = $db->fetchColumn("SELECT version()");
    echo "[SUCCESS] Connected directly to Supabase PostgreSQL!\n";
    echo "Engine: " . substr((string)$version, 0, 60) . "...\n\n";

    $userCount = $db->fetchColumn("SELECT COUNT(*) FROM users");
    echo "Users in Supabase:           {$userCount}\n";

    $balanceCount = $db->fetchColumn("SELECT COUNT(*) FROM user_balances");
    echo "Balances in Supabase:        {$balanceCount}\n";

    $transferCount = $db->fetchColumn("SELECT COUNT(*) FROM pending_transfers");
    echo "Pending Transfers:           {$transferCount}\n";

    echo "\n[ALL CLEAR] Pure Supabase database is active and healthy!\n";
} catch (\Throwable $e) {
    echo "[ERROR] Could not connect to Supabase: " . $e->getMessage() . "\n";
    exit(1);
}
