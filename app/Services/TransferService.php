<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Utils\Base58;
use InvalidArgumentException;
use RuntimeException;

/**
 * Escrow Transfer Engine
 *
 * Implements atomic balance holds for transfers below configurable thresholds,
 * cancellation grace periods, instant large broadcast transfers, and 2FA payouts.
 */
class TransferService
{
    private Database $db;
    private SolanaRpcService $rpcService;
    private KeyManagementService $keyService;

    public function __construct(
        ?Database $db = null,
        ?SolanaRpcService $rpcService = null,
        ?KeyManagementService $keyService = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->rpcService = $rpcService ?? new SolanaRpcService();
        $this->keyService = $keyService ?? new KeyManagementService();
    }

    /**
     * Get per-token small transfer threshold configuration.
     *
     * @return array<string, array{symbol: string, threshold: float, decimals: int, is_native: bool}>
     */
    public function getThresholds(): array
    {
        $tokens = (array) config('tokens', []);
        $result = [];
        foreach ($tokens as $symbol => $meta) {
            $result[$symbol] = [
                'symbol' => $meta['symbol'] ?? $symbol,
                'name' => $meta['name'] ?? $symbol,
                'threshold' => (float) ($meta['threshold'] ?? 100.0),
                'decimals' => (int) ($meta['decimals'] ?? 6),
                'is_native' => (bool) ($meta['is_native'] ?? false),
            ];
        }
        return $result;
    }

    /**
     * Determine whether an outgoing amount qualifies as a small transfer held in escrow.
     */
    public function isSmallTransfer(string $token, float $amount): bool
    {
        $thresholds = $this->getThresholds();
        $symbol = strtoupper(trim($token));

        if (!isset($thresholds[$symbol])) {
            throw new InvalidArgumentException("Unsupported token: {$token}");
        }

        return $amount <= $thresholds[$symbol]['threshold'];
    }

    /**
     * Initiate an outgoing transfer.
     *
     * - If amount <= threshold: atomically reserves balance into escrow hold.
     * - If amount > threshold: bypasses hold, broadcasts immediately to Solana.
     *
     * @param int $userId
     * @param string $token Token symbol (SOL, USDC, USDT)
     * @param float $amount Amount to send
     * @param string $destination Valid Solana Base58 recipient address
     * @param string|null $note Optional transaction note
     * @param int|null $customWindowMinutes Custom hold duration (5-60 min)
     * @return array
     * @throws ValidationException
     */
    public function initiateTransfer(
        int $userId,
        string $token,
        float $amount,
        string $destination,
        ?string $note = null,
        ?int $customWindowMinutes = null
    ): array {
        $token = strtoupper(trim($token));
        $destination = trim($destination);
        $amount = (float) $amount;

        // 1. Input Validations
        if ($amount <= 0) {
            throw new ValidationException('Transfer amount must be strictly greater than zero.', ['amount' => 'Must be > 0']);
        }

        $thresholds = $this->getThresholds();
        if (!isset($thresholds[$token])) {
            throw new ValidationException("Unsupported token: {$token}. Supported: " . implode(', ', array_keys($thresholds)));
        }

        if (!Base58::isValid($destination) || strlen($destination) < 32 || strlen($destination) > 44) {
            throw new ValidationException('Invalid Solana destination address.', ['destination' => 'Must be valid Base58 pubkey (32-44 chars)']);
        }

        $isSmall = $this->isSmallTransfer($token, $amount);

        // Calculate undo window duration (supports minutes up to 100 years)
        $defaultWindow = (int) config('undo_window.default_minutes', 10);
        $minWindow = (int) config('undo_window.min_minutes', 1);
        $maxWindow = (int) config('undo_window.max_minutes', 52560000);

        $windowMinutes = $customWindowMinutes !== null ? $customWindowMinutes : $defaultWindow;
        $windowMinutes = max($minWindow, min($maxWindow, $windowMinutes));

        // 2. Small Transfer -> Escrow Hold
        $platformFee = round($amount * 0.12, 6);
        $gasFee = 0.000005; // standard Solana tx fee in SOL
        $gasFeeStr = '0.000005';

        if ($isSmall) {
            return $this->db->transaction(function (Database $db) use ($userId, $token, $amount, $destination, $note, $windowMinutes, $platformFee, $gasFee, $gasFeeStr) {
                if ($token === 'SOL') {
                    $totalRequired = round($amount + $platformFee + $gasFee, 6);

                    // Atomic balance row lock
                    $balanceRow = $db->selectForUpdate(
                        'SELECT available, escrow FROM user_balances WHERE user_id = ? AND token = ?',
                        [$userId, 'SOL']
                    );

                    if (!$balanceRow) {
                        // Initialize empty balance row if absent
                        $db->execute(
                            'INSERT INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, 0, 0)',
                            [$userId, 'SOL']
                        );
                        $balanceRow = ['available' => 0.0, 'escrow' => 0.0];
                    }

                    $available = (float) $balanceRow['available'];
                    if ($available < $totalRequired) {
                        throw new ValidationException(
                            "Requires {$amount} SOL + {$platformFee} SOL (12% platform fee) + {$gasFeeStr} SOL (network gas) = {$totalRequired} SOL. Available: {$available} SOL",
                            ['amount' => 'Insufficient funds']
                        );
                    }

                    // Calculate expiration timestamp
                    $expiresTimestamp = time() + ($windowMinutes * 60);
                    $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);

                    // Atomic balance update: decrement available by totalRequired, increment escrow by principal amount
                    $db->execute(
                        'UPDATE user_balances SET available = available - ?, escrow = escrow + ? WHERE user_id = ? AND token = ?',
                        [$totalRequired, $amount, $userId, 'SOL']
                    );
                } else {
                    $totalRequiredToken = round($amount + $platformFee, 6);

                    // Lock token balance row
                    $balanceRow = $db->selectForUpdate(
                        'SELECT available, escrow FROM user_balances WHERE user_id = ? AND token = ?',
                        [$userId, $token]
                    );

                    if (!$balanceRow) {
                        $db->execute(
                            'INSERT INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, 0, 0)',
                            [$userId, $token]
                        );
                        $balanceRow = ['available' => 0.0, 'escrow' => 0.0];
                    }

                    $available = (float) $balanceRow['available'];
                    if ($available < $totalRequiredToken) {
                        throw new ValidationException(
                            "Requires {$amount} {$token} + {$platformFee} {$token} (12% platform fee) = {$totalRequiredToken} {$token}. Available: {$available} {$token}",
                            ['amount' => 'Insufficient funds']
                        );
                    }

                    // Check user also has at least $gasFee (0.000005 SOL) in their SOL balance for Solana network gas!
                    $solBalanceRow = $db->selectForUpdate(
                        'SELECT available, escrow FROM user_balances WHERE user_id = ? AND token = ?',
                        [$userId, 'SOL']
                    );
                    if (!$solBalanceRow) {
                        $db->execute(
                            'INSERT INTO user_balances (user_id, token, available, escrow) VALUES (?, ?, 0, 0)',
                            [$userId, 'SOL']
                        );
                        $solBalanceRow = ['available' => 0.0, 'escrow' => 0.0];
                    }
                    $solAvailable = (float) $solBalanceRow['available'];
                    if ($solAvailable < $gasFee) {
                        throw new ValidationException(
                            "Insufficient SOL balance for network gas. Requires {$gasFeeStr} SOL for Solana transaction fee. Available SOL: {$solAvailable}",
                            ['gas' => 'Insufficient SOL for gas fee']
                        );
                    }

                    // Calculate expiration timestamp
                    $expiresTimestamp = time() + ($windowMinutes * 60);
                    $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);

                    // Decrement token available by $amount + $platformFee, increment token escrow by $amount
                    $db->execute(
                        'UPDATE user_balances SET available = available - ?, escrow = escrow + ? WHERE user_id = ? AND token = ?',
                        [$totalRequiredToken, $amount, $userId, $token]
                    );

                    // Decrement SOL available by $gasFee
                    $db->execute(
                        'UPDATE user_balances SET available = available - ? WHERE user_id = ? AND token = ?',
                        [$gasFee, $userId, 'SOL']
                    );
                }

                // Insert pending transfer record with RETURNING id
                $transferId = (int) $db->fetchColumn(
                    "INSERT INTO pending_transfers (user_id, token, amount, destination, note, expires_at, status) 
                     VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING id",
                    [$userId, $token, $amount, $destination, $note, $expiresAt]
                );
                $humanDuration = self::formatHumanDuration($windowMinutes);
                $totalDeducted = ($token === 'SOL') ? round($amount + $platformFee + $gasFee, 6) : round($amount + $platformFee, 6);

                return [
                    'success' => true,
                    'type' => 'escrow',
                    'status' => 'pending',
                    'transfer_id' => $transferId,
                    'token' => $token,
                    'amount' => $amount,
                    'platform_fee' => $platformFee,
                    'gas_fee' => $gasFee,
                    'total_deducted' => $totalDeducted,
                    'destination' => $destination,
                    'note' => $note,
                    'window_minutes' => $windowMinutes,
                    'window_human' => $humanDuration,
                    'expires_at' => $expiresAt,
                    'seconds_remaining' => $windowMinutes * 60,
                    'message' => "Transfer of {$amount} {$token} initiated with a {$humanDuration} escrow hold window.",
                ];
            });
        }

        // 3. Large Transfer -> Immediate Broadcast (Bypasses Hold)
        return $this->executeImmediateTransfer($userId, $token, $amount, $destination, $note);
    }

    /**
     * Cancel an active pending transfer before its undo window expires.
     * Reverses escrow balance back into user's available balance atomically.
     *
     * @param int $userId
     * @param int $transferId
     * @return array
     * @throws ValidationException
     */
    public function cancelTransfer(int $userId, int $transferId): array
    {
        return $this->db->transaction(function (Database $db) use ($userId, $transferId) {
            // Lock transfer record
            $transfer = $db->selectForUpdate(
                'SELECT * FROM pending_transfers WHERE id = ?',
                [$transferId]
            );

            if (!$transfer) {
                throw new ValidationException('Transfer not found.', ['transfer_id' => 'Not found']);
            }

            // Verify ownership (or admin privileges)
            if ((int) $transfer['user_id'] !== $userId) {
                $user = $db->fetch('SELECT is_admin FROM users WHERE id = ?', [$userId]);
                if (!$user || !(bool) $user['is_admin']) {
                    throw new ValidationException('You do not have permission to cancel this transfer.');
                }
            }

            if ($transfer['status'] !== 'pending') {
                throw new ValidationException("Cannot cancel transfer with status '{$transfer['status']}'.");
            }

            $now = time();
            $expiresAtTimestamp = strtotime((string) $transfer['expires_at']);
            if ($expiresAtTimestamp <= $now) {
                throw new ValidationException('Undo grace period has expired; transfer is already queued for broadcast.');
            }

            $token = (string) $transfer['token'];
            $amount = (float) $transfer['amount'];
            $platformFee = round($amount * 0.12, 6);
            $gasFee = 0.000005;

            // Lock balance row
            $balance = $db->selectForUpdate(
                'SELECT escrow, available FROM user_balances WHERE user_id = ? AND token = ?',
                [(int) $transfer['user_id'], $token]
            );

            if (!$balance) {
                throw new RuntimeException("User balance record missing for token {$token}");
            }

            $now = date('Y-m-d H:i:s');

            // ATOMIC CAS CLAIM: Atomically transition status from 'pending' to 'cancelled'
            // Only succeeds if row is STILL 'pending' and expires_at has NOT passed yet.
            // If the cron processor or another process already claimed/expired it, affected rows will be 0.
            $affected = $db->execute(
                "UPDATE pending_transfers 
                 SET status = 'cancelled', cancelled_at = ? 
                 WHERE id = ? AND status = 'pending' AND expires_at > ?",
                [$now, $transferId, $now]
            );

            if ($affected === 0) {
                throw new ValidationException(
                    'Transfer cannot be cancelled. The undo window may have expired or the transfer is already being processed.',
                    ['transfer_id' => 'Cannot cancel transfer in current state']
                );
            }

            // Atomic claim won! Safe to reverse escrow balance and platform fee back into available balance
            if ($token === 'SOL') {
                $totalRefund = round($amount + $platformFee + $gasFee, 6);
                $db->execute(
                    'UPDATE user_balances SET escrow = escrow - ?, available = available + ? WHERE user_id = ? AND token = ?',
                    [$amount, $totalRefund, (int) $transfer['user_id'], 'SOL']
                );
            } else {
                $totalRefundToken = round($amount + $platformFee, 6);
                $db->execute(
                    'UPDATE user_balances SET escrow = escrow - ?, available = available + ? WHERE user_id = ? AND token = ?',
                    [$amount, $totalRefundToken, (int) $transfer['user_id'], $token]
                );
                $db->execute(
                    'UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?',
                    [$gasFee, (int) $transfer['user_id'], 'SOL']
                );
            }

            return [
                'success' => true,
                'transfer_id' => $transferId,
                'status' => 'cancelled',
                'token' => $token,
                'refunded_amount' => $amount,
                'platform_fee_refunded' => $platformFee,
                'gas_fee_refunded' => $gasFee,
                'cancelled_at' => $now,
                'message' => "Transfer #{$transferId} successfully cancelled. {$amount} {$token} restored to available balance.",
            ];
        });
    }

    /**
     * Process immediate on-chain transfer for amounts exceeding the small hold threshold.
     */
    private function executeImmediateTransfer(
        int $userId,
        string $token,
        float $amount,
        string $destination,
        ?string $note
    ): array {
        $platformFee = round($amount * 0.12, 6);
        $gasFee = 0.000005;
        $gasFeeStr = '0.000005';

        // Step 1: Atomically lock and deduct available balance
        $transferId = $this->db->transaction(function (Database $db) use ($userId, $token, $amount, $destination, $note, $platformFee, $gasFee, $gasFeeStr) {
            if ($token === 'SOL') {
                $totalRequired = round($amount + $platformFee + $gasFee, 6);
                $balanceRow = $db->selectForUpdate(
                    'SELECT available FROM user_balances WHERE user_id = ? AND token = ?',
                    [$userId, 'SOL']
                );

                $available = (float) ($balanceRow['available'] ?? 0);
                if ($available < $totalRequired) {
                    throw new ValidationException(
                        "Requires {$amount} SOL + {$platformFee} SOL (12% platform fee) + {$gasFeeStr} SOL (network gas) = {$totalRequired} SOL. Available: {$available} SOL",
                        ['amount' => 'Insufficient funds']
                    );
                }

                // Deduct available
                $db->execute(
                    'UPDATE user_balances SET available = available - ? WHERE user_id = ? AND token = ?',
                    [$totalRequired, $userId, 'SOL']
                );
            } else {
                $totalRequiredToken = round($amount + $platformFee, 6);
                $balanceRow = $db->selectForUpdate(
                    'SELECT available FROM user_balances WHERE user_id = ? AND token = ?',
                    [$userId, $token]
                );

                $available = (float) ($balanceRow['available'] ?? 0);
                if ($available < $totalRequiredToken) {
                    throw new ValidationException(
                        "Requires {$amount} {$token} + {$platformFee} {$token} (12% platform fee) = {$totalRequiredToken} {$token}. Available: {$available} {$token}",
                        ['amount' => 'Insufficient funds']
                    );
                }

                $solBalanceRow = $db->selectForUpdate(
                    'SELECT available FROM user_balances WHERE user_id = ? AND token = ?',
                    [$userId, 'SOL']
                );
                $solAvailable = (float) ($solBalanceRow['available'] ?? 0);
                if ($solAvailable < $gasFee) {
                    throw new ValidationException(
                        "Insufficient SOL balance for network gas. Requires {$gasFeeStr} SOL for Solana transaction fee. Available SOL: {$solAvailable}",
                        ['gas' => 'Insufficient SOL for gas fee']
                    );
                }

                $db->execute(
                    'UPDATE user_balances SET available = available - ? WHERE user_id = ? AND token = ?',
                    [$totalRequiredToken, $userId, $token]
                );
                $db->execute(
                    'UPDATE user_balances SET available = available - ? WHERE user_id = ? AND token = ?',
                    [$gasFee, $userId, 'SOL']
                );
            }

            $now = date('Y-m-d H:i:s');

            // Insert as processing
            return (int) $db->fetchColumn(
                "INSERT INTO pending_transfers (user_id, token, amount, destination, note, expires_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'processing') RETURNING id",
                [$userId, $token, $amount, $destination, $note, $now]
            );
        });

        // Step 2: Resolve signing key and broadcast on-chain
        try {
            $user = $this->db->fetch('SELECT encrypted_private_key FROM users WHERE id = ?', [$userId]);
            $signingKey = $this->keyService->resolveSigningKey($user['encrypted_private_key'] ?? null);

            $txSig = $this->rpcService->broadcastTransfer($signingKey, $destination, $amount, $token);
            $processedAt = date('Y-m-d H:i:s');

            $this->db->execute(
                "UPDATE pending_transfers SET status = 'completed', tx_signature = ?, processed_at = ? WHERE id = ?",
                [$txSig, $processedAt, $transferId]
            );

            return [
                'success' => true,
                'type' => 'immediate',
                'status' => 'completed',
                'transfer_id' => $transferId,
                'token' => $token,
                'amount' => $amount,
                'platform_fee' => $platformFee,
                'gas_fee' => $gasFee,
                'destination' => $destination,
                'tx_signature' => $txSig,
                'message' => "Transfer of {$amount} {$token} broadcast directly on-chain.",
            ];
        } catch (\Throwable $e) {
            // Revert balance on failure
            $this->db->transaction(function (Database $db) use ($userId, $token, $amount, $transferId, $e, $platformFee, $gasFee) {
                $failedAt = date('Y-m-d H:i:s');
                if ($token === 'SOL') {
                    $totalRevert = round($amount + $platformFee + $gasFee, 6);
                    $db->execute(
                        'UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?',
                        [$totalRevert, $userId, 'SOL']
                    );
                } else {
                    $totalRevertToken = round($amount + $platformFee, 6);
                    $db->execute(
                        'UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?',
                        [$totalRevertToken, $userId, $token]
                    );
                    $db->execute(
                        'UPDATE user_balances SET available = available + ? WHERE user_id = ? AND token = ?',
                        [$gasFee, $userId, 'SOL']
                    );
                }
                $db->execute(
                    "UPDATE pending_transfers SET status = 'failed', failure_reason = ?, processed_at = ? WHERE id = ?",
                    [$e->getMessage(), $failedAt, $transferId]
                );
            });

            throw new RuntimeException("Broadcast failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Withdraw funds directly to an external Solana address (requires 2FA if enabled).
     *
     * @param int $userId
     * @param string $token
     * @param float $amount
     * @param string $destination
     * @param string|null $totpCode
     * @return array
     * @throws ValidationException
     */
    public function withdraw(
        int $userId,
        string $token,
        float $amount,
        string $destination,
        ?string $totpCode = null
    ): array {
        $token = strtoupper(trim($token));
        $destination = trim($destination);
        $amount = (float) $amount;

        if ($amount <= 0) {
            throw new ValidationException('Withdrawal amount must be greater than zero.');
        }

        if (!Base58::isValid($destination) || strlen($destination) < 32 || strlen($destination) > 44) {
            throw new ValidationException('Invalid destination Solana address.');
        }

        // Verify 2FA
        $user = $this->db->fetch('SELECT two_factor_enabled, two_factor_secret, encrypted_private_key FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new ValidationException('User account not found.');
        }

        if ((bool) $user['two_factor_enabled']) {
            if ($totpCode === null || trim($totpCode) === '') {
                throw new ValidationException('2FA TOTP code is required for withdrawals.', ['totp_code' => 'Code required']);
            }

            if (!$this->verifyTotp((string) $user['two_factor_secret'], trim($totpCode))) {
                throw new ValidationException('Invalid 2FA authentication code.', ['totp_code' => 'Invalid code']);
            }
        }

        return $this->executeImmediateTransfer($userId, $token, $amount, $destination, 'Withdrawal to external address');
    }

    /**
     * Verify a 6-digit RFC 6238 TOTP code against a Base32 secret.
     */
    public function verifyTotp(string $secret, string $code): bool
    {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $base32Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $binarySecret = '';

        for ($i = 0; $i < strlen($secret); $i++) {
            $char = $secret[$i];
            if ($char === '=') {
                break;
            }
            $val = strpos($base32Alphabet, $char);
            if ($val === false) {
                continue;
            }
            $binarySecret .= sprintf('%05b', $val);
        }

        $decodedSecret = '';
        for ($i = 0; $i + 8 <= strlen($binarySecret); $i += 8) {
            $decodedSecret .= chr((int) bindec(substr($binarySecret, $i, 8)));
        }

        $currentTimeSlice = (int) floor(time() / 30);

        // Check window of -1, 0, +1 time slice for slight clock drift tolerance
        for ($offset = -1; $offset <= 1; $offset++) {
            $slice = $currentTimeSlice + $offset;
            $packedSlice = pack('N*', 0) . pack('N*', $slice);
            $hash = hash_hmac('sha1', $packedSlice, $decodedSecret, true);
            $offsetIndex = ord($hash[19]) & 0x0f;

            $truncated = (
                ((ord($hash[$offsetIndex]) & 0x7f) << 24) |
                ((ord($hash[$offsetIndex + 1]) & 0xff) << 16) |
                ((ord($hash[$offsetIndex + 2]) & 0xff) << 8) |
                (ord($hash[$offsetIndex + 3]) & 0xff)
            ) % 1000000;

            if (hash_equals(sprintf('%06d', $truncated), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch user balances across all supported tokens.
     */
    public function getUserBalances(int $userId): array
    {
        $tokens = $this->getThresholds();
        $rows = $this->db->fetchAll('SELECT token, available, escrow FROM user_balances WHERE user_id = ?', [$userId]);

        $balanceMap = [];
        foreach ($rows as $row) {
            $balanceMap[$row['token']] = [
                'available' => (float) $row['available'],
                'escrow' => (float) $row['escrow'],
                'total' => (float) $row['available'] + (float) $row['escrow'],
            ];
        }

        $result = [];
        foreach ($tokens as $sym => $meta) {
            $avail = $balanceMap[$sym]['available'] ?? 0.0;
            $escrow = $balanceMap[$sym]['escrow'] ?? 0.0;
            $result[$sym] = [
                'token' => $sym,
                'name' => $meta['name'],
                'available' => $avail,
                'escrow' => $escrow,
                'total' => $avail + $escrow,
                'threshold' => $meta['threshold'],
                'decimals' => $meta['decimals'],
            ];
        }

        return $result;
    }

    /**
     * Inspect on-chain transaction history for a user's deposit address
     * and credit any new confirmed deposits to their available balance.
     */
    public function syncUserDeposits(int $userId): int
    {
        $user = $this->db->fetch('SELECT solana_deposit_address, username FROM users WHERE id = ?', [$userId]);
        if (!$user || empty($user['solana_deposit_address'])) {
            return 0;
        }

        $depositAddress = trim((string) $user['solana_deposit_address']);
        $totalCredited = 0;

        try {
            $signatures = $this->rpcService->getSignaturesForAddress($depositAddress, 10);
            foreach ($signatures as $sigInfo) {
                $txSig = is_array($sigInfo) ? ($sigInfo['signature'] ?? '') : (string) $sigInfo;
                if ($txSig === '') {
                    continue;
                }

                // Check if already processed
                $existing = $this->db->fetch('SELECT id FROM deposits WHERE tx_signature = ?', [$txSig]);
                if ($existing) {
                    continue;
                }

                $err = is_array($sigInfo) ? ($sigInfo['err'] ?? null) : null;
                if ($err !== null) {
                    continue; // Skip failed on-chain transactions
                }

                $txData = $this->rpcService->getTransaction($txSig);
                if (!$txData) {
                    continue;
                }

                $depositDelta = $this->parseDepositDelta($txData, $depositAddress);
                if (!$depositDelta) {
                    continue;
                }

                $token = $depositDelta['token'];
                $amount = $depositDelta['amount'];

                $credited = (bool) $this->db->transaction(function (Database $db) use ($userId, $token, $amount, $txSig) {
                    $exists = $db->fetch('SELECT id FROM deposits WHERE tx_signature = ?', [$txSig]);
                    if ($exists) {
                        return false;
                    }

                    $now = date('Y-m-d H:i:s');
                    $db->execute(
                        "INSERT INTO deposits (user_id, token, amount, tx_signature, status, created_at, confirmed_at) 
                         VALUES (?, ?, ?, ?, 'confirmed', ?, ?)",
                        [$userId, $token, $amount, $txSig, $now, $now]
                    );

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

                if ($credited) {
                    $totalCredited++;
                }
            }
        } catch (\Throwable) {
            // Non-fatal if Solana RPC is unreachable or throttling
        }

        return $totalCredited;
    }

    /**
     * Parse on-chain balance deltas for a specific deposit address.
     */
    protected function parseDepositDelta(array $tx, string $depositAddress): ?array
    {
        $meta = $tx['meta'] ?? null;
        if (!$meta || ($meta['err'] ?? null) !== null) {
            return null;
        }

        $usdcMint = (string) config('tokens.USDC.mint', '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU');
        $usdtMint = (string) config('tokens.USDT.mint', 'EJwZgeZrdCraqiSCdtXYWhWsvQTxZeQXP3ZvGWcvppgw');

        // 1. Check SPL Token Balances
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

        // 2. Check Native SOL Balances
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
     * Retrieve active pending transfers currently held in escrow.
     */
    public function getPendingTransfers(int $userId, bool $isAdmin = false): array
    {
        if ($isAdmin) {
            $sql = "SELECT pt.*, u.username, u.email 
                    FROM pending_transfers pt 
                    JOIN users u ON pt.user_id = u.id 
                    WHERE pt.status = 'pending' 
                    ORDER BY pt.expires_at ASC";
            $params = [];
        } else {
            $sql = "SELECT * FROM pending_transfers 
                    WHERE user_id = ? AND status = 'pending' 
                    ORDER BY expires_at ASC";
            $params = [$userId];
        }

        $transfers = $this->db->fetchAll($sql, $params);
        $now = time();

        return array_map(function ($row) use ($now) {
            $expiresTimestamp = strtotime((string) $row['expires_at']);
            $remaining = max(0, $expiresTimestamp - $now);
            $totalWindow = max(1, $expiresTimestamp - strtotime((string) $row['created_at']));
            $progressPercent = min(100, max(0, (int) round((($totalWindow - $remaining) / $totalWindow) * 100)));

            $row['amount'] = (float) $row['amount'];
            $row['seconds_remaining'] = $remaining;
            $row['total_seconds'] = $totalWindow;
            $row['window_human'] = self::formatHumanDuration((int) max(1, round($totalWindow / 60)));
            $row['is_cancellable'] = ($remaining > 0);
            $row['progress_percent'] = $progressPercent;
            return $row;
        }, $transfers);
    }

    /**
     * Retrieve transfer history (pending escrow, completed, cancelled, failed, processing).
     */
    public function getTransferHistory(int $userId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM pending_transfers 
                WHERE user_id = ? AND status IN ('pending', 'processing', 'completed', 'cancelled', 'failed') 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";

        $rows = $this->db->fetchAll($sql, [$userId, $limit, $offset]);

        return array_map(function ($row) {
            $row['amount'] = (float) $row['amount'];
            $row['platform_fee'] = round($row['amount'] * 0.12, 6);
            $row['gas_fee'] = 0.000005;
            return $row;
        }, $rows);
    }

    /**
     * Format minutes into a clean human readable duration (e.g. "2 years", "3 months", "7 days", "10 minutes").
     */
    public static function formatHumanDuration(int $minutes): string
    {
        if ($minutes >= 525600) {
            $years = round($minutes / 525600, 1);
            $clean = ($years == (int) $years) ? (int) $years : $years;
            return "{$clean} year" . ($clean !== 1 ? 's' : '');
        }
        if ($minutes >= 43200) {
            $months = round($minutes / 43200, 1);
            $clean = ($months == (int) $months) ? (int) $months : $months;
            return "{$clean} month" . ($clean !== 1 ? 's' : '');
        }
        if ($minutes >= 10080) {
            $weeks = round($minutes / 10080, 1);
            $clean = ($weeks == (int) $weeks) ? (int) $weeks : $weeks;
            return "{$clean} week" . ($clean !== 1 ? 's' : '');
        }
        if ($minutes >= 1440) {
            $days = round($minutes / 1440, 1);
            $clean = ($days == (int) $days) ? (int) $days : $days;
            return "{$clean} day" . ($clean !== 1 ? 's' : '');
        }
        if ($minutes >= 60) {
            $hours = round($minutes / 60, 1);
            $clean = ($hours == (int) $hours) ? (int) $hours : $hours;
            return "{$clean} hour" . ($clean !== 1 ? 's' : '');
        }
        return "{$minutes} minute" . ($minutes !== 1 ? 's' : '');
    }

    /**
     * Retrieve a specific transfer by ID with user ownership check.
     */
    public function getTransferById(int $transferId, int $userId): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM pending_transfers WHERE id = ? AND user_id = ?',
            [$transferId, $userId]
        );

        if (!$row) {
            return null;
        }

        $row['amount'] = (float) $row['amount'];
        $expires = strtotime((string) $row['expires_at']);
        $row['seconds_remaining'] = max(0, $expires - time());
        $row['is_cancellable'] = ($row['status'] === 'pending' && $row['seconds_remaining'] > 0);

        return $row;
    }
}
