<?php

declare(strict_types=1);

/**
 * CLI Cron Broadcaster: Expired Escrow Processing
 *
 * Scans pending_transfers for records where status = 'pending' AND expires_at <= NOW().
 * Atomically marks them 'processing', broadcasts real/simulated transactions to Solana,
 * and updates user balances and transfer statuses.
 *
 * Usage:
 *   php cron/process_pending.php
 *   php cron/process_pending.php --dry-run
 *   php cron/process_pending.php --limit=50
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\KeyManagementService;
use App\Services\SolanaRpcService;

$startTime = microtime(true);
$options = getopt('', ['dry-run', 'limit::', 'verbose']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;
$verbose = isset($options['verbose']);

echo "[" . date('Y-m-d H:i:s') . "] === Starting Solana Undo Escrow Broadcaster ===\n";
if ($dryRun) {
    echo "[DRY-RUN MODE] No balances will be altered and no on-chain transactions will be broadcast.\n";
}

try {
    $db = Database::getInstance();
    $rpcService = new SolanaRpcService();
    $keyService = new KeyManagementService();

    $now = date('Y-m-d H:i:s');

    // 1. Fetch expired transfers that are still in 'pending' status
    $sql = "SELECT pt.*, u.encrypted_private_key, u.solana_deposit_address, u.username 
            FROM pending_transfers pt 
            JOIN users u ON pt.user_id = u.id 
            WHERE pt.status = 'pending' 
              AND pt.expires_at <= ? 
            ORDER BY pt.expires_at ASC 
            LIMIT ?";

    $pendingTransfers = $db->fetchAll($sql, [$now, $limit]);
    $count = count($pendingTransfers);

    echo "Found {$count} expired pending transfer(s) ready to broadcast.\n";

    if ($count === 0) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "[OK] No expired transfers to process. Execution time: {$elapsed}ms.\n";
        exit(0);
    }

    $processed = 0;
    $succeeded = 0;
    $failed = 0;

    foreach ($pendingTransfers as $transfer) {
        $transferId = (int) $transfer['id'];
        $userId = (int) $transfer['user_id'];
        $token = strtoupper(trim((string) $transfer['token']));
        $amount = (float) $transfer['amount'];
        $destination = trim((string) $transfer['destination']);
        $username = $transfer['username'] ?? "user#{$userId}";

        echo "\n--> Processing Transfer #{$transferId}: {$amount} {$token} -> {$destination} ({$username})\n";

        if ($dryRun) {
            echo "    [DRY-RUN] Would broadcast and complete transfer #{$transferId}.\n";
            continue;
        }

        // 2. Concurrency-safe lock: Atomically change status from 'pending' to 'processing'
        // Strictly only claims the row if it is STILL 'pending' and expires_at has passed.
        $now = date('Y-m-d H:i:s');
        $locked = $db->execute(
            "UPDATE pending_transfers SET status = 'processing' WHERE id = ? AND status = 'pending' AND expires_at <= ?",
            [$transferId, $now]
        );

        if ($locked === 0) {
            echo "    [SKIP] Transfer #{$transferId} was already cancelled by user or claimed by another worker.\n";
            continue;
        }

        $processed++;

        // 3. Resolve user signing key (user pays own network fee, no hot wallet sponsorship)
        $userSigningKey = $keyService->resolveSigningKey($transfer['encrypted_private_key'] ?? null);

        try {
            // 4. Broadcast on-chain transaction via SolanaRpcService (user pays network fee)
            echo "    Broadcasting transaction to Solana cluster (User custodial wallet pays network fee)...\n";
            $txSignature = $rpcService->broadcastTransfer($userSigningKey, $destination, $amount, $token, null);
            echo "    [ON-CHAIN SUCCESS] Tx Signature: {$txSignature}\n";

            // 5. On success: Atomically deduct escrow in user_balances & mark completed
            $db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $txSignature) {
                // Deduct from escrow
                $db->execute(
                    "UPDATE user_balances SET escrow = escrow - ? WHERE user_id = ? AND token = ?",
                    [$amount, $userId, $token]
                );

                // Update transfer record
                $processedAt = date('Y-m-d H:i:s');
                $db->execute(
                    "UPDATE pending_transfers 
                     SET status = 'completed', tx_signature = ?, processed_at = ? 
                     WHERE id = ?",
                    [$txSignature, $processedAt, $transferId]
                );
            });

            echo "    [COMPLETED] Transfer #{$transferId} marked completed. Escrow balance finalized.\n";
            $succeeded++;

        } catch (Throwable $broadcastError) {
            $errorMessage = $broadcastError->getMessage();
            echo "    [BROADCAST ERROR] " . $errorMessage . "\n";

            // 6. On failure: Atomically revert escrow and fees back to available balance & mark failed
            try {
                $db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $errorMessage) {
                    $failedAt = date('Y-m-d H:i:s');
                    $platformFee = round($amount * 0.12, 6);
                    $gasFee = 0.000005;

                    if ($token === 'SOL') {
                        $totalRefund = round($amount + $platformFee + $gasFee, 6);
                        $db->execute(
                            "UPDATE user_balances SET escrow = escrow - ?, available = available + ? WHERE user_id = ? AND token = ?",
                            [$amount, $totalRefund, $userId, 'SOL']
                        );
                    } else {
                        $totalRefundToken = round($amount + $platformFee, 6);
                        $db->execute(
                            "UPDATE user_balances SET escrow = escrow - ?, available = available + ? WHERE user_id = ? AND token = ?",
                            [$amount, $totalRefundToken, $userId, $token]
                        );
                        $db->execute(
                            "UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?",
                            [$gasFee, $userId, 'SOL']
                        );
                    }

                    // Mark transfer as failed
                    $db->execute(
                        "UPDATE pending_transfers 
                         SET status = 'failed', failure_reason = ?, processed_at = ? 
                         WHERE id = ?",
                        [$errorMessage, $failedAt, $transferId]
                    );
                });

                echo "    [REVERTED] Funds and fees refunded from escrow back to available balance.\n";
            } catch (Throwable $revertError) {
                echo "    [CRITICAL ERROR] Failed to revert escrow for transfer #{$transferId}: " . $revertError->getMessage() . "\n";
            }

            $failed++;
        }
    }

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    echo "\n=== Processing Summary ===\n";
    echo "Total Expired Found: {$count}\n";
    echo "Processed:           {$processed}\n";
    echo "Succeeded:           {$succeeded}\n";
    echo "Failed:              {$failed}\n";
    echo "Elapsed Time:        {$elapsed}ms\n";
    echo "==========================\n";

    exit($failed > 0 ? 1 : 0);

} catch (Throwable $e) {
    echo "\n[FATAL ERROR] " . $e->getMessage() . "\n";
    if ($verbose) {
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}
