<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SolanaRpcService;
use App\Services\KeyManagementService;

echo "=== Live Solana Devnet Connectivity Check ===\n\n";

$rpc = new SolanaRpcService('https://api.devnet.solana.com', 'confirmed', 10, false);
echo "RPC Endpoint: https://api.devnet.solana.com\n";
echo "Commitment: confirmed\n";
echo "Simulation Mode: " . ($rpc->isSimulationMode() ? 'ENABLED' : 'DISABLED (Live Cluster Mode)') . "\n\n";

try {
    echo "1. Fetching live recent blockhash from Solana Devnet...\n";
    $blockhashInfo = $rpc->getRecentBlockhash();
    echo "   [SUCCESS] Live Devnet Blockhash: " . $blockhashInfo['blockhash'] . "\n";
    echo "   [SUCCESS] Last Valid Block Height: " . $blockhashInfo['lastValidBlockHeight'] . "\n\n";

    echo "2. Querying live Devnet balance for a known address (e.g. System Program / Test account)...\n";
    // Check balance for a known devnet address or test account
    $testPubkey = '11111111111111111111111111111111';
    $balance = $rpc->getBalance($testPubkey);
    echo "   [SUCCESS] Live Devnet query completed. Balance: {$balance} SOL\n\n";

    echo "3. Generating test Solana custodial keypair...\n";
    $km = new KeyManagementService();
    $newAccount = $km->generateSolanaKeypair();
    echo "   Generated Address: " . $newAccount['publicKey'] . "\n\n";

    echo "4. Testing Associated Token Account (ATA) derivation for Devnet USDC...\n";
    $usdcMint = config('tokens.USDC.mint');
    $derivedAta = $rpc->findAssociatedTokenAddress($newAccount['publicKey'], $usdcMint);
    echo "   USDC Mint: " . $usdcMint . "\n";
    echo "   [SUCCESS] Derived ATA: " . $derivedAta . "\n\n";

    echo "======================================================\n";
    echo "  Solana Devnet is 100% READY and CONNECTED!\n";
    echo "======================================================\n";

} catch (\Throwable $e) {
    echo "   [ERROR] Devnet query failed: " . $e->getMessage() . "\n";
    exit(1);
}
