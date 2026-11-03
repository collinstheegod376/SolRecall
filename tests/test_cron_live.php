<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\TransferService;

$db = Database::getInstance();
$service = new TransferService();

// Initiate a small transfer for user 1 (0.5 SOL to a test address)
$dest = '83astBRguLMdt2h5U1Tpdq5LM9KuKHLuGTu2uCpWbhQA';
$res = $service->initiateTransfer(1, 'SOL', 0.5, $dest, 'Cron processing test');
$transferId = $res['transfer_id'];
echo "Initiated transfer #{$transferId} for 0.5 SOL in escrow.\n";

// Force expires_at to 10 seconds ago
$db->execute("UPDATE pending_transfers SET expires_at = datetime('now', '-10 seconds') WHERE id = ?", [$transferId]);
echo "Backdated transfer #{$transferId} to expired.\n";

// Now run process_pending.php
echo "Invoking cron/process_pending.php...\n";
passthru('"' . PHP_BINARY . '" cron/process_pending.php');

// Check status in DB
$item = $db->fetch("SELECT * FROM pending_transfers WHERE id = ?", [$transferId]);
echo "\nTransfer #{$transferId} final status: {$item['status']}, tx_signature: {$item['tx_signature']}\n";
assert($item['status'] === 'completed');
assert(!empty($item['tx_signature']));
echo "[SUCCESS] Verified cron/process_pending.php successfully processed and completed the expired transfer!\n";
