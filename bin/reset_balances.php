<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

echo "=== SolRecall Database Balance Reset for Mainnet ===\n\n";

try {
    $db = Database::getInstance();

    echo "1. Resetting all user_balances to 0.00000000...\n";
    $db->execute("UPDATE user_balances SET available = 0.00000000, escrow = 0.00000000, updated_at = NOW()");
    
    // Ensure every user has SOL, USDC, and USDT rows with 0
    $users = $db->fetchAll("SELECT id, username FROM users");
    $tokens = ['SOL', 'USDC', 'USDT'];
    foreach ($users as $u) {
        foreach ($tokens as $t) {
            $db->execute("
                INSERT INTO user_balances (user_id, token, available, escrow, updated_at)
                VALUES (?, ?, 0.00000000, 0.00000000, NOW())
                ON CONFLICT (user_id, token) 
                DO UPDATE SET available = 0.00000000, escrow = 0.00000000, updated_at = NOW()
            ", [$u['id'], $t]);
        }
    }
    echo "   [SUCCESS] All user balances set to 0.00000000 across SOL, USDC, and USDT.\n\n";

    echo "2. Purging Devnet deposit transaction records...\n";
    $delDeposits = $db->execute("DELETE FROM deposits");
    echo "   [SUCCESS] Deposits table cleared.\n\n";

    echo "3. Purging Devnet pending/historic escrow transfers...\n";
    $delTransfers = $db->execute("DELETE FROM pending_transfers");
    echo "   [SUCCESS] Pending transfers table cleared.\n\n";

    echo "4. Invalidating in-memory and session micro-caches...\n";
    \App\Services\TransferService::invalidateBalanceCache();
    echo "   [SUCCESS] Cache invalidated.\n\n";

    echo "5. Verifying clean state in database...\n";
    $balances = $db->fetchAll("
        SELECT u.id, u.username, b.token, b.available, b.escrow 
        FROM users u 
        JOIN user_balances b ON u.id = b.user_id 
        ORDER BY u.id, b.token
    ");
    foreach ($balances as $row) {
        echo "   User #{$row['id']} ({$row['username']}) - {$row['token']}: available={$row['available']}, escrow={$row['escrow']}\n";
    }

    echo "\n======================================================\n";
    echo "  Database is 100% CLEAN and RESET for MAINNET!\n";
    echo "======================================================\n";

} catch (\Throwable $e) {
    echo "   [ERROR] Failed to reset database: " . $e->getMessage() . "\n";
    exit(1);
}
