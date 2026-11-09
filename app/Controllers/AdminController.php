<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\KeyManagementService;
use App\Services\SolanaRpcService;
use App\Services\TransferService;

/**
 * Administrator Operations Controller
 * Handles platform escrow ledger, hot wallet monitoring, force release/cancel, and manual broadcaster triggers.
 */
class AdminController extends Controller
{
    private Database $db;
    private SolanaRpcService $rpcService;
    private KeyManagementService $keyService;
    private TransferService $transferService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->rpcService = new SolanaRpcService();
        $this->keyService = new KeyManagementService();
        $this->transferService = new TransferService($this->db, $this->rpcService, $this->keyService);
    }

    /**
     * Resolve the active authenticated user ID.
     */
    protected function getAuthenticatedUserId(Request $request): ?int
    {
        ensure_session_started();
        if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        $authHeader = $request->header('authorization');
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $sessionRow = $this->db->fetch(
                    'SELECT user_id FROM public.sessions WHERE id = ? AND expires_at > NOW()',
                    [$token]
                );
                if ($sessionRow && !empty($sessionRow['user_id'])) {
                    return (int) $sessionRow['user_id'];
                }
            } catch (\Throwable) {}
        }

        return null;
    }

    /**
     * Enforce administrator access. Returns admin user row or null.
     */
    private function requireAdmin(Request $request): ?array
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return null;
        }

        $user = $this->db->fetch('SELECT id, username, email, is_admin, password_hash, two_factor_enabled, two_factor_secret FROM users WHERE id = ?', [$userId]);
        if (!$user || (int) ($user['is_admin'] ?? 0) !== 1) {
            return null;
        }

        return $user;
    }

    /**
     * GET /api/admin/stats
     * Live platform-wide metrics & hot wallet balances.
     */
    public function stats(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        // 1. Hot Wallet Solana balance & address
        $hotWalletAddress = $this->keyService->getHotWalletAddress();
        $hotWalletSol = 0.0;
        try {
            $hotWalletSol = $this->rpcService->getBalance($hotWalletAddress);
        } catch (\Throwable) {
            // Non-blocking fallback
        }

        // 2. Active Escrow totals (grouped by token)
        $escrowRows = $this->db->fetchAll(
            "SELECT token, SUM(amount) as total_amount, COUNT(*) as count 
             FROM pending_transfers 
             WHERE status = 'pending' 
             GROUP BY token"
        );
        $activeEscrow = [
            'SOL' => 0.0,
            'USDC' => 0.0,
            'USDT' => 0.0,
            'total_holds' => 0
        ];
        foreach ($escrowRows as $row) {
            $tok = strtoupper((string) $row['token']);
            $activeEscrow[$tok] = (float) $row['total_amount'];
            $activeEscrow['total_holds'] += (int) $row['count'];
        }

        // 3. Platform stats & revenue
        $userCount = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM users");
        $completedTransfers = $this->db->fetchAll(
            "SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total_volume 
             FROM pending_transfers 
             WHERE status = 'completed'"
        );
        $completedCount = (int) ($completedTransfers[0]['count'] ?? 0);

        // Platform fees (12% of completed transfers)
        $feeRevenueSol = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount * 0.12), 0) FROM pending_transfers WHERE status = 'completed' AND token = 'SOL'"
        ) ?? 0);
        $feeRevenueStables = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount * 0.12), 0) FROM pending_transfers WHERE status = 'completed' AND token IN ('USDC', 'USDT')"
        ) ?? 0);

        return Response::success([
            'hot_wallet' => [
                'address' => $hotWalletAddress,
                'sol_balance' => $hotWalletSol,
                'network' => config('solana.cluster', 'mainnet-beta'),
                'status' => 'healthy'
            ],
            'active_escrow' => $activeEscrow,
            'dev_wallet' => (function() {
                try {
                    $devUser = TransferService::getOrCreateDevWallet($this->db, $this->keyService);
                    $balances = $this->db->fetchAll("SELECT token, available FROM user_balances WHERE user_id = ?", [(int) $devUser['id']]);
                    $bMap = ['SOL' => 0.0, 'USDC' => 0.0, 'USDT' => 0.0];
                    foreach ($balances as $b) {
                        $bMap[strtoupper((string) $b['token'])] = (float) $b['available'];
                    }
                    return [
                        'address' => $devUser['solana_deposit_address'],
                        'balances' => $bMap
                    ];
                } catch (\Throwable) {
                    return null;
                }
            })(),
            'platform' => [
                'total_users' => $userCount,
                'completed_transfers' => $completedCount,
                'fee_revenue_sol' => $feeRevenueSol,
                'fee_revenue_stables' => $feeRevenueStables,
                'cron_schedule' => 'Every 1 minute (Supabase pg_cron + opportunistic)'
            ]
        ]);
    }

    /**
     * GET /api/admin/transfers
     * List all platform-wide pending escrow transfers with full user information.
     */
    public function transfers(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        $sql = "SELECT pt.*, u.username, u.email, u.solana_deposit_address 
                FROM pending_transfers pt 
                JOIN users u ON pt.user_id = u.id 
                WHERE pt.status = 'pending' 
                ORDER BY pt.expires_at ASC";

        $rows = $this->db->fetchAll($sql);
        $now = time();

        $formatted = array_map(function ($row) use ($now) {
            $expiresTimestamp = strtotime((string) $row['expires_at']);
            $remaining = max(0, $expiresTimestamp - $now);
            $totalWindow = max(1, $expiresTimestamp - strtotime((string) $row['created_at']));
            $progressPercent = min(100, max(0, (int) round((($totalWindow - $remaining) / $totalWindow) * 100)));

            return [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'token' => $row['token'],
                'amount' => (float) $row['amount'],
                'platform_fee' => round((float) $row['amount'] * 0.12, 6),
                'destination' => $row['destination'],
                'note' => $row['note'] ?? '',
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'seconds_remaining' => $remaining,
                'total_seconds' => $totalWindow,
                'progress_percent' => $progressPercent,
                'is_expired' => ($remaining <= 0)
            ];
        }, $rows);

        return Response::success([
            'transfers' => $formatted,
            'count' => count($formatted)
        ]);
    }

    /**
     * POST /api/admin/trigger-cron
     * Trigger the full escrow broadcaster cycle and deposit watcher on-chain.
     */
    public function triggerCron(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        $startTime = microtime(true);

        // 1. Process expired pending transfers using unified settlement engine
        $processed = $this->transferService->processExpiredTransfers();

        // 2. Scan and credit deposits
        $users = $this->db->fetchAll("SELECT id FROM users WHERE encrypted_private_key IS NOT NULL AND id != 13 AND solana_deposit_address IS NOT NULL AND solana_deposit_address != ''");
        $depositsCredited = 0;
        foreach ($users as $u) {
            try {
                $depositsCredited += $this->transferService->syncUserDeposits((int) $u['id']);
            } catch (\Throwable) {}
        }

        // Invalidate balance micro-caches
        TransferService::invalidateBalanceCache();

        return Response::success([
            'message' => 'Escrow broadcaster and deposit scan executed successfully.',
            'processed' => $processed,
            'deposits_credited' => $depositsCredited,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
    }

    /**
     * POST /api/admin/force-cancel
     * Force-cancel and refund any user's held transfer immediately.
     */
    public function forceCancel(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        $transferId = (int) $request->input('transfer_id');
        if ($transferId <= 0) {
            return Response::error('Valid transfer ID required.', 422);
        }

        $transfer = $this->db->fetch('SELECT * FROM pending_transfers WHERE id = ?', [$transferId]);
        if (!$transfer) {
            return Response::error('Transfer not found.', 404);
        }

        if ($transfer['status'] !== 'pending') {
            return Response::error("Cannot cancel transfer with status '{$transfer['status']}'.", 400);
        }

        $userId = (int) $transfer['user_id'];
        $token = (string) $transfer['token'];
        $amount = (float) $transfer['amount'];
        $platformFee = round($amount * 0.12, 6);
        $gasFee = 0.000005;

        $this->db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $platformFee, $gasFee) {
            $db->execute(
                "UPDATE pending_transfers SET status = 'cancelled', failure_reason = 'Cancelled by administrator override' WHERE id = ?",
                [$transferId]
            );

            // Refund principal from escrow back to available, plus refund platform fee and gas
            if ($token === 'SOL') {
                $refundTotal = round($amount + $platformFee + $gasFee, 6);
                $db->execute(
                    "UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = 'SOL'",
                    [$amount, $refundTotal, $userId]
                );
            } else {
                $refundTotal = round($amount + $platformFee, 6);
                $db->execute(
                    "UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = ?",
                    [$amount, $refundTotal, $userId, $token]
                );
                $db->execute(
                    "UPDATE user_balances SET available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = 'SOL'",
                    [$gasFee, $userId]
                );
            }
        });

        TransferService::invalidateBalanceCache($userId);

        return Response::success([
            'message' => "Transfer #{$transferId} force-cancelled and refunded successfully to user #{$userId}."
        ]);
    }

    /**
     * POST /api/admin/force-broadcast
     * Force immediate on-chain broadcast of a pending transfer without waiting for timer expiry.
     */
    public function forceBroadcast(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        $transferId = (int) $request->input('transfer_id');
        if ($transferId <= 0) {
            return Response::error('Valid transfer ID required.', 422);
        }

        $transfer = $this->db->fetch('SELECT pt.*, u.encrypted_private_key FROM pending_transfers pt JOIN users u ON pt.user_id = u.id WHERE pt.id = ?', [$transferId]);
        if (!$transfer) {
            return Response::error('Transfer not found.', 404);
        }

        if ($transfer['status'] !== 'pending') {
            return Response::error("Cannot broadcast transfer with status '{$transfer['status']}'.", 400);
        }

        $userId = (int) $transfer['user_id'];
        $token = strtoupper((string) $transfer['token']);
        $amount = (float) $transfer['amount'];
        $destination = (string) $transfer['destination'];

        // Mark processing
        $this->db->execute("UPDATE pending_transfers SET status = 'processing' WHERE id = ?", [$transferId]);

        try {
            $userSigningKey = $this->keyService->resolveSigningKey($transfer['encrypted_private_key'] ?? null);
            $hotWalletKey = $this->keyService->resolveSigningKey(null);

            $txSignature = $this->rpcService->broadcastTransfer($userSigningKey, $destination, $amount, $token, $hotWalletKey);

            $this->db->execute(
                "UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), updated_at = NOW() WHERE user_id = ? AND token = ?",
                [$amount, $userId, $token]
            );

            $this->db->execute(
                "UPDATE pending_transfers SET status = 'completed', tx_signature = ?, processed_at = NOW() WHERE id = ?",
                [$txSignature, $transferId]
            );

            $platformFee = round($amount * 0.12, 6);
            TransferService::creditPlatformFee($this->db, $transferId, $token, $platformFee, "Force broadcast for transfer #{$transferId}");

            TransferService::invalidateBalanceCache($userId);

            return Response::success([
                'message' => "Transfer #{$transferId} successfully broadcast on Solana Mainnet.",
                'tx_signature' => $txSignature
            ]);
        } catch (\Throwable $e) {
            // Revert back to pending
            $this->db->execute("UPDATE pending_transfers SET status = 'pending' WHERE id = ?", [$transferId]);
            return Response::error('Broadcast error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/users
     * Directory of registered users with balances and deposit addresses.
     */
    public function users(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        $sql = "SELECT u.id, u.username, u.email, u.solana_deposit_address, u.is_admin, u.two_factor_enabled, u.created_at,
                       COALESCE((SELECT available FROM user_balances WHERE user_id = u.id AND token = 'SOL'), 0) as sol_avail,
                       COALESCE((SELECT escrow FROM user_balances WHERE user_id = u.id AND token = 'SOL'), 0) as sol_escrow,
                       COALESCE((SELECT available FROM user_balances WHERE user_id = u.id AND token = 'USDC'), 0) as usdc_avail,
                       COALESCE((SELECT available FROM user_balances WHERE user_id = u.id AND token = 'USDT'), 0) as usdt_avail
                FROM users u 
                ORDER BY u.id ASC";

        $users = $this->db->fetchAll($sql);

        return Response::success([
            'users' => $users,
            'count' => count($users)
        ]);
    }

    /**
     * GET /api/admin/dev-wallet
     * Retrieve the Dev Wallet (Platform Fee Collector) status, address, balances, and fee history.
     */
    public function devWallet(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        $devUser = TransferService::getOrCreateDevWallet($this->db, $this->keyService);
        $devUserId = (int) $devUser['id'];
        $devAddress = (string) $devUser['solana_deposit_address'];

        // Get DB ledger balances
        $balanceRows = $this->db->fetchAll(
            "SELECT token, available, escrow FROM user_balances WHERE user_id = ?",
            [$devUserId]
        );
        $balances = [
            'SOL' => ['available' => 0.0, 'escrow' => 0.0, 'on_chain' => 0.0],
            'USDC' => ['available' => 0.0, 'escrow' => 0.0],
            'USDT' => ['available' => 0.0, 'escrow' => 0.0],
        ];
        foreach ($balanceRows as $row) {
            $tok = strtoupper((string) $row['token']);
            if (isset($balances[$tok])) {
                $balances[$tok]['available'] = (float) $row['available'];
                $balances[$tok]['escrow'] = (float) $row['escrow'];
            }
        }

        // Query live on-chain SOL balance
        try {
            $balances['SOL']['on_chain'] = $this->rpcService->getBalance($devAddress);
        } catch (\Throwable) {
            $balances['SOL']['on_chain'] = 0.0;
        }

        // Query total collected fees by token
        $feeRows = $this->db->fetchAll(
            "SELECT token, SUM(amount) as total FROM fee_collections WHERE type = 'fee_collected' GROUP BY token"
        );
        $totalFees = ['SOL' => 0.0, 'USDC' => 0.0, 'USDT' => 0.0];
        foreach ($feeRows as $row) {
            $tok = strtoupper((string) $row['token']);
            $totalFees[$tok] = (float) $row['total'];
        }

        // Query recent 25 fee transactions
        $history = $this->db->fetchAll(
            "SELECT id, transfer_id, token, amount, type, destination, tx_signature, notes, created_at
             FROM fee_collections 
             ORDER BY created_at DESC 
             LIMIT 25"
        );

        return Response::success([
            'wallet' => [
                'user_id' => $devUserId,
                'address' => $devAddress,
                'explorer_url' => "https://solscan.io/account/{$devAddress}",
                'status' => 'active'
            ],
            'balances' => $balances,
            'total_fees_collected' => $totalFees,
            'history' => $history
        ]);
    }

    /**
     * POST /api/admin/dev-wallet/export-key
     * Export Dev Wallet private keys (Base58 & JSON Array) for Phantom, Solflare, or Solana CLI.
     * Enforces admin authorization via 2FA TOTP or password.
     */
    public function exportDevWalletKey(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        // Validate security verification: require 2FA TOTP if admin has 2FA, or password
        $totpCode = trim((string) $request->input('totp_code', ''));
        $password = (string) $request->input('password', '');

        $has2FA = !empty($admin['two_factor_enabled']) && !empty($admin['two_factor_secret']);
        $authenticated = false;

        if ($has2FA && $totpCode !== '') {
            $twoFactorService = new \App\Services\TwoFactorService();
            if ($twoFactorService->verifyCode((string) $admin['two_factor_secret'], $totpCode)) {
                $authenticated = true;
            }
        }

        if (!$authenticated && $password !== '') {
            $adminFull = $this->db->fetch('SELECT password_hash FROM users WHERE id = ?', [(int) $admin['id']]);
            if ($adminFull && password_verify($password, (string) $adminFull['password_hash'])) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            return Response::error('Security verification failed. Please provide a valid 2FA TOTP code or admin password.', 401);
        }

        $devUser = TransferService::getOrCreateDevWallet($this->db, $this->keyService);
        if (empty($devUser['encrypted_private_key'])) {
            return Response::error('No private key found for Dev Wallet.', 404);
        }

        $decrypted = $this->keyService->decrypt((string) $devUser['encrypted_private_key']);
        $address = (string) $devUser['solana_deposit_address'];

        if (strlen($decrypted) === 64) {
            $secretKeyBase58 = \App\Utils\Base58::encode($decrypted);
            $secretKeyArray = array_values(unpack('C*', $decrypted));
        } else {
            $secretKeyBase58 = $decrypted;
            $decoded = \App\Utils\Base58::decode($decrypted);
            $secretKeyArray = array_values(unpack('C*', $decoded));
        }

        // Audit log
        $this->keyService->logSecurityAudit((int) $admin['id'], 'DEV_WALLET_KEY_EXPORTED', [
            'dev_wallet_address' => $address,
            'ip' => $request->ip(),
            'user_agent' => $request->header('user-agent'),
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ]);

        return Response::success([
            'address' => $address,
            'private_key_base58' => $secretKeyBase58,
            'private_key_json' => $secretKeyArray,
            'exported_at' => gmdate('c'),
            'warning' => 'CRITICAL SECURITY NOTICE: Anyone possessing this private key has full irrevocable control over the SolRecall Dev Wallet and all accumulated platform fees. Never share this key.'
        ]);
    }

    /**
     * POST /api/admin/dev-wallet/transfer
     * Transfer/sweep accumulated fees from the Dev Wallet out to an external Solana address.
     */
    public function transferDevWallet(Request $request): Response
    {
        $userId = $this->getAuthenticatedUserId($request);
        if ($userId === null) {
            return Response::error('Authentication required. Please log in.', 401);
        }
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return Response::error('Administrator privileges required.', 403);
        }

        // Security check
        $totpCode = trim((string) $request->input('totp_code', ''));
        $password = (string) $request->input('password', '');
        $has2FA = !empty($admin['two_factor_enabled']) && !empty($admin['two_factor_secret']);
        $authenticated = false;

        if ($has2FA && $totpCode !== '') {
            $twoFactorService = new \App\Services\TwoFactorService();
            if ($twoFactorService->verifyCode((string) $admin['two_factor_secret'], $totpCode)) {
                $authenticated = true;
            }
        }

        if (!$authenticated && $password !== '') {
            $adminFull = $this->db->fetch('SELECT password_hash FROM users WHERE id = ?', [(int) $admin['id']]);
            if ($adminFull && password_verify($password, (string) $adminFull['password_hash'])) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            return Response::error('Security verification failed. Please provide a valid 2FA TOTP code or admin password.', 401);
        }

        $destination = trim((string) $request->input('destination', ''));
        $token = strtoupper(trim((string) $request->input('token', 'SOL')));
        $amount = (float) $request->input('amount', 0);

        if (!$this->keyService->isValidSolanaAddress($destination)) {
            return Response::error('Invalid destination Solana address.', 422);
        }

        if (!in_array($token, ['SOL', 'USDC', 'USDT'], true)) {
            return Response::error("Unsupported token '{$token}'.", 422);
        }

        if ($amount <= 0) {
            return Response::error('Transfer amount must be greater than zero.', 422);
        }

        $devUser = TransferService::getOrCreateDevWallet($this->db, $this->keyService);
        $devUserId = (int) $devUser['id'];

        // Lock Dev Wallet balance row
        $balance = $this->db->selectForUpdate(
            "SELECT available FROM user_balances WHERE user_id = ? AND token = ?",
            [$devUserId, $token]
        );
        $available = (float) ($balance['available'] ?? 0);

        if ($available < $amount) {
            return Response::error("Insufficient Dev Wallet balance. Available: {$available} {$token}, Requested: {$amount} {$token}", 400);
        }

        // Resolve Dev Wallet signing key & Hot Wallet fee sponsor
        $devSigningKey = $this->keyService->resolveSigningKey($devUser['encrypted_private_key']);
        $hotWalletKey = $this->keyService->resolveSigningKey(null);

        try {
            // Broadcast on-chain transfer directly from Dev Wallet to destination
            $txSignature = $this->rpcService->broadcastTransfer(
                $devSigningKey,
                $destination,
                $amount,
                $token,
                $hotWalletKey
            );

            // Deduct available balance
            $this->db->execute(
                "UPDATE user_balances SET available = GREATEST(0, available - ?), updated_at = NOW() WHERE user_id = ? AND token = ?",
                [$amount, $devUserId, $token]
            );

            // Log into fee_collections as withdrawal
            $this->db->execute(
                "INSERT INTO fee_collections (transfer_id, token, amount, type, destination, tx_signature, notes, created_at)
                 VALUES (NULL, ?, ?, 'fee_withdrawn', ?, ?, 'Admin withdrawal to external wallet', NOW())",
                [$token, $amount, $destination, $txSignature]
            );

            TransferService::invalidateBalanceCache($devUserId);

            return Response::success([
                'message' => "Successfully transferred {$amount} {$token} from Dev Wallet to {$destination}.",
                'tx_signature' => $txSignature,
                'explorer_url' => "https://solscan.io/tx/{$txSignature}",
                'amount' => $amount,
                'token' => $token,
                'destination' => $destination
            ]);
        } catch (\Throwable $e) {
            return Response::error('On-chain transfer failed: ' . $e->getMessage(), 500);
        }
    }
}
