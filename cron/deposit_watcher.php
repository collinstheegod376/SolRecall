<?php

declare(strict_types=1);

/**
 * CLI Deposit Watcher & Balance Creditor
 *
 * Scans users' custodial solana_deposit_address for incoming SOL, USDC, and USDT transfers.
 * Prevents double-crediting via the deposits table and credits user_balances.available atomically.
 *
 * Usage:
 *   php cron/deposit_watcher.php
 *   php cron/deposit_watcher.php --simulate
 *   php cron/deposit_watcher.php --poll --interval=10
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\KeyManagementService;
use App\Services\SolanaRpcService;

$startTime = microtime(true);
$options = getopt('', ['simulate', 'poll', 'interval::', 'user::', 'verbose']);
$simulate = isset($options['simulate']);
$poll = isset($options['poll']);
$interval = isset($options['interval']) ? max(1, (int) $options['interval']) : 10;
$filterUser = isset($options['user']) ? (int) $options['user'] : null;
$verbose = isset($options['verbose']);

echo "[" . date('Y-m-d H:i:s') . "] === Starting Solana Deposit Watcher & Escrow Broadcaster ===\n";

$db = Database::getInstance();
$rpcService = new SolanaRpcService();
$keyService = new KeyManagementService();

/**
 * Perform a single scanning iteration across deposit addresses.
 */
function scanDeposits(Database $db, SolanaRpcService $rpcService, ?int $filterUser, bool $simulate, bool $verbose): int
{
    $sql = "SELECT id, username, solana_deposit_address FROM users WHERE solana_deposit_address IS NOT NULL AND solana_deposit_address != ''";
    $params = [];
    if ($filterUser !== null) {
        $sql .= " AND id = ?";
        $params[] = $filterUser;
    }

    $users = $db->fetchAll($sql, $params);
    $totalCredited = 0;

    echo "Checking deposit addresses for " . count($users) . " user(s)...\n";

    foreach ($users as $user) {
        $userId = (int) $user['id'];
        $username = $user['username'] ?? "user#{$userId}";
        $depositAddress = trim((string) $user['solana_deposit_address']);

        if ($verbose) {
            echo "  --> Checking user {$username} ({$depositAddress})...\n";
        }

        if ($simulate) {
            // Simulated deposit test event
            $tokens = ['SOL', 'USDC', 'USDT'];
            $simToken = $tokens[array_rand($tokens)];
            $simAmount = $simToken === 'SOL' ? 1.25 : 50.0;
            $simTx = 'sim_dep_' . bin2hex(random_bytes(16)) . '_' . time();

            echo "  [SIMULATION] Detected incoming {$simAmount} {$simToken} to {$depositAddress}\n";
            $credited = creditDeposit($db, $userId, $simToken, $simAmount, $simTx);
            if ($credited) {
                $totalCredited++;
                echo "  [CREDITED] Successfully credited {$simAmount} {$simToken} to {$username}.\n";
            }
            continue;
        }

        // 1. Live RPC Signature Check
        try {
            $signatures = $rpcService->getSignaturesForAddress($depositAddress, 10);
            foreach ($signatures as $sigInfo) {
                $txSig = is_array($sigInfo) ? ($sigInfo['signature'] ?? '') : (string) $sigInfo;
                if ($txSig === '') {
                    continue;
                }

                // Check if already processed
                $existing = $db->fetch('SELECT id FROM deposits WHERE tx_signature = ?', [$txSig]);
                if ($existing) {
                    continue; // already recorded
                }

                // In live mode, query transaction details and parse exact balance deltas
                $confirmedSlot = is_array($sigInfo) ? ($sigInfo['slot'] ?? null) : null;
                $err = is_array($sigInfo) ? ($sigInfo['err'] ?? null) : null;
                if ($err !== null) {
                    continue; // Skip failed on-chain transactions
                }

                $txData = $rpcService->getTransaction($txSig);
                if (!$txData) {
                    continue;
                }

                $depositDelta = parseTransactionDepositDelta($txData, $depositAddress);
                if (!$depositDelta) {
                    // Transaction was an outgoing send, internal contract call, or non-balance delta
                    continue;
                }

                $token = $depositDelta['token'];
                $amount = $depositDelta['amount'];

                $credited = creditDeposit($db, $userId, $token, $amount, $txSig);
                if ($credited) {
                    $totalCredited++;
                    echo "  [DEPOSIT CONFIRMED] User: {$username}, Token: {$token}, Amount: {$amount}, Tx: {$txSig}\n";
                }
            }
        } catch (Throwable $e) {
            if ($verbose) {
                echo "  [WARN] Failed to query signatures for {$depositAddress}: " . $e->getMessage() . "\n";
            }
        }
    }

    return $totalCredited;
}

/**
 * Parse actual on-chain balance deltas for a specific deposit address.
 * Inspects postTokenBalances vs preTokenBalances (USDC/USDT) and
 * postBalances vs preBalances (native SOL).
 *
 * @param array $tx Parsed transaction payload from getTransaction
 * @param string $depositAddress Target deposit public key
 * @return array{token: string, amount: float}|null
 */
function parseTransactionDepositDelta(array $tx, string $depositAddress): ?array
{
    $meta = $tx['meta'] ?? null;
    if (!$meta || ($meta['err'] ?? null) !== null) {
        return null;
    }

    $usdcMint = (string) config('tokens.USDC.mint', '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU');
    $usdtMint = (string) config('tokens.USDT.mint', 'EJwZgeZrdCraqiSCdtXYWhWsvQTxZeQXP3ZvGWcvppgw');

    // 1. Check SPL Token Balances (postTokenBalances vs preTokenBalances)
    if (!empty($meta['postTokenBalances']) && is_array($meta['postTokenBalances'])) {
        $preMap = [];
        if (!empty($meta['preTokenBalances']) && is_array($meta['preTokenBalances'])) {
            foreach ($meta['preTokenBalances'] as $pre) {
                $idx = $pre['accountIndex'] ?? null;
                if ($idx !== null) {
                    $preMap[$idx] = (float) ($pre['uiTokenAmount']['uiAmount'] ?? 0.0);
                }
            }
        }

        foreach ($meta['postTokenBalances'] as $post) {
            $owner = $post['owner'] ?? '';
            if ($owner === $depositAddress) {
                $idx = $post['accountIndex'] ?? null;
                $preAmount = $preMap[$idx] ?? 0.0;
                $postAmount = (float) ($post['uiTokenAmount']['uiAmount'] ?? 0.0);
                $delta = $postAmount - $preAmount;

                if ($delta > 0.000001) {
                    $mint = $post['mint'] ?? '';
                    $token = match ($mint) {
                        $usdcMint => 'USDC',
                        $usdtMint => 'USDT',
                        default => null
                    };

                    if ($token !== null) {
                        return ['token' => $token, 'amount' => round($delta, 6)];
                    }
                }
            }
        }
    }

    // 2. Check Native SOL Balances (postBalances vs preBalances)
    $message = $tx['transaction']['message'] ?? [];
    $accountKeys = $message['accountKeys'] ?? [];
    $accountIndex = null;

    foreach ($accountKeys as $idx => $keyInfo) {
        $pubkey = is_array($keyInfo) ? ($keyInfo['pubkey'] ?? '') : (string) $keyInfo;
        if ($pubkey === $depositAddress) {
            $accountIndex = $idx;
            break;
        }
    }

    if ($accountIndex !== null && isset($meta['preBalances'][$accountIndex], $meta['postBalances'][$accountIndex])) {
        $preLamports = (int) $meta['preBalances'][$accountIndex];
        $postLamports = (int) $meta['postBalances'][$accountIndex];
        $deltaLamports = $postLamports - $preLamports;

        if ($deltaLamports > 0) {
            $deltaSol = round($deltaLamports / 1e9, 9);
            if ($deltaSol > 0.00001) {
                return ['token' => 'SOL', 'amount' => $deltaSol];
            }
        }
    }

    return null;
}

/**
 * Atomically records deposit and increments user available balance.
 */
function creditDeposit(Database $db, int $userId, string $token, float $amount, string $txSignature): bool
{
    return (bool) $db->transaction(function (Database $db) use ($userId, $token, $amount, $txSignature) {
        // Double-check signature uniqueness with lock
        $exists = $db->fetch('SELECT id FROM deposits WHERE tx_signature = ?', [$txSignature]);
        if ($exists) {
            return false;
        }

        // Insert into deposits table
        $now = date('Y-m-d H:i:s');
        $db->execute(
            "INSERT INTO deposits (user_id, token, amount, tx_signature, status, created_at, confirmed_at) 
             VALUES (?, ?, ?, ?, 'confirmed', ?, ?)",
            [$userId, $token, $amount, $txSignature, $now, $now]
        );

        // Update or insert into user_balances
        $balance = $db->selectForUpdate(
            'SELECT available FROM user_balances WHERE user_id = ? AND token = ?',
            [$userId, $token]
        );

        if ($balance) {
            $db->execute(
                'UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?',
                [$amount, $userId, $token]
            );
        } else {
            $db->execute(
                'INSERT INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, ?, 0)',
                [$userId, $token, $amount]
            );
        }

        return true;
    });
}

/**
 * Scan and process expired pending transfers (broadcast to Solana).
 */
function processExpiredTransfers(Database $db, SolanaRpcService $rpcService, KeyManagementService $keyService): int
{
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT pt.*, u.encrypted_private_key, u.solana_deposit_address, u.username 
            FROM pending_transfers pt 
            JOIN users u ON pt.user_id = u.id 
            WHERE pt.status = 'pending' 
              AND pt.expires_at <= ? 
            ORDER BY pt.expires_at ASC 
            LIMIT 50";

    $pendingTransfers = $db->fetchAll($sql, [$now]);
    if (empty($pendingTransfers)) {
        return 0;
    }

    $processed = 0;
    foreach ($pendingTransfers as $transfer) {
        $transferId = (int) $transfer['id'];
        $userId = (int) $transfer['user_id'];
        $token = strtoupper(trim((string) $transfer['token']));
        $amount = (float) $transfer['amount'];
        $destination = trim((string) $transfer['destination']);

        // Concurrency-safe atomic claim
        $claimed = $db->execute(
            "UPDATE pending_transfers SET status = 'processing' WHERE id = ? AND status = 'pending' AND expires_at <= ?",
            [$transferId, $now]
        );
        if ($claimed === 0) {
            continue;
        }

        try {
            $userSigningKey = $keyService->resolveSigningKey($transfer['encrypted_private_key'] ?? null);
            $txSignature = $rpcService->broadcastTransfer($userSigningKey, $destination, $amount, $token);

            $db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $txSignature) {
                $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?) WHERE user_id = ? AND token = ?", [
                    $amount, $userId, $token
                ]);
                $processedAt = date('Y-m-d H:i:s');
                $db->execute("UPDATE pending_transfers SET status = 'completed', tx_signature = ?, processed_at = ? WHERE id = ?", [
                    $txSignature, $processedAt, $transferId
                ]);
            });

            echo "  [BROADCAST SUCCESS] Transfer #{$transferId}: {$amount} {$token} -> {$destination} (Tx: {$txSignature})\n";
            $processed++;
        } catch (\Throwable $e) {
            echo "  [BROADCAST FAILED] Transfer #{$transferId}: " . $e->getMessage() . "\n";
            $db->transaction(function (Database $db) use ($transferId, $userId, $token, $amount, $e) {
                $db->execute("UPDATE user_balances SET escrow = GREATEST(0, escrow - ?), available = available + ? WHERE user_id = ? AND token = ?", [
                    $amount, $amount, $userId, $token
                ]);
                $db->execute("UPDATE pending_transfers SET status = 'failed', failure_reason = ? WHERE id = ?", [
                    $e->getMessage(), $transferId
                ]);
            });
        }
    }

    return $processed;
}

// Execution Loop
if ($poll) {
    echo "Running in continuous polling daemon mode (interval: {$interval}s). Press Ctrl+C to terminate.\n";
    while (true) {
        try {
            $credited = scanDeposits($db, $rpcService, $filterUser, $simulate, $verbose);
            if ($credited > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Processed {$credited} new deposit(s).\n";
            }

            $broadcasted = processExpiredTransfers($db, $rpcService, $keyService);
            if ($broadcasted > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Broadcasted {$broadcasted} expired transfer(s) on-chain.\n";
            }
        } catch (\Throwable $loopErr) {
            echo "[" . date('Y-m-d H:i:s') . "] [LOOP NOTICE] " . $loopErr->getMessage() . "\n";
            // Re-instantiate database connection in case of connection drop
            try {
                Database::resetInstance();
                $db = Database::getInstance();
            } catch (\Throwable) {
                // Will retry on next tick
            }
        }

        sleep($interval);
    }
} else {
    $credited = scanDeposits($db, $rpcService, $filterUser, $simulate, $verbose);
    $broadcasted = processExpiredTransfers($db, $rpcService, $keyService);
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    echo "\n=== Background Runner Summary ===\n";
    echo "New Deposits Credited:     {$credited}\n";
    echo "Expired Transfers Sent:    {$broadcasted}\n";
    echo "Execution Time:            {$elapsed}ms\n";
    echo "=================================\n";
    exit(0);
}
