<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\SolanaRpcService;

$db = Database::getInstance();
$users = $db->fetchAll("SELECT id, username, email, solana_deposit_address FROM users ORDER BY id DESC");

echo "Found " . count($users) . " users in database:\n";
$rpc = new SolanaRpcService();

foreach ($users as $u) {
    $addr = $u['solana_deposit_address'] ?? '';
    echo "\n----------------------------------------\n";
    echo "User ID: {$u['id']} | Username: {$u['username']} | Email: {$u['email']}\n";
    echo "Deposit Address: {$addr}\n";
    
    // Check balance in local user_balances table
    $balances = $db->fetchAll("SELECT token, available, escrow FROM user_balances WHERE user_id = ?", [$u['id']]);
    echo "DB Balances:\n";
    foreach ($balances as $b) {
        echo "  - {$b['token']}: available={$b['available']}, escrow={$b['escrow']}\n";
    }

    if (!empty($addr)) {
        try {
            $lamports = $rpc->getBalance($addr);
            $sol = $lamports / 1_000_000_000;
            echo "On-Chain Solana Devnet Balance: {$sol} SOL ({$lamports} lamports)\n";
        } catch (\Throwable $e) {
            echo "Error checking on-chain balance: " . $e->getMessage() . "\n";
        }
    }
}
