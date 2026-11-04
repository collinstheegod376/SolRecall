<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SolanaRpcService;
use App\Services\KeyManagementService;

echo "=== Live Solana Mainnet Connectivity Check ===\n\n";

$rpcUrl = (string) config('solana.rpc_url', 'https://api.mainnet-beta.solana.com');
$rpc = new SolanaRpcService($rpcUrl, 'confirmed', 10, false);
echo "RPC Endpoint: {$rpcUrl}\n";
echo "Cluster: " . config('solana.cluster') . "\n";
echo "Commitment: confirmed\n";
echo "Simulation Mode: " . ($rpc->isSimulationMode() ? 'ENABLED' : 'DISABLED (Live Cluster Mode)') . "\n\n";

try {
    echo "1. Fetching live recent blockhash from Solana Mainnet...\n";
    $blockhashInfo = $rpc->getRecentBlockhash();
    echo "   [SUCCESS] Live Mainnet Blockhash: " . $blockhashInfo['blockhash'] . "\n";
    echo "   [SUCCESS] Last Valid Block Height: " . $blockhashInfo['lastValidBlockHeight'] . "\n\n";

    echo "2. Querying live Mainnet balance for a known address (Solana System Program)...\n";
    $testPubkey = '11111111111111111111111111111111';
    $balance = $rpc->getBalance($testPubkey);
    echo "   [SUCCESS] Live Mainnet query completed. Balance: {$balance} SOL\n\n";

    echo "3. Generating test Solana custodial keypair...\n";
    $km = new KeyManagementService();
    $newAccount = $km->generateSolanaKeypair();
    echo "   Generated Address: " . $newAccount['publicKey'] . "\n\n";

    echo "4. Testing Associated Token Account (ATA) derivation for Mainnet USDC & USDT...\n";
    $usdcMint = config('tokens.USDC.mint');
    $derivedUsdcAta = $rpc->findAssociatedTokenAddress($newAccount['publicKey'], $usdcMint);
    echo "   USDC Mint: " . $usdcMint . "\n";
    echo "   [SUCCESS] Derived USDC ATA: " . $derivedUsdcAta . "\n";

    $usdtMint = config('tokens.USDT.mint');
    $derivedUsdtAta = $rpc->findAssociatedTokenAddress($newAccount['publicKey'], $usdtMint);
    echo "   USDT Mint: " . $usdtMint . "\n";
    echo "   [SUCCESS] Derived USDT ATA: " . $derivedUsdtAta . "\n\n";

    echo "======================================================\n";
    echo "  Solana Mainnet is 100% READY and CONNECTED!\n";
    echo "======================================================\n";

} catch (\Throwable $e) {
    echo "   [ERROR] Mainnet query failed: " . $e->getMessage() . "\n";
    exit(1);
}
