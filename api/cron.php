<?php

declare(strict_types=1);

/**
 * Vercel Cron Handler: Escrow Expiration Broadcaster
 *
 * Runs automatically every minute via Vercel Cron to process expired
 * pending transfers and broadcast transactions on-chain.
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// 1. Security token verification
// Vercel Cron sends Authorization: Bearer <CRON_SECRET>
// Local development (localhost / 127.0.0.1 / CLI) is automatically permitted
$cronSecret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: null;
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($clientIp, ['127.0.0.1', '::1', 'localhost'], true) || (php_sapi_name() === 'cli');

if ($cronSecret && !$isLocal) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    $querySecret = $_GET['secret'] ?? '';
    $bearerMatch = preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches) ? $matches[1] : '';
    $isAuthorized = ($bearerMatch !== '' && hash_equals($cronSecret, $bearerMatch))
        || ($querySecret !== '' && hash_equals($cronSecret, $querySecret));

    if (!$isAuthorized) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized cron request']);
        exit;
    }
}

// 2. Load framework bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\KeyManagementService;
use App\Services\SolanaRpcService;

$startTime = microtime(true);

try {
    $db = Database::getInstance();
    $rpcService = new SolanaRpcService();
    $keyService = new KeyManagementService();

    $now = date('Y-m-d H:i:s');

    // Fetch expired transfers that are still in 'pending' status
    $sql = "SELECT pt.*, u.encrypted_private_key, u.solana_deposit_address, u.username 
            FROM pending_transfers pt 
            JOIN users u ON pt.user_id = u.id 
            WHERE pt.status = 'pending' 
              AND pt.expires_at <= ? 
            ORDER BY pt.expires_at ASC 
            LIMIT 50";

    $pendingTransfers = $db->fetchAll($sql, [$now]);
    $count = count($pendingTransfers);

    if ($count === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'No expired transfers to process',
            'processed' => 0,
            'timestamp' => $now,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ]);
        exit;
    }

    $processed = 0;
    $succeeded = 0;
    $failed = 0;

    foreach ($pendingTransfers as $transfer) {
        $transferId = (int) $transfer['id'];
        $userId = (int) $transfer['user_id'];
        $token = strtoupper(trim((string) $transfer['token']));
        $amount = (float) $transfer['amount'];
        $destination = (string) $transfer['destination'];

        // Concurrency-safe atomic claim: Only succeeds if STILL 'pending' and expires_at has arrived
        $claimed = $db->execute(
            "UPDATE pending_transfers SET status = 'processing' WHERE id = ? AND status = 'pending' AND expires_at <= ?",
            [$transferId, $now]
        );

        if ($claimed === 0) {
            continue; // Skipped! User cancelled or another worker claimed it.
        }

        try {
            // Resolve user signing key and hot wallet fee sponsor key
            $userSigningKey = $keyService->resolveSigningKey($transfer['encrypted_private_key'] ?? null);
            $hotWalletKey = $keyService->resolveSigningKey(null);

            // Broadcast on-chain with fee sponsorship & ATA idempotent creation
            $txSignature = $rpcService->broadcastTransfer(
                $userSigningKey,
                $destination,
                $amount,
                $token,
                $hotWalletKey
            );

            // Deduct escrow balance & mark completed atomically
            $db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $txSignature) {
                $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), updated_at = NOW() WHERE user_id = ? AND token = ?", [
                    $amount, $userId, $token
                ]);

                $db->execute("UPDATE pending_transfers SET status = 'completed', tx_signature = ?, processed_at = NOW() WHERE id = ?", [
                    $txSignature, $transferId
                ]);

                // Credit 12% platform fee to Dev Wallet vault
                $platformFee = round($amount * 0.12, 6);
                \App\Services\TransferService::creditPlatformFee($db, $transferId, $token, $platformFee, "Cron expired transfer #{$transferId}");
            });

            $succeeded++;
        } catch (\Throwable $e) {
            // Revert funds AND platform/gas fees back to available balance (zero funds leaked)
            try {
                $db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $e) {
                    $platformFee = round($amount * 0.12, 6);
                    $gasFee = 0.000005;

                    if ($token === 'SOL') {
                        $totalRefund = round($amount + $platformFee + $gasFee, 6);
                        $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = ?", [
                            $amount, $totalRefund, $userId, 'SOL'
                        ]);
                    } else {
                        $totalRefundToken = round($amount + $platformFee, 6);
                        $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = ?", [
                            $amount, $totalRefundToken, $userId, $token
                        ]);
                        $db->execute("UPDATE user_balances SET available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = ?", [
                            $gasFee, $userId, 'SOL'
                        ]);
                    }

                    $db->execute("UPDATE pending_transfers SET status = 'failed', failure_reason = ?, processed_at = NOW() WHERE id = ?", [
                        $e->getMessage(), $transferId
                    ]);
                });
            } catch (\Throwable $revertErr) {
                error_log("Critical error reverting transfer #{$transferId}: " . $revertErr->getMessage());
            }

            $failed++;
        }
        $processed++;
    }

    // 4. Automatically sync incoming on-chain deposits for users
    $transferService = new \App\Services\TransferService($db, $rpcService, $keyService);
    $users = $db->fetchAll("SELECT id FROM users WHERE solana_deposit_address IS NOT NULL AND solana_deposit_address != ''");
    $totalDepositsCredited = 0;
    foreach ($users as $u) {
        try {
            $totalDepositsCredited += $transferService->syncUserDeposits((int) $u['id']);
        } catch (\Throwable) {
            // Continue scanning other users
        }
    }

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed,
        'deposits_credited' => $totalDepositsCredited,
        'timestamp' => $now,
        'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
    ]);
}
