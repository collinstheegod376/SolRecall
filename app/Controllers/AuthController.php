<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Models\User;
use App\Services\AuthService;
use App\Services\KeyManagementService;
use App\Services\TwoFactorService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * AuthController
 *
 * Provides RESTful API endpoints for registration, password authentication,
 * Solana Wallet Adapter (SIWS), Google OAuth, 2FA management, and custodial key export.
 */
class AuthController extends Controller
{
    private AuthService $authService;
    private KeyManagementService $keyService;
    private TwoFactorService $twoFactorService;

    public function __construct(
        ?AuthService $authService = null,
        ?KeyManagementService $keyService = null,
        ?TwoFactorService $twoFactorService = null
    ) {
        $this->keyService = $keyService ?? new KeyManagementService();
        $this->twoFactorService = $twoFactorService ?? new TwoFactorService();
        $this->authService = $authService ?? new AuthService(null, $this->keyService, $this->twoFactorService);
    }

    // =========================================================================
    // 1. POST /api/auth/register
    // =========================================================================

    /**
     * Handle user account registration.
     */
    public function register(Request $request): Response
    {
        try {
            $username = (string) ($request->input('username') ?? $request->input('name') ?? '');
            $email = (string) ($request->input('email') ?? '');
            $password = (string) ($request->input('password') ?? '');

            if ($username === '' || strlen($username) < 3) {
                return $this->jsonError('The username field is required and must be at least 3 characters.', 422);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->jsonError('A valid email address is required.', 422);
            }
            if (strlen($password) < 8) {
                return $this->jsonError('The password field must be at least 8 characters.', 422);
            }

            $user = $this->authService->register($username, $email, $password);

            // Automatically log in on successful registration
            $this->authService->login($user);

            $balances = $user->getBalances();
            $formattedBalances = [];
            foreach ($balances as $token => $b) {
                $formattedBalances[$token] = [
                    'available' => (string) $b->available,
                    'escrow' => (string) $b->escrow,
                ];
            }

            return $this->jsonSuccess([
                'user' => $user->toSafeArray(),
                'deposit_address' => $user->solana_deposit_address,
                'balances' => $formattedBalances,
            ], 'Account registered successfully.', 201);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->jsonError('Registration failed: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 2. POST /api/auth/login
    // =========================================================================

    /**
     * Handle user credential authentication.
     */
    public function login(Request $request): Response
    {
        try {
            $login = (string) ($request->input('login') ?? $request->input('email') ?? $request->input('username') ?? '');
            $password = (string) ($request->input('password') ?? '');

            if ($login === '' || $password === '') {
                return $this->jsonError('Login identifier (email or username) and password are required.', 422);
            }

            $cleanLogin = trim($login);
            $user = filter_var($cleanLogin, FILTER_VALIDATE_EMAIL)
                ? User::findByEmail($cleanLogin)
                : User::findByUsername($cleanLogin);

            if ($user === null || !$user->verifyPassword($password)) {
                return $this->jsonError('Invalid credentials. Please verify your username/email and password.', 401);
            }

            // Direct 2FA verification if code provided in initial request
            $twoFactorCode = $request->input('code') ?? $request->input('two_factor_code');
            if ($twoFactorCode !== null && trim((string) $twoFactorCode) !== '') {
                $cleanCode = trim(str_replace([' ', '-'], '', (string) $twoFactorCode));
                if (!empty($user->two_factor_enabled)) {
                    if (empty($user->two_factor_secret) || !$this->twoFactorService->verifyCode((string) $user->two_factor_secret, $cleanCode)) {
                        return $this->jsonError('Invalid or expired authentication code', 400);
                    }
                }
            } elseif (!empty($user->two_factor_enabled)) {
                // Split 2FA Login into Dedicated 2-Step Flow
                ensure_session_started();
                $_SESSION['pending_2fa_user_id'] = (int) $user->id;
                // DO NOT set $_SESSION['user_id'] yet.
                unset($_SESSION['user_id']);

                return Response::success([
                    'requires_2fa' => true,
                    'redirect' => '/two-factor-verify',
                ], '2FA verification required');
            }

            // If two_factor_enabled is 0 (or direct code verified), log in normally
            $this->authService->login($user);

            $balances = $user->getBalances();
            $formattedBalances = [];
            foreach ($balances as $token => $b) {
                $formattedBalances[$token] = [
                    'available' => (string) $b->available,
                    'escrow' => (string) $b->escrow,
                ];
            }

            return $this->jsonSuccess([
                'user' => $user->toSafeArray(),
                'deposit_address' => $user->solana_deposit_address,
                'balances' => $formattedBalances,
                'session_token' => session_id(),
            ], 'Login successful.');
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 401);
        } catch (Throwable $e) {
            return $this->jsonError('Authentication error: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 3. POST /api/auth/logout
    // =========================================================================

    /**
     * Terminate active session and clear authentication cookies.
     */
    public function logout(Request $request): Response
    {
        $this->authService->logout();
        return $this->jsonSuccess(null, 'Logged out successfully.');
    }

    // =========================================================================
    // 4. GET /api/auth/me
    // =========================================================================

    /**
     * Retrieve the authenticated user profile, deposit address, 2FA status, and balances.
     */
    public function me(Request $request): Response
    {
        $user = $this->authService->getCurrentUser();
        if ($user === null) {
            return $this->jsonError('Unauthorized. Session not authenticated.', 401);
        }

        $balances = $user->getBalances();
        $formattedBalances = [];
        foreach ($balances as $token => $b) {
            $formattedBalances[$token] = [
                'available' => (string) $b->available,
                'escrow' => (string) $b->escrow,
            ];
        }

        return $this->jsonSuccess([
            'user' => $user->toSafeArray(),
            'deposit_address' => $user->solana_deposit_address,
            'wallet_address' => $user->wallet_address,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'balances' => $formattedBalances,
        ], 'User profile retrieved successfully.');
    }

    // =========================================================================
    // 5. GET /api/auth/solana-nonce
    // =========================================================================

    /**
     * Generate Sign-In With Solana (SIWS) challenge nonce.
     */
    public function getSolanaNonce(Request $request): Response
    {
        $nonce = $this->authService->generateSolanaNonce();
        $domain = parse_url((string) config('app.url', 'http://localhost:8000'), PHP_URL_HOST) ?: 'localhost';
        $address = (string) ($request->input('address') ?? $request->input('publicKey') ?? '');

        $statement = "Sign this message to authenticate with Undo Window platform.\n\nNonce: {$nonce}\nTimestamp: " . time();
        if ($address !== '') {
            $statement = "{$domain} wants you to sign in with your Solana account:\n{$address}\n\nSign-in to Solana Undo Escrow Platform.\nNonce: {$nonce}\nIssued At: " . gmdate('c');
        }

        return Response::json([
            'success' => true,
            'nonce' => $nonce,
            'message' => $statement,
            'data' => [
                'nonce' => $nonce,
                'message' => $statement,
                'domain' => $domain,
                'statement' => $statement,
                'issued_at' => gmdate('c'),
            ],
        ]);
    }

    // =========================================================================
    // 6. POST /api/auth/solana-verify
    // =========================================================================

    /**
     * Cryptographically verify Solana wallet signature and authenticate session.
     */
    public function verifySolanaAuth(Request $request): Response
    {
        try {
            $publicKey = (string) ($request->input('publicKey') ?? $request->input('address') ?? '');
            $signature = (string) ($request->input('signature') ?? '');
            $message = (string) ($request->input('message') ?? '');

            if ($publicKey === '' || !$this->keyService->isValidSolanaAddress($publicKey)) {
                return $this->jsonError('A valid Solana wallet address (publicKey) is required.', 422);
            }
            if ($signature === '') {
                return $this->jsonError('A valid Solana signature is required.', 422);
            }
            if ($message === '') {
                return $this->jsonError('The signed message is required.', 422);
            }

            $user = $this->authService->verifySolanaSignature($publicKey, $signature, $message);

            if (!empty($user->two_factor_enabled)) {
                ensure_session_started();
                $_SESSION['pending_2fa_user_id'] = (int) $user->id;
                unset($_SESSION['user_id']);

                return Response::success([
                    'requires_2fa' => true,
                    'redirect' => '/two-factor-verify',
                ], 'Two-factor authentication code required.');
            }

            $balances = $user->getBalances();
            $formattedBalances = [];
            foreach ($balances as $token => $b) {
                $formattedBalances[$token] = [
                    'available' => (string) $b->available,
                    'escrow' => (string) $b->escrow,
                ];
            }

            return $this->jsonSuccess([
                'user' => $user->toSafeArray(),
                'deposit_address' => $user->solana_deposit_address,
                'wallet_address' => $user->wallet_address,
                'balances' => $formattedBalances,
            ], 'Solana wallet verified and authenticated successfully.');
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->jsonError('Solana authentication failed: ' . $e->getMessage(), 400);
        }
    }

    // =========================================================================
    // 7. POST /api/auth/google
    // =========================================================================

    /**
     * Verify Google OAuth ID token and authenticate or create account.
     */
    public function googleAuth(Request $request): Response
    {
        try {
            $data = $this->validate($request, [
                'credential' => 'required|string',
            ]);

            $user = $this->authService->verifyGoogleToken((string) $data['credential']);

            return $this->jsonSuccess([
                'user' => $user->toSafeArray(),
                'deposit_address' => $user->solana_deposit_address,
            ], 'Google authentication successful.');
        } catch (ValidationException $e) {
            return $this->jsonError('Validation failed.', 422, $e->getErrors());
        } catch (Throwable $e) {
            return $this->jsonError('Google authentication failed: ' . $e->getMessage(), 400);
        }
    }

    // =========================================================================
    // 8. POST /api/auth/2fa/setup
    // =========================================================================

    /**
     * Initiate Two-Factor Authentication setup for the logged-in user.
     * Generates a fresh Base32 secret, otpauth:// URI, and QR code image URL.
     */
    public function setup2FA(Request $request): Response
    {
        $user = $this->authService->getCurrentUser();
        if ($user === null) {
            return $this->jsonError('Unauthorized. Login required.', 401);
        }

        ensure_session_started();

        $secret = $this->twoFactorService->generateSecret(20);
        $_SESSION['pending_2fa_secret'] = $secret;

        $accountLabel = !empty($user->email) ? (string) $user->email : (string) $user->username;
        $otpauth = $this->twoFactorService->getOtpAuthUri($secret, $accountLabel);
        $qrCodeUrl = $this->twoFactorService->getQrCodeUrl($secret, $accountLabel);

        return $this->jsonSuccess([
            'secret' => $secret,
            'otpauth_uri' => $otpauth,
            'qr_code_url' => $qrCodeUrl,
        ], 'Two-Factor setup secret generated.');
    }

    // =========================================================================
    // 9. POST /api/auth/2fa/verify
    // =========================================================================

    /**
     * Verify TOTP code to finalize 2FA activation OR complete pending 2FA login.
     */
    public function verify2FA(Request $request): Response
    {
        try {
            $data = $this->validate($request, [
                'code' => 'required|string',
            ]);
            $code = (string) $data['code'];

            ensure_session_started();

            // Case A: Completing 2FA login challenge with temp_token
            $tempToken = $request->input('temp_token');
            if (!empty($tempToken)) {
                $user = $this->authService->complete2FALogin((string) $tempToken, $code);
                return $this->jsonSuccess([
                    'user' => $user->toSafeArray(),
                    'deposit_address' => $user->solana_deposit_address,
                ], 'Two-Factor login completed successfully.');
            }

            // Case B: Finalizing 2FA activation for active user session
            $user = $this->authService->getCurrentUser();
            if ($user === null) {
                return $this->jsonError('Unauthorized. Login required.', 401);
            }

            $pendingSecret = $_SESSION['pending_2fa_secret'] ?? null;
            if (!$pendingSecret) {
                return $this->jsonError('No 2FA setup process was initiated. Please request setup first.', 400);
            }

            if (!$this->twoFactorService->verifyCode($pendingSecret, $code)) {
                return $this->jsonError('Invalid 2FA verification code. Please check your authenticator app and try again.', 400);
            }

            // Persist 2FA secret and activate
            $user->two_factor_secret = $pendingSecret;
            $user->two_factor_enabled = 1;
            $user->save();

            $_SESSION['two_factor_enabled'] = 1;
            unset($_SESSION['pending_2fa_secret']);

            return $this->jsonSuccess([
                'two_factor_enabled' => true,
            ], 'Two-Factor Authentication successfully enabled on your account.');
        } catch (ValidationException $e) {
            return $this->jsonError('Validation failed.', 422, $e->getErrors());
        } catch (Throwable $e) {
            return $this->jsonError('2FA verification failed: ' . $e->getMessage(), 400);
        }
    }

    // =========================================================================
    // 9b. POST /api/auth/2fa/verify-login
    // =========================================================================

    /**
     * Validate 6-digit TOTP code for pending 2FA login session and complete authentication.
     */
    public function verifyLogin2FA(Request $request): Response
    {
        try {
            ensure_session_started();

            $pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
            if (!$pendingUserId) {
                return $this->jsonError('Invalid or expired authentication code', 400);
            }

            $code = (string) ($request->input('code') ?? $request->input('otp') ?? $request->input('two_factor_code') ?? '');
            $cleanCode = trim(str_replace([' ', '-'], '', $code));

            if (strlen($cleanCode) !== 6 || !ctype_digit($cleanCode)) {
                return $this->jsonError('Invalid or expired authentication code', 400);
            }

            $user = User::find((int) $pendingUserId);
            if ($user === null || empty($user->two_factor_secret)) {
                return $this->jsonError('Invalid or expired authentication code', 400);
            }

            if (!$this->twoFactorService->verifyCode((string) $user->two_factor_secret, $cleanCode)) {
                return $this->jsonError('Invalid or expired authentication code', 400);
            }

            // On success: sets full $_SESSION['user_id'] and user session
            $this->authService->login($user);

            // Unsets pending_2fa_user_id
            unset($_SESSION['pending_2fa_user_id']);

            $balances = $user->getBalances();
            $formattedBalances = [];
            foreach ($balances as $token => $b) {
                $formattedBalances[$token] = [
                    'available' => (string) $b->available,
                    'escrow' => (string) $b->escrow,
                ];
            }

            return Response::success([
                'redirect' => '/dashboard',
                'user' => $user->toSafeArray(),
                'deposit_address' => $user->solana_deposit_address,
                'balances' => $formattedBalances,
                'session_token' => session_id(),
            ], '2FA verification successful.');
        } catch (Throwable $e) {
            return $this->jsonError('Invalid or expired authentication code', 400);
        }
    }

    // =========================================================================
    // 9c. POST /api/auth/2fa/disable
    // =========================================================================

    /**
     * Self-service delete / reset 2FA authentication key and disable 2FA.
     */
    public function disable2FA(Request $request): Response
    {
        try {
            $user = $this->authService->getCurrentUser();
            if ($user === null) {
                return $this->jsonError('Unauthorized. Login required.', 401);
            }

            $password = (string) ($request->input('password') ?? '');
            $code = (string) ($request->input('code') ?? $request->input('totp') ?? $request->input('two_factor_code') ?? '');

            $verified = false;

            // If account has a password_hash and password was provided, verify password
            if (!empty($user->password_hash) && $password !== '') {
                if (!$user->verifyPassword($password)) {
                    return $this->jsonError('Incorrect account password. Verification failed.', 403);
                }
                $verified = true;
            }

            // If 2FA code was provided, verify against current secret
            if ($code !== '') {
                $cleanCode = trim(str_replace([' ', '-'], '', $code));
                if (!empty($user->two_factor_secret) && !$this->twoFactorService->verifyCode((string) $user->two_factor_secret, $cleanCode)) {
                    return $this->jsonError('Invalid or expired authentication code', 400);
                }
                $verified = true;
            }

            // Requires current password AND/OR 6-digit 2FA code to verify authorization
            if (!$verified) {
                return $this->jsonError('Please provide your current password and/or 6-digit 2FA code to confirm deletion.', 400);
            }

            // Updates DB: UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?
            \App\Core\Database::getInstance()->execute(
                "UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?",
                [(int) $user->id]
            );

            // Update user instance and session
            $user->two_factor_enabled = 0;
            $user->two_factor_secret = null;

            ensure_session_started();
            $_SESSION['two_factor_enabled'] = 0;

            return $this->jsonSuccess([
                'two_factor_enabled' => false,
            ], 'Two-Factor Authentication key deleted successfully.');
        } catch (Throwable $e) {
            return $this->jsonError('Failed to disable 2FA: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 10. POST /api/auth/export-key
    // =========================================================================

    /**
     * Securely export the user's custodial private key with mandatory 2FA check and audit logging.
     */
    public function exportKey(Request $request): Response
    {
        $user = $this->authService->getCurrentUser();
        if ($user === null) {
            return $this->jsonError('Unauthorized. Login required.', 401);
        }

        try {
            $data = $this->validate($request, [
                'code' => 'required|string',
            ]);

            // Optional password verification if provided and user has a password hash
            $password = (string) ($request->input('password') ?? '');
            if ($password !== '' && !empty($user->password_hash)) {
                if (!$user->verifyPassword($password)) {
                    return $this->jsonError('Incorrect account password. Verification failed.', 403);
                }
            }

            $result = $this->keyService->exportUserPrivateKey(
                $user,
                (string) $data['code'],
                $request->ip(),
                $request->userAgent()
            );

            // Ensure aliases are present in the response
            $result['private_key'] = $result['privateKeyBase58'];
            $result['secret_key'] = $result['privateKeyBase58'];
            $result['deposit_address'] = $result['depositAddress'];

            return $this->jsonSuccess($result, 'Custodial private key exported securely.');
        } catch (ValidationException $e) {
            return $this->jsonError('Validation failed.', 422, $e->getErrors());
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 403);
        }
    }
}
