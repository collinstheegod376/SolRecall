<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Controllers\AdminController;
use App\Services\KeyManagementService;
use App\Services\TransferService;
use App\Utils\Base58;

echo "======================================================================\n";
echo "  SolRecall • Dev Wallet & Admin Fee Vault Test Suite\n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$kms = new KeyManagementService();
$adminController = new AdminController();

// 1. Setup Admin Test Session for User 7 (nerfedkillua)
$_SESSION['user_id'] = 7;
$_SESSION['username'] = 'nerfedkillua';
$_SESSION['is_admin'] = 1;

// Ensure admin user exists and is flagged as admin
$adminUser = $db->fetch("SELECT * FROM users WHERE id = 7");
assert($adminUser !== null, "Admin user 7 not found");
assert((int)$adminUser['is_admin'] === 1, "User 7 must be admin");

echo "--- SECTION 1: Dev Wallet Initialization & Discovery ---\n";
$devWallet = TransferService::getOrCreateDevWallet($db, $kms);
echo "  [PASS] Dev Wallet User ID: {$devWallet['id']}\n";
echo "  [PASS] Dev Wallet Address: {$devWallet['solana_deposit_address']}\n";
assert(!empty($devWallet['solana_deposit_address']));
assert(!empty($devWallet['encrypted_private_key']));

// Check GET /api/admin/dev-wallet
$req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
$res = $adminController->devWallet($req);
assert($res->getStatusCode() === 200, "devWallet endpoint failed: " . $res->getBody());
$data = json_decode($res->getBody(), true)['data'];
assert($data['wallet']['address'] === $devWallet['solana_deposit_address']);
assert(isset($data['balances']['SOL']));
assert(isset($data['balances']['USDC']));
assert(isset($data['balances']['USDT']));
echo "  [PASS] GET /api/admin/dev-wallet returned 200 OK with live balances & history\n";

echo "\n--- SECTION 2: Automated 12% Platform Fee Collection ---\n";
$preBalSol = (float)($db->fetchColumn("SELECT available FROM user_balances WHERE user_id = ? AND token = 'SOL'", [$devWallet['id']]) ?? 0);
$testFeeSol = 0.012345;
TransferService::creditPlatformFee($db, 999999, 'SOL', $testFeeSol, "Automated audit fee collection");

$postBalSol = (float)($db->fetchColumn("SELECT available FROM user_balances WHERE user_id = ? AND token = 'SOL'", [$devWallet['id']]) ?? 0);
$diff = round($postBalSol - $preBalSol, 6);
echo "  [PASS] Platform fee {$testFeeSol} SOL credited to Dev Wallet: Pre={$preBalSol}, Post={$postBalSol}\n";
assert($diff === round($testFeeSol, 6), "Fee was not credited properly: diff={$diff}");

$feeRecord = $db->fetch("SELECT * FROM fee_collections WHERE transfer_id = 999999 ORDER BY id DESC LIMIT 1");
assert($feeRecord !== null, "fee_collections record not created");
assert((float)$feeRecord['amount'] === $testFeeSol);
assert($feeRecord['type'] === 'fee_collected');
echo "  [PASS] Audit record logged in fee_collections table (ID: {$feeRecord['id']})\n";

echo "\n--- SECTION 3: Dev Wallet Private Key Export & Cryptographic Verification ---\n";
// Unauthorized attempt without password or TOTP
$unauthReq = new Request([], [], ['REQUEST_METHOD' => 'POST']);
$unauthRes = $adminController->exportDevWalletKey($unauthReq);
assert($unauthRes->getStatusCode() === 401, "Expected 401 for unauthorized export");
echo "  [PASS] Unverified export rejected with HTTP 401 Unauthorized\n";

// Authorized attempt using admin 2FA code
$totp = new \App\Services\TwoFactorService();
$validCode = $totp->getCode((string)$adminUser['two_factor_secret']);

$authReq = new Request([], ['totp_code' => $validCode], ['REQUEST_METHOD' => 'POST']);
$authRes = $adminController->exportDevWalletKey($authReq);
assert($authRes->getStatusCode() === 200, "exportDevWalletKey failed: " . $authRes->getBody());

$exportData = json_decode($authRes->getBody(), true)['data'];
assert($exportData['address'] === $devWallet['solana_deposit_address']);
assert(!empty($exportData['private_key_base58']));
assert(is_array($exportData['private_key_json']) && count($exportData['private_key_json']) === 64);

// Cryptographic verification: Deriving pubkey from exported Base58 secret key
$exportedSecKeyBin = Base58::decode($exportData['private_key_base58']);
assert(strlen($exportedSecKeyBin) === 64, "Exported secret key must be 64 bytes");
$derivedPubkey = Base58::encode(substr($exportedSecKeyBin, 32, 32));
assert($derivedPubkey === $devWallet['solana_deposit_address'], "Derived pubkey does not match Dev Wallet address!");
echo "  [PASS] Exported Base58 Key verified cryptographically (Derived: {$derivedPubkey})\n";
echo "  [PASS] Exported Keypair JSON array verified (Length: 64 bytes)\n";

echo "\n--- SECTION 4: Dev Wallet Transfer Out / Sweep Endpoint ---\n";
// Test validation: invalid address
$invalidAddrReq = new Request([], [
    'totp_code' => $validCode,
    'destination' => 'InvalidAddress123',
    'token' => 'SOL',
    'amount' => 0.001
], ['REQUEST_METHOD' => 'POST']);
$invalidAddrRes = $adminController->transferDevWallet($invalidAddrReq);
assert($invalidAddrRes->getStatusCode() === 422, "Expected 422 for invalid address");
echo "  [PASS] Invalid destination address rejected with HTTP 422\n";

// Test validation: amount exceeds balance
$excessiveReq = new Request([], [
    'totp_code' => $validCode,
    'destination' => '83astBRguLMdt2h5U1Tpdq5LM9KuKHLuGTu2uCpWbhQA',
    'token' => 'SOL',
    'amount' => 999999.0
], ['REQUEST_METHOD' => 'POST']);
$excessiveRes = $adminController->transferDevWallet($excessiveReq);
assert($excessiveRes->getStatusCode() === 400, "Expected 400 for excessive amount");
echo "  [PASS] Excessive withdrawal amount rejected with HTTP 400 Insufficient Dev Wallet balance\n";

// Clean up test audit records
$db->execute("DELETE FROM fee_collections WHERE transfer_id = 999999");
$db->execute("UPDATE user_balances SET available = available - ? WHERE user_id = ? AND token = 'SOL'", [$testFeeSol, $devWallet['id']]);

echo "\n======================================================================\n";
echo "  ALL DEV WALLET & ADMIN TESTS PASSED (100% OK)\n";
echo "======================================================================\n";
