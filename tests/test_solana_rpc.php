<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SolanaRpcService;
use App\Services\KeyManagementService;

$km = new KeyManagementService();
$sender = $km->generateSolanaKeypair();
$receiver = $km->generateSolanaKeypair();

$rpc = new SolanaRpcService(null, null, 5, true); // force simulation mode for instant test
echo "Is Simulation Mode: " . ($rpc->isSimulationMode() ? 'YES' : 'NO') . "\n";

// 1. Test getBalance
$bal = $rpc->getBalance($sender['publicKey']);
echo "Simulated Balance: {$bal} SOL\n";
assert($bal > 0);

// 2. Test getRecentBlockhash
$bh = $rpc->getRecentBlockhash();
echo "Recent Blockhash: {$bh['blockhash']}\n";
assert(!empty($bh['blockhash']));

// 3. Test ATA Derivation
$ata = $rpc->findAssociatedTokenAddress($receiver['publicKey'], 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v');
echo "Derived USDC ATA for Receiver: {$ata}\n";
assert(strlen($ata) >= 32);

// 4. Test broadcastTransfer SOL
$txSig = $rpc->broadcastTransfer($sender['secretKey'], $receiver['publicKey'], 0.5, 'SOL');
echo "Broadcast SOL TxSig: {$txSig} (Length: " . strlen($txSig) . ")\n";
assert(strlen($txSig) >= 80);

// 5. Test broadcastTransfer USDC
$txSigSpl = $rpc->broadcastTransfer($sender['secretKey'], $receiver['publicKey'], 25.0, 'USDC');
echo "Broadcast USDC TxSig: {$txSigSpl} (Length: " . strlen($txSigSpl) . ")\n";
assert(strlen($txSigSpl) >= 80);

echo "All SolanaRpcService tests passed!\n";
