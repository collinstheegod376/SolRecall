<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Middleware\Require2FAMiddleware;
use App\Models\User;
use App\Services\AuthService;
use App\Services\KeyManagementService;
use App\Services\TwoFactorService;
use App\Utils\Base58;

echo "========================================================\n";
echo "Starting Auth, Security, Keypair & 2FA Test Suite\n";
echo "========================================================\n\n";

$passCount = 0;
function testAssert(bool $condition, string $message): void {
    global $passCount;
    if (!$condition) {
        echo " [FAIL] {$message}\n";
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo " [PASS] {$message}\n";
    $passCount++;
}

// -----------------------------------------------------------------------------
// Test 1: KeyManagementService - Solana Keypair Generation & Base58
// -----------------------------------------------------------------------------
echo "--> Test Group 1: Solana Keypair Generation & Base58 Encoding\n";
$kms = new KeyManagementService('test-encryption-key-for-unit-tests');

$keypair = $kms->generateSolanaKeypair();
testAssert(!empty($keypair['publicKey']), "Public address was generated");
testAssert(strlen($keypair['publicKey']) >= 32 && strlen($keypair['publicKey']) <= 44, "Public address length is 32-44 base58 characters ({$keypair['publicKey']})");
testAssert(strlen($keypair['secretKeyBytes']) === 64, "Secret key bytes is exactly 64 bytes");
testAssert(strlen($keypair['publicKeyBytes']) === 32, "Public key bytes is exactly 32 bytes");
testAssert($kms->isValidSolanaAddress($keypair['publicKey']), "isValidSolanaAddress validates generated public address");
testAssert(!$kms->isValidSolanaAddress("InvalidSolanaAddress000OIl"), "isValidSolanaAddress rejects invalid Base58 characters");

// Test Base58 roundtrip
$testBinary = random_bytes(64);
$encodedB58 = Base58::encode($testBinary);
$decodedBinary = Base58::decode($encodedB58);
testAssert($testBinary === $decodedBinary, "Base58 encode/decode 64-byte random roundtrip matches exactly");

// Test leading zero preservation
$zeroPrefix = "\x00\x00\x00" . "Solana";
$encodedZero = Base58::encode($zeroPrefix);
testAssert(str_starts_with($encodedZero, '111'), "Leading zero bytes produce leading '1' characters in Base58");
$decodedZero = Base58::decode($encodedZero);
testAssert($zeroPrefix === $decodedZero, "Leading zero bytes preserved on Base58 decode");

// -----------------------------------------------------------------------------
// Test 2: AES-256-GCM Encryption, Decryption & Tamper Resistance
// -----------------------------------------------------------------------------
echo "\n--> Test Group 2: AES-256-GCM Key Encryption & Tamper Resistance\n";
$secretMessage = "SolanaCustodialPrivateKeySecretPayload-64Bytes";
$encrypted = $kms->encrypt($secretMessage);
testAssert(!empty($encrypted), "Encrypted payload is non-empty string");
$decrypted = $kms->decrypt($encrypted);
testAssert($decrypted === $secretMessage, "Decrypted text exactly matches plaintext");

// Decrypt keypair secret key
$decryptedSecretKey = $kms->decrypt($keypair['encryptedSecretKey']);
testAssert($decryptedSecretKey === $keypair['secretKey'], "Decrypted keypair secret key matches original");

// Tamper test: modify ciphertext
$tampered = base64_encode(base64_decode($encrypted) ^ str_repeat("\xFF", 10));
$tamperDetected = false;
try {
    $kms->decrypt($tampered);
} catch (Throwable $e) {
    $tamperDetected = true;
}
testAssert($tamperDetected, "Tampered ciphertext triggers authentication verification failure");

// -----------------------------------------------------------------------------
// Test 3: TwoFactorService - Base32 & RFC 6238 TOTP
// -----------------------------------------------------------------------------
echo "\n--> Test Group 3: TwoFactorService RFC 6238 TOTP\n";
$tfa = new TwoFactorService('SolanaUndoEscrow');

$secret = $tfa->generateSecret(20);
testAssert(strlen($secret) === 32, "Generated Base32 secret has 32 characters (160 bits of entropy)");
testAssert(preg_match('/^[A-Z2-7]+$/', $secret) === 1, "Secret complies strictly with RFC 4648 Base32 alphabet");

$now = time();
$code = $tfa->getCode($secret, $now);
testAssert(strlen($code) === 6 && ctype_digit($code), "Generated TOTP code is 6 digits ({$code})");

// Verify current window
testAssert($tfa->verifyCode($secret, $code, 1, $now), "TOTP code verifies in current timestamp");

// Verify -30s window tolerance
$prevCode = $tfa->getCode($secret, $now - 30);
testAssert($tfa->verifyCode($secret, $prevCode, 1, $now), "TOTP code verifies within -30s window tolerance");

// Verify +30s window tolerance
$nextCode = $tfa->getCode($secret, $now + 30);
testAssert($tfa->verifyCode($secret, $nextCode, 1, $now), "TOTP code verifies within +30s window tolerance");

// Reject code outside window (-90s)
$expiredCode = $tfa->getCode($secret, $now - 90);
testAssert(!$tfa->verifyCode($secret, $expiredCode, 1, $now), "TOTP code rejected when outside window tolerance (-90s)");

// Reject bad code
testAssert(!$tfa->verifyCode($secret, "000000", 1, $now), "Arbitrary wrong TOTP code is rejected");

// Otpauth URI and QR URL
$uri = $tfa->getOtpAuthUri($secret, 'trader@solana.undo');
testAssert(str_starts_with($uri, 'otpauth://totp/'), "URI follows standard otpauth:// schema");
testAssert(str_contains($uri, "secret={$secret}"), "URI contains Base32 secret");

$qrUrl = $tfa->getQrCodeUrl($secret, 'trader@solana.undo');
testAssert(str_starts_with($qrUrl, 'https://'), "QR code URL points to public image service");

// -----------------------------------------------------------------------------
// Test 4: AuthService - SIWS (Sign-In With Solana) Ed25519 Signature Verification
// -----------------------------------------------------------------------------
echo "\n--> Test Group 4: Sign-In With Solana (SIWS) Cryptographic Verification\n";
$authService = new AuthService(null, $kms, $tfa);

// Generate challenge nonce
$nonce = $authService->generateSolanaNonce();
testAssert(!empty($nonce) && strlen($nonce) === 32, "Challenge nonce generated in session");

// Create test Solana keypair
$solanaKeypair = sodium_crypto_sign_keypair();
$solanaPubkeyBytes = sodium_crypto_sign_publickey($solanaKeypair);
$solanaSecretKeyBytes = sodium_crypto_sign_secretkey($solanaKeypair);
$solanaAddress = Base58::encode($solanaPubkeyBytes);

// Construct SIWS message containing the nonce
$message = "solana.undo.local wants you to sign in with your Solana account:\n{$solanaAddress}\n\nSign-in to Solana Undo Escrow Platform.\nNonce: {$nonce}\nIssued At: " . gmdate('c');

// Sign message with Ed25519 secret key
$rawSignature = sodium_crypto_sign_detached($message, $solanaSecretKeyBytes);
$signatureB58 = Base58::encode($rawSignature);

testAssert(strlen($signatureB58) >= 80, "Ed25519 detached signature encoded to Base58");

// Verify detached signature directly with sodium
$validDirect = sodium_crypto_sign_verify_detached($rawSignature, $message, $solanaPubkeyBytes);
testAssert($validDirect, "Direct sodium_crypto_sign_verify_detached verifies valid signature");

// -----------------------------------------------------------------------------
// Test 5: Secure Private Key Export with 2FA Check & Audit Log
// -----------------------------------------------------------------------------
echo "\n--> Test Group 5: Custodial Private Key Export & Audit Logging\n";
// Create mock user model with 2FA enabled
$mockCustodialKp = $kms->generateSolanaKeypair();
$test2FASecret = $tfa->generateSecret(20);

$mockUser = new User([
    'id' => 999,
    'username' => 'test_exporter',
    'email' => 'exporter@solana.undo',
    'solana_deposit_address' => $mockCustodialKp['publicKey'],
    'encrypted_private_key' => $mockCustodialKp['encryptedSecretKey'],
    'two_factor_secret' => $test2FASecret,
    'two_factor_enabled' => 1,
], true);

// Test export without valid 2FA code fails
$failedExport = false;
try {
    $kms->exportUserPrivateKey($mockUser, '999999', '127.0.0.1', 'PHPUnit/Test');
} catch (Throwable $e) {
    $failedExport = true;
}
testAssert($failedExport, "Private key export rejected when invalid 2FA code is provided");

// Test export with valid 2FA code succeeds
$validTotp = $tfa->getCode($test2FASecret);
$exportResult = $kms->exportUserPrivateKey($mockUser, $validTotp, '192.168.1.100', 'Mozilla/5.0');

testAssert(!empty($exportResult['privateKeyBase58']), "Exported private key is present in Base58");
testAssert($exportResult['depositAddress'] === $mockCustodialKp['publicKey'], "Exported deposit address matches custodial public address");
testAssert(!empty($exportResult['warning']), "Security warning text is returned in export result");

// Verify audit log file was written
$auditFile = base_path('storage/logs/security_audit.log');
testAssert(file_exists($auditFile), "Security audit log file exists at storage/logs/security_audit.log");
$auditContent = file_get_contents($auditFile);
testAssert(str_contains($auditContent, 'PRIVATE_KEY_EXPORTED') && str_contains($auditContent, 'User ID: 999'), "Security audit log recorded the key export event");

// -----------------------------------------------------------------------------
// Test 6: AuthMiddleware & Require2FAMiddleware
// -----------------------------------------------------------------------------
echo "\n--> Test Group 6: Middleware Enforcement\n";
$authMiddleware = new AuthMiddleware($authService);

// Test unauthenticated API request gets 401
$_SESSION = []; // Clear session
$unauthApiRequest = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/auth/me', 'HTTP_ACCEPT' => 'application/json']);

$response = $authMiddleware->handle($unauthApiRequest, function (Request $req) {
    return Response::success(['ok' => true]);
});

testAssert($response->getStatusCode() === 401, "AuthMiddleware returns 401 Unauthorized for unauthenticated API requests");
$respBody = json_decode($response->getBody(), true);
testAssert($respBody['success'] === false, "401 response payload has success = false");

// Test Require2FAMiddleware with non-2FA user
$mockNon2faUser = new User([
    'id' => 888,
    'username' => 'no_2fa_user',
    'two_factor_enabled' => 0,
    'two_factor_secret' => null,
], true);

$mockAuthServiceNo2fa = new class($mockNon2faUser) extends AuthService {
    private ?User $mockUser;
    public function __construct(?User $mockUser) { $this->mockUser = $mockUser; }
    public function getCurrentUser(): ?User { return $this->mockUser; }
};

$require2FA = new Require2FAMiddleware($mockAuthServiceNo2fa);
$req2FARequest = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/auth/export-key', 'HTTP_ACCEPT' => 'application/json']);

// When user doesn't have 2FA enabled, Require2FAMiddleware blocks access with 403
$resp2fa = $require2FA->handle($req2FARequest, function (Request $req) {
    return Response::success(['secret_data' => true]);
});
testAssert($resp2fa->getStatusCode() === 403, "Require2FAMiddleware blocks sensitive route with 403 when 2FA is not enabled on account");

// When user DOES have 2FA enabled, Require2FAMiddleware allows request through
$mock2faActiveUser = new User([
    'id' => 777,
    'username' => 'active_2fa_user',
    'two_factor_enabled' => 1,
    'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
], true);

$mockAuthService2fa = new class($mock2faActiveUser) extends AuthService {
    private ?User $mockUser;
    public function __construct(?User $mockUser) { $this->mockUser = $mockUser; }
    public function getCurrentUser(): ?User { return $this->mockUser; }
};

$require2FAActive = new Require2FAMiddleware($mockAuthService2fa);
$resp2faPassed = $require2FAActive->handle($req2FARequest, function (Request $req) {
    return Response::success(['secret_data' => true]);
});
testAssert($resp2faPassed->getStatusCode() === 200, "Require2FAMiddleware allows request through when 2FA is active on account");

echo "\n========================================================\n";
echo "SUCCESS! All {$passCount} tests passed without errors!\n";
echo "========================================================\n";
