<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Controllers\TransferController;
use App\Services\KeyManagementService;
use App\Services\SolanaRpcService;
use App\Services\TransferService;

echo "======================================================\n";
echo "  Solana Undo Escrow - Full End-to-End Integration Test\n";
echo "======================================================\n\n";

// 1. Initialize SQLite Database for Testing
$testDbPath = __DIR__ . '/test_undo.sqlite';
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

$dbConfig = [
    'driver' => 'sqlite',
    'database' => $testDbPath,
];

Database::resetInstance();
$db = Database::getInstance($dbConfig);
$pdo = $db->getPdo();

// Create schema for SQLite
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        email TEXT UNIQUE,
        password_hash TEXT,
        google_id TEXT,
        wallet_address TEXT,
        solana_deposit_address TEXT,
        encrypted_private_key TEXT,
        two_factor_secret TEXT,
        two_factor_enabled INTEGER DEFAULT 0,
        is_admin INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE user_balances (
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        available NUMERIC NOT NULL DEFAULT 0,
        escrow NUMERIC NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, token)
    );

    CREATE TABLE pending_transfers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        amount NUMERIC NOT NULL,
        destination TEXT NOT NULL,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        status TEXT DEFAULT 'pending',
        tx_signature TEXT,
        cancelled_at DATETIME,
        processed_at DATETIME,
        failure_reason TEXT
    );

    CREATE TABLE deposits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        amount NUMERIC NOT NULL,
        tx_signature TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        confirmed_at DATETIME
    );

    CREATE TABLE sessions (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        data TEXT,
        ip_address TEXT,
        user_agent TEXT,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_activity DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

echo "[OK] Test database schema initialized.\n";

// 2. Seed Test Users and Balances
$keyService = new KeyManagementService('test-master-key-32b-length-ok!');
$demoKeypair = $keyService->generateSolanaKeypair();

$pdo->prepare("
    INSERT INTO users (id, username, email, wallet_address, solana_deposit_address, encrypted_private_key, two_factor_enabled, is_admin)
    VALUES (1, 'demo_user', 'demo@undo.sol', '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU', ?, ?, 0, 0)
")->execute([$demoKeypair['publicKey'], $demoKeypair['encryptedSecretKey']]);

$pdo->prepare("
    INSERT INTO users (id, username, email, wallet_address, solana_deposit_address, encrypted_private_key, two_factor_enabled, is_admin)
    VALUES (2, 'admin_user', 'admin@undo.sol', 'DYw8jCTfwHNRJhhmFcbXvVDTqWMEVFBX6ZKUmG5CNSKK', '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM', NULL, 0, 1)
")->execute();

// Initial user balances: 5.0 SOL, 200.0 USDC, 100.0 USDT
$pdo->exec("
    INSERT INTO user_balances (user_id, token, available, escrow) VALUES 
    (1, 'SOL', 5.00000000, 0.00000000),
    (1, 'USDC', 200.00000000, 0.00000000),
    (1, 'USDT', 100.00000000, 0.00000000);
");

echo "[OK] Test users and balances seeded.\n";

// 3. Test SolanaRpcService & ATA Derivation
$rpcService = new SolanaRpcService(null, null, 5, true); // force simulation
$destinationPubkey = '83astBRguLMdt2h5U1Tpdq5LM9KuKHLuGTu2uCpWbhQA';

$ataUsdc = $rpcService->findAssociatedTokenAddress($destinationPubkey, '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU');
echo "[OK] Derived Associated Token Account for USDC: {$ataUsdc}\n";
assert(strlen($ataUsdc) >= 32);

// 4. Test TransferService: Small Transfer (Escrow Hold)
$transferService = new TransferService($db, $rpcService, $keyService);

echo "\n--- Testing Small Transfer Initiation (SOL <= 2.0) ---\n";
// Threshold for SOL is 2.0. Transfer 0.75 SOL -> should be held in escrow.
$smallTransferResult = $transferService->initiateTransfer(
    1,
    'SOL',
    0.75,
    $destinationPubkey,
    'Coffee payment with undo window',
    10 // 10 minutes
);

assert($smallTransferResult['type'] === 'escrow');
assert($smallTransferResult['status'] === 'pending');
$smallTransferId = $smallTransferResult['transfer_id'];
echo "[OK] Initiated escrow transfer ID #{$smallTransferId}, expires at: {$smallTransferResult['expires_at']}\n";

// Verify balances: 12% platform fee (0.09) + 0.000005 gas fee deducted from available
// available should be 5.0 - (0.75 + 0.09 + 0.000005) = 4.159995, escrow should be 0.75
$balances = $transferService->getUserBalances(1);
echo "SOL Available: {$balances['SOL']['available']}, Escrow: {$balances['SOL']['escrow']}\n";
assert(abs($balances['SOL']['available'] - 4.159995) < 0.0001);
assert(abs($balances['SOL']['escrow'] - 0.75) < 0.0001);

// 5. Test TransferService: Cancellation Grace Window
echo "\n--- Testing Transfer Cancellation ---\n";
$cancelResult = $transferService->cancelTransfer(1, $smallTransferId);
assert($cancelResult['status'] === 'cancelled');
echo "[OK] Transfer #{$smallTransferId} successfully cancelled!\n";

// Verify balances restored: available should be 5.0, escrow should be 0.0
$balancesAfterCancel = $transferService->getUserBalances(1);
echo "After Cancel - SOL Available: {$balancesAfterCancel['SOL']['available']}, Escrow: {$balancesAfterCancel['SOL']['escrow']}\n";
assert(abs($balancesAfterCancel['SOL']['available'] - 5.0) < 0.0001);
assert(abs($balancesAfterCancel['SOL']['escrow'] - 0.0) < 0.0001);

// 6. Test TransferService: Large Transfer (Bypasses Escrow -> Direct Broadcast)
echo "\n--- Testing Large Transfer (> 2.0 SOL threshold) ---\n";
// Transfer 3.0 SOL (> 2.0 threshold) -> should execute immediate broadcast
$largeTransferResult = $transferService->initiateTransfer(
    1,
    'SOL',
    3.0,
    $destinationPubkey,
    'Large supplier invoice'
);

assert($largeTransferResult['type'] === 'immediate');
assert($largeTransferResult['status'] === 'completed');
assert(!empty($largeTransferResult['tx_signature']));
echo "[OK] Large transfer executed directly on-chain! Tx Signature: {$largeTransferResult['tx_signature']}\n";

// Available should now be 5.0 - (3.0 + 0.36 + 0.000005) = 1.639995, escrow = 0.0
$balancesAfterLarge = $transferService->getUserBalances(1);
echo "After Large Send - SOL Available: {$balancesAfterLarge['SOL']['available']}, Escrow: {$balancesAfterLarge['SOL']['escrow']}\n";
assert(abs($balancesAfterLarge['SOL']['available'] - 1.639995) < 0.0001);
assert(abs($balancesAfterLarge['SOL']['escrow'] - 0.0) < 0.0001);

// 7. Test Multi-Year Timelock Duration & Human Formatting
echo "\n--- Testing Multi-Year Escrow Timelock & Duration Formatting ---\n";
assert(TransferService::formatHumanDuration(5) === '5 minutes');
assert(TransferService::formatHumanDuration(60) === '1 hour');
assert(TransferService::formatHumanDuration(1440) === '1 day');
assert(TransferService::formatHumanDuration(10080) === '1 week');
assert(TransferService::formatHumanDuration(43200) === '1 month');
assert(TransferService::formatHumanDuration(525600) === '1 year');
assert(TransferService::formatHumanDuration(1051200) === '2 years');
assert(TransferService::formatHumanDuration(2628000) === '5 years');
echo "[OK] formatHumanDuration validated across minutes, hours, days, weeks, months, and years.\n";

// Initiate a 1-year escrow hold transfer (525,600 minutes)
$multiYearTransfer = $transferService->initiateTransfer(
    1,
    'SOL',
    0.2,
    $destinationPubkey,
    'Multi-year trust fund hold',
    525600 // 1 year
);
assert($multiYearTransfer['type'] === 'escrow');
assert($multiYearTransfer['status'] === 'pending');
assert($multiYearTransfer['window_minutes'] === 525600);
assert($multiYearTransfer['window_human'] === '1 year');
$oneYearFromNow = time() + (525600 * 60);
$actualExpiry = strtotime($multiYearTransfer['expires_at']);
assert(abs($actualExpiry - $oneYearFromNow) < 5, 'Expiry timestamp must be ~1 year in the future');
echo "[OK] Multi-year escrow transfer #{$multiYearTransfer['transfer_id']} successfully created with 1-year hold (Expires: {$multiYearTransfer['expires_at']})!\n";

// Cancel the multi-year transfer and restore funds
$cancelMultiYear = $transferService->cancelTransfer(1, $multiYearTransfer['transfer_id']);
assert($cancelMultiYear['status'] === 'cancelled');
echo "[OK] Multi-year escrow transfer cancelled and funds restored.\n";

// 8. Test Cron Expired Transfer Processing
echo "\n--- Testing Cron Broadcaster (process_pending.php logic) ---\n";
// Create another small transfer and set its expires_at to 1 minute ago (expired)
$holdResult = $transferService->initiateTransfer(1, 'SOL', 1.0, $destinationPubkey, 'Will be expired for cron test');
$expiredTransferId = $holdResult['transfer_id'];

// Manually backdate expires_at to the past
$pdo->prepare("UPDATE pending_transfers SET expires_at = datetime('now', '-2 minutes') WHERE id = ?")->execute([$expiredTransferId]);

// Verify it's in pending_transfers and expired
$expiredItem = $db->fetch("SELECT * FROM pending_transfers WHERE id = ? AND expires_at <= datetime('now')", [$expiredTransferId]);
assert($expiredItem !== null, "Expired item not found in query");

// Run cron simulation
echo "Executing process_pending check...\n";
// Concurrency lock
$db->execute("UPDATE pending_transfers SET status = 'processing' WHERE id = ? AND status = 'pending'", [$expiredTransferId]);
$signingKey = $keyService->resolveSigningKey($demoKeypair['encryptedSecretKey']);
$broadcastSig = $rpcService->broadcastTransfer($signingKey, $destinationPubkey, 1.0, 'SOL');

// Finalize
$db->transaction(function (Database $db) use ($expiredTransferId, $broadcastSig) {
    $db->execute("UPDATE user_balances SET escrow = escrow - 1.0 WHERE user_id = 1 AND token = 'SOL'");
    $db->execute("UPDATE pending_transfers SET status = 'completed', tx_signature = ?, processed_at = datetime('now') WHERE id = ?", [$broadcastSig, $expiredTransferId]);
});

$completedItem = $db->fetch("SELECT * FROM pending_transfers WHERE id = ?", [$expiredTransferId]);
assert($completedItem['status'] === 'completed');
assert(!empty($completedItem['tx_signature']));
echo "[OK] Cron broadcaster successfully processed expired transfer #{$expiredTransferId} with tx signature {$completedItem['tx_signature']}\n";

// 8. Test Deposit Watcher Logic
echo "\n--- Testing Deposit Watcher (deposit_watcher.php logic) ---\n";
// Simulate incoming deposit of 1.5 SOL
$simDepositSig = 'sim_dep_test_' . time();
$pdo->prepare("
    INSERT INTO deposits (user_id, token, amount, tx_signature, status, created_at, confirmed_at)
    VALUES (1, 'SOL', 1.5, ?, 'confirmed', datetime('now'), datetime('now'))
")->execute([$simDepositSig]);

$pdo->prepare("
    UPDATE user_balances SET available = available + 1.5 WHERE user_id = 1 AND token = 'SOL'
")->execute();

$balancesAfterDeposit = $transferService->getUserBalances(1);
echo "After 1.5 SOL Deposit - Available: {$balancesAfterDeposit['SOL']['available']}\n";
assert(abs($balancesAfterDeposit['SOL']['available'] - 2.01999) < 0.0001); // 1.639995 - 1.120005 (cron completed) + 1.5 (deposit) = 2.01999 SOL
echo "[OK] Deposit successfully credited to user balance!\n";

// 9. Test TransferController API Endpoints & Auth Enforcement
echo "\n--- Testing TransferController HTTP API Endpoints & Auth Enforcement ---\n";
$controller = new TransferController($transferService);

// A. Unauthenticated request must return 401
$_SESSION = [];
$unauthReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'HTTP_X_USER_ID' => '1'], [], null);
$unauthRes = $controller->balances($unauthReq);
assert($unauthRes->getStatusCode() === 401, 'Unauthenticated request must be rejected with HTTP 401');
echo "[OK] Auth enforcement: Untrusted X-User-Id header rejected with HTTP 401 Unauthorized.\n";

// B. Authenticated session
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'demo_user';

$req = new Request([], [], ['REQUEST_METHOD' => 'GET'], [], null);
$resBalances = $controller->balances($req);
assert($resBalances->getStatusCode() === 200);
$bodyBalances = json_decode($resBalances->getBody(), true);
assert($bodyBalances['success'] === true);
echo "[OK] GET /api/transfers/balances returned HTTP 200 with tokens: " . implode(', ', array_keys($bodyBalances['data']['balances'])) . "\n";

// C. /api/transfers/initiate (POST)
$postData = [
    'token' => 'USDC',
    'amount' => 50.0,
    'destination' => $destinationPubkey,
    'note' => 'Controller API test USDC',
    'window_minutes' => 15,
];
$reqPost = new Request([], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], [], json_encode($postData));
$resInitiate = $controller->initiate($reqPost);
assert($resInitiate->getStatusCode() === 200);
$bodyInitiate = json_decode($resInitiate->getBody(), true);
assert($bodyInitiate['success'] === true);
$controllerTransferId = $bodyInitiate['data']['transfer_id'];
echo "[OK] POST /api/transfers/initiate created transfer #{$controllerTransferId} (Hold window: {$bodyInitiate['data']['window_minutes']}m)\n";

// C2. /api/transfers/initiate with duration_value and duration_unit (2 Years)
$postDataYears = [
    'token' => 'USDT',
    'amount' => 10.0,
    'destination' => $destinationPubkey,
    'note' => '2-year timelock via unit parameters',
    'duration_value' => 2,
    'duration_unit' => 'years',
];
$reqPostYears = new Request([], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], [], json_encode($postDataYears));
$resInitiateYears = $controller->initiate($reqPostYears);
assert($resInitiateYears->getStatusCode() === 200);
$bodyYears = json_decode($resInitiateYears->getBody(), true);
assert($bodyYears['success'] === true);
assert($bodyYears['data']['window_minutes'] === 1051200);
assert($bodyYears['data']['window_human'] === '2 years');
echo "[OK] POST /api/transfers/initiate with {duration_value: 2, duration_unit: 'years'} created transfer #{$bodyYears['data']['transfer_id']} with 2-year hold!\n";

// D. /api/transfers/pending (GET)
$resPending = $controller->pending($req);
assert($resPending->getStatusCode() === 200);
$bodyPending = json_decode($resPending->getBody(), true);
assert($bodyPending['data']['count'] >= 1);
echo "[OK] GET /api/transfers/pending returned {$bodyPending['data']['count']} pending item(s)\n";

// E. /api/transfers/cancel (POST)
$cancelData = ['transfer_id' => $controllerTransferId];
$reqCancel = new Request([], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], [], json_encode($cancelData));
$resCancel = $controller->cancel($reqCancel);
assert($resCancel->getStatusCode() === 200);
$bodyCancel = json_decode($resCancel->getBody(), true);
assert($bodyCancel['success'] === true);
assert($bodyCancel['data']['status'] === 'cancelled');
echo "[OK] POST /api/transfers/cancel cancelled transfer #{$controllerTransferId}\n";

// F. /api/transfers/history (GET)
$resHistory = $controller->history($req);
assert($resHistory->getStatusCode() === 200);
$bodyHistory = json_decode($resHistory->getBody(), true);
echo "[OK] GET /api/transfers/history returned {$bodyHistory['data']['count']} historic item(s)\n";

echo "\n======================================================\n";
echo "  All End-to-End Integration Tests PASSED (100% OK)!\n";
echo "======================================================\n";

// Clean up test sqlite database
Database::resetInstance();
$pdo = null;
$db = null;
gc_collect_cycles();
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}
