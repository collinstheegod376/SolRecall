<?php

require __DIR__ . '/../app/bootstrap.php';

echo "======================================================================" . PHP_EOL;
echo "  SolRecall • Serverless Session Persistence & Admin Hydration Test   " . PHP_EOL;
echo "======================================================================" . PHP_EOL;

$db = \App\Core\Database::getInstance();
$testToken = 'test_sess_' . bin2hex(random_bytes(16));
$userId = 7; // nerfedkillua (Admin)

// 1. Seed session into public.sessions in database
$db->execute("
    INSERT INTO public.sessions (id, user_id, ip_address, user_agent, payload, expires_at, last_activity)
    VALUES (?, ?, '127.0.0.1', 'PHPUnit/Test', ?, NOW() + INTERVAL '1 day', EXTRACT(EPOCH FROM NOW())::integer)
    ON CONFLICT (id) DO UPDATE SET user_id = EXCLUDED.user_id, expires_at = EXCLUDED.expires_at
", [$testToken, $userId, json_encode(['test_key' => 'verified'])]);

echo "[PASS] Seeded database session in public.sessions: ID = {$testToken}" . PHP_EOL;

// 2. Simulate Cold Serverless Container (no in-memory $_SESSION, no /tmp file)
// We set $_COOKIE['undo_session'] to the testToken
$_COOKIE['undo_session'] = $testToken;
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Call ensure_session_started() to simulate cold container boot
ensure_session_started();

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] !== 7) {
    echo "[FAIL] Cold container hydration failed! Expected user_id 7, got: " . var_export($_SESSION['user_id'] ?? null, true) . PHP_EOL;
    exit(1);
}
echo "[PASS] Cold container successfully hydrated \$_SESSION['user_id'] = 7 from PostgreSQL" . PHP_EOL;

if (empty($_SESSION['username']) || $_SESSION['username'] !== 'nerfedkillua') {
    echo "[FAIL] Username not hydrated! Got: " . var_export($_SESSION['username'] ?? null, true) . PHP_EOL;
    exit(1);
}
echo "[PASS] User profile hydrated: username = " . $_SESSION['username'] . PHP_EOL;

if (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) {
    echo "[FAIL] is_admin not hydrated! Got: " . var_export($_SESSION['is_admin'] ?? null, true) . PHP_EOL;
    exit(1);
}
echo "[PASS] Admin privileges hydrated: is_admin = 1" . PHP_EOL;

// 3. Test AdminController endpoints with hydrated session
$adminCtrl = new \App\Controllers\AdminController();
$req = \App\Core\Request::createFromGlobals();

$statsRes = $adminCtrl->stats($req);
if ($statsRes->getStatusCode() !== 200) {
    echo "[FAIL] Admin stats returned status " . $statsRes->getStatusCode() . PHP_EOL;
    exit(1);
}
echo "[PASS] AdminController::stats() authorized with HTTP 200 OK" . PHP_EOL;

$transfersRes = $adminCtrl->transfers($req);
if ($transfersRes->getStatusCode() !== 200) {
    echo "[FAIL] Admin transfers returned status " . $transfersRes->getStatusCode() . PHP_EOL;
    exit(1);
}
echo "[PASS] AdminController::transfers() authorized with HTTP 200 OK" . PHP_EOL;

$devWalletRes = $adminCtrl->devWallet($req);
if ($devWalletRes->getStatusCode() !== 200) {
    echo "[FAIL] Admin dev-wallet returned status " . $devWalletRes->getStatusCode() . PHP_EOL;
    exit(1);
}
echo "[PASS] AdminController::devWallet() authorized with HTTP 200 OK" . PHP_EOL;

// 4. Test Bearer Header fallback without any cookies
unset($_COOKIE['undo_session'], $_COOKIE['PHPSESSID']);
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$bearerReq = new \App\Core\Request([], [], [
    'REQUEST_METHOD' => 'GET',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $testToken,
]);

$bearerDevRes = $adminCtrl->devWallet($bearerReq);
if ($bearerDevRes->getStatusCode() !== 200) {
    echo "[FAIL] Bearer header authentication failed! Got: " . $bearerDevRes->getStatusCode() . PHP_EOL;
    exit(1);
}
echo "[PASS] AdminController::devWallet() authorized via Bearer header with HTTP 200 OK" . PHP_EOL;

// 5. Test Unauthenticated rejection returns HTTP 401
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$unauthReq = new \App\Core\Request([], [], ['REQUEST_METHOD' => 'GET']);
$unauthRes = $adminCtrl->stats($unauthReq);
if ($unauthRes->getStatusCode() !== 401) {
    echo "[FAIL] Unauthenticated request expected 401, got: " . $unauthRes->getStatusCode() . PHP_EOL;
    exit(1);
}
echo "[PASS] Unauthenticated request correctly rejected with HTTP 401 Unauthorized" . PHP_EOL;

// Cleanup
$db->execute("DELETE FROM public.sessions WHERE id = ?", [$testToken]);
echo "[PASS] Cleaned up test session." . PHP_EOL;

echo "======================================================================" . PHP_EOL;
echo "  ALL SERVERLESS SESSION TESTS PASSED (100% OK)                       " . PHP_EOL;
echo "======================================================================" . PHP_EOL;
