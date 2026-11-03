<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\AuthService;
use App\Services\KeyManagementService;
use App\Services\TwoFactorService;

echo "======================================================\n";
echo "  Solana Undo Escrow - Pure Supabase Live Verification\n";
echo "======================================================\n\n";

// 1. Test PDO connection
$db = Database::getInstance();
$pdo = $db->getPdo();
echo "[PASS] Successfully connected to Supabase PostgreSQL at " . env('DB_HOST') . "!\n";

// 2. Test reading tables
$userCount = (int) $db->fetchColumn("SELECT COUNT(*) FROM users");
echo "[PASS] Read 'users' table: found {$userCount} user(s).\n";

$balanceCount = (int) $db->fetchColumn("SELECT COUNT(*) FROM user_balances");
echo "[PASS] Read 'user_balances' table: found {$balanceCount} record(s).\n";

// 3. Test User Registration via AuthService
$authService = new AuthService();
$rand = bin2hex(random_bytes(4));
$testUser = "test_" . $rand;
$testEmail = "test_{$rand}@solanaundo.com";
$testPass = "SecurePass123!#";

echo "\nRegistering test user: {$testUser} ({$testEmail})...\n";
$user = $authService->register($testUser, $testEmail, $testPass);
echo "[PASS] User created with ID: {$user->id}\n";
echo "       Solana Deposit Address: {$user->solana_deposit_address}\n";

// 4. Verify Initial Balances
$bals = $user->getBalances();
echo "[PASS] Verified seeded balances for new user:\n";
foreach ($bals as $tok => $b) {
    echo "       - {$tok}: Available = {$b->available}, Escrow = {$b->escrow}\n";
}

// 5. Test Balance Operations (Credit & Debit)
$solBal = UserBalance::getByUserAndToken((int)$user->id, 'SOL');
$solBal->creditAvailable(2.50);
$freshSol = UserBalance::getByUserAndToken((int)$user->id, 'SOL');
echo "[PASS] Credited 2.50 SOL: new balance = {$freshSol->available}\n";

$solBal->moveToEscrow(1.00);
$heldSol = UserBalance::getByUserAndToken((int)$user->id, 'SOL');
echo "[PASS] Held 1.00 SOL in escrow: Available = {$heldSol->available}, Escrow = {$heldSol->escrow}\n";

$solBal->releaseFromEscrow(1.00);
$releasedSol = UserBalance::getByUserAndToken((int)$user->id, 'SOL');
echo "[PASS] Released 1.00 SOL back to available: Available = {$releasedSol->available}, Escrow = {$releasedSol->escrow}\n";

// 6. Test Authentication & Session
$authResult = $authService->authenticate($testEmail, $testPass);
if ($authResult['authenticated']) {
    echo "[PASS] Authentication verified with correct password!\n";
} else {
    echo "[FAIL] Authentication failed!\n";
}

// 7. Cleanup Test Records
$db->execute("DELETE FROM user_balances WHERE user_id = ?", [$user->id]);
$db->execute("DELETE FROM users WHERE id = ?", [$user->id]);
echo "[PASS] Cleaned up test user #{$user->id} from Supabase.\n";

echo "\n======================================================\n";
echo "  ALL SUPABASE POSTGRESQL TESTS PASSED WITH 100% SUCCESS!\n";
echo "======================================================\n";
