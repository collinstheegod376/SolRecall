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

// 1. Optional security token verification (Vercel provides Authorization: Bearer <CRON_SECRET>)
$cronSecret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: null;
if ($cronSecret) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $querySecret = $_GET['secret'] ?? '';
    if ($authHeader !== 'Bearer ' . $cronSecret && $querySecret !== $cronSecret) {
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

        // Mark processing
        $db->execute("UPDATE pending_transfers SET status = 'processing' WHERE id = ?", [$transferId]);

        try {
            // Resolve signing key (decrypted custodial key or platform hot wallet)
            $signingPrivateKey = $keyService->resolveSigningKey($transfer['encrypted_private_key'] ?? null);

            // Broadcast on-chain
            $txSignature = $rpcService->broadcastTransfer(
                $signingPrivateKey,
                $destination,
                $amount,
                $token
            );

            // Deduct escrow balance
            $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), updated_at = NOW() WHERE user_id = ? AND token = ?", [
                $amount, $userId, $token
            ]);

            // Mark completed
            $db->execute("UPDATE pending_transfers SET status = 'completed', tx_signature = ?, processed_at = NOW() WHERE id = ?", [
                $txSignature, $transferId
            ]);

            $succeeded++;
        } catch (\Throwable $e) {
            // Revert funds back to available
            $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), available = available + ?, updated_at = NOW() WHERE user_id = ? AND token = ?", [
                $amount, $amount, $userId, $token
            ]);

            $db->execute("UPDATE pending_transfers SET status = 'failed', failure_reason = ? WHERE id = ?", [
                $e->getMessage(), $transferId
            ]);

            $failed++;
        }
        $processed++;
    }

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed,
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
