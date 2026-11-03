<?php

declare(strict_types=1);

/**
 * CLI Database Migration & Seeder
 *
 * Runs schema.sql and seed.sql against the configured database.
 * Usage: php database/seed.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

echo "==> Running Solana Undo Escrow Database Migrations & Seeds...\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found at: {$schemaFile}");
    }

    echo "--> Executing schema.sql...\n";
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "    [OK] Schema created successfully.\n";

    $seedFile = __DIR__ . '/seed.sql';
    if (file_exists($seedFile)) {
        echo "--> Executing seed.sql...\n";
        $seedSql = file_get_contents($seedFile);
        $pdo->exec($seedSql);
        echo "    [OK] Seed data inserted successfully.\n";
    }

    echo "==> Database setup complete!\n";
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
