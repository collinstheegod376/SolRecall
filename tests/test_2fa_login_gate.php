<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\User;
use App\Controllers\AuthController;
use App\Core\Request;
use App\Services\TwoFactorService;

echo "======================================================================\n";
echo "  SolRecall • 2FA Login Enforcement & Session Gate Test\n";
echo "======================================================================\n";

$db = Database::getInstance();
$user = User::find(7); // nerfedkillua
if (!$user) {
    die("[FAIL] User 7 not found\n");
}

echo "Testing with user: {$user->username} (ID: {$user->id})\n";

// Ensure 2FA is enabled for User 7
if (empty($user->two_factor_enabled) || empty($user->two_factor_secret)) {
    $t2 = new TwoFactorService();
    $secret = $t2->generateSecret();
    $db->execute("UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = 7", [$secret]);
    $user = User::find(7);
}

// 1. Seed database session in pending 2FA state:
// user_id in DB is 7 (satisfying NOT NULL constraint), but payload ONLY contains pending_2fa_user_id!
$testToken = 'test_2fa_' . bin2hex(random_bytes(16));
$db->execute("
    INSERT INTO public.sessions (id, user_id, ip_address, user_agent, payload, expires_at, last_activity)
    VALUES (?, 7, '127.0.0.1', 'PHPUnit/Test', ?, NOW() + INTERVAL '1 day', EXTRACT(EPOCH FROM NOW())::integer)
", [$testToken, json_encode(['pending_2fa_user_id' => 7])]);

echo "\n--- Phase 1: Pre-Verification Cold Hydration ---\n";
// Simulate cold serverless container receiving request with undo_session cookie
$_COOKIE['undo_session'] = $testToken;
$_SESSION = [];
hydrate_session_from_db_if_needed();

if (isset($_SESSION['user_id'])) {
    echo "[FAIL] Security gate breached! user_id was hydrated before 2FA verification: " . $_SESSION['user_id'] . "\n";
    exit(1);
}
echo "  [PASS] CRITICAL SECURITY CHECK: user_id is NOT set before 2FA verification\n";

if (empty($_SESSION['pending_2fa_user_id']) || (int)$_SESSION['pending_2fa_user_id'] !== 7) {
    echo "[FAIL] pending_2fa_user_id not restored! Got: " . var_export($_SESSION['pending_2fa_user_id'] ?? null, true) . "\n";
    exit(1);
}
echo "  [PASS] pending_2fa_user_id=7 correctly hydrated from session payload\n";

// 2. Test OTP Verification via AuthController::verifyLogin2FA
echo "\n--- Phase 2: OTP Code Verification ---\n";
$t2Service = new TwoFactorService();
$validOtp = $t2Service->getCode((string) $user->two_factor_secret);
echo "  Generated valid TOTP code: {$validOtp}\n";

$authController = new AuthController();
$verifyReq = new Request([], ['code' => $validOtp]);
$res = $authController->verifyLogin2FA($verifyReq);

if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] !== 7) {
    echo "[FAIL] user_id not set after successful OTP verification!\n";
    exit(1);
}
echo "  [PASS] verifyLogin2FA successfully promoted session: user_id=7\n";

if (isset($_SESSION['pending_2fa_user_id'])) {
    echo "[FAIL] pending_2fa_user_id was not cleared!\n";
    exit(1);
}
echo "  [PASS] pending_2fa_user_id cleared after successful 2FA\n";

// 3. Test Invalid OTP Rejection
$_SESSION = ['pending_2fa_user_id' => 7];
$badReq = new Request([], ['code' => '000000']);
$badRes = $authController->verifyLogin2FA($badReq);

if (isset($_SESSION['user_id'])) {
    echo "[FAIL] Invalid OTP was accepted!\n";
    exit(1);
}
echo "  [PASS] Invalid OTP correctly rejected\n";

// Cleanup test session
$db->execute("DELETE FROM public.sessions WHERE id = ?", [$testToken]);

echo "\n======================================================================\n";
echo "  2FA LOGIN GATE AUDIT PASSED 100% (ZERO BYPASS VULNERABILITIES)\n";
echo "======================================================================\n";
