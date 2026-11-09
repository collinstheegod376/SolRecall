<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SolanaRpcService;
use App\Services\KeyManagementService;
use App\Utils\Base58;

echo "=== Testing Solana Wire Transactions (ATA Idempotent + Fee Sponsor) ===\n\n";

$kms = new KeyManagementService();
$rpc = new SolanaRpcService();

// Generate test user custodial keypair
$userKeypair = sodium_crypto_sign_keypair();
$userSecret = Base58::encode(sodium_crypto_sign_secretkey($userKeypair));
$userPubkey = Base58::encode(sodium_crypto_sign_publickey($userKeypair));

// Generate test hot wallet keypair
$hotKeypair = sodium_crypto_sign_keypair();
$hotSecret = Base58::encode(sodium_crypto_sign_secretkey($hotKeypair));
$hotPubkey = Base58::encode(sodium_crypto_sign_publickey($hotKeypair));

// Destination address
$destKeypair = sodium_crypto_sign_keypair();
$destPubkey = Base58::encode(sodium_crypto_sign_publickey($destKeypair));

echo "1. User Custodial Wallet:  {$userPubkey}\n";
echo "2. Platform Hot Wallet:    {$hotPubkey} (Fee Sponsor)\n";
echo "3. Destination Recipient:  {$destPubkey}\n\n";

$mockBlockhash = Base58::encode(random_bytes(32));

// 1. Test SOL Transfer with Fee Sponsorship
echo "Building SOL Transfer with Platform Hot Wallet Fee Sponsorship...\n";
$solTx = $rpc->buildAndSignSolTransfer($userSecret, $destPubkey, 0.5, $hotSecret, $mockBlockhash);
echo "  [SUCCESS] Wire Tx Base64 length: " . strlen($solTx['txBase64']) . "\n";
echo "  [SUCCESS] Tx Signature: {$solTx['signature']}\n\n";

// Decode and verify wire transaction structure
$solWireBytes = base64_decode($solTx['txBase64']);
// First byte is compact-u16 number of signatures = 2
$numSigs = ord($solWireBytes[0]);
echo "  [VERIFY] Number of signatures in wire SOL tx: {$numSigs} (expected 2)\n";
assert($numSigs === 2, "Expected 2 signatures in sponsored wire transaction");

// 2. Test SPL Token Transfer (USDC) with ATA Idempotent Creation & Fee Sponsorship
echo "Building SPL Transfer (USDC) with Idempotent ATA Instruction + Hot Wallet Fee Sponsor...\n";
$usdcMint = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
$splTx = $rpc->buildAndSignSplTransfer($userSecret, $destPubkey, 25.0, $usdcMint, 6, $hotSecret, $mockBlockhash);
echo "  [SUCCESS] Wire Tx Base64 length: " . strlen($splTx['txBase64']) . "\n";
echo "  [SUCCESS] Tx Signature: {$splTx['signature']}\n\n";

$splWireBytes = base64_decode($splTx['txBase64']);
$splNumSigs = ord($splWireBytes[0]);
echo "  [VERIFY] Number of signatures in wire SPL tx: {$splNumSigs} (expected 2)\n";
assert($splNumSigs === 2, "Expected 2 signatures in sponsored wire transaction");

// Verify signatures
$sig1 = substr($splWireBytes, 1, 64);
$sig2 = substr($splWireBytes, 65, 64);
$message = substr($splWireBytes, 129);

$v1 = sodium_crypto_sign_verify_detached($sig1, $message, Base58::decode($hotPubkey));
$v2 = sodium_crypto_sign_verify_detached($sig2, $message, Base58::decode($userPubkey));

echo "  [VERIFY] Hot Wallet detached signature valid: " . ($v1 ? 'YES' : 'NO') . "\n";
echo "  [VERIFY] User Custodial detached signature valid: " . ($v2 ? 'YES' : 'NO') . "\n";
assert($v1 && $v2, "Both signatures must be cryptographically valid!");

echo "\n======================================================\n";
echo "  ALL WIRE TRANSACTION TESTS PASSED (ATA + FEE SPONSOR)!\n";
echo "======================================================\n";
