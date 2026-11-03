<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Utils\Base58;

function encodeCompactU16(int $val): string
{
    $out = '';
    while ($val >= 0x80) {
        $out .= chr(($val & 0x7f) | 0x80);
        $val >>= 7;
    }
    $out .= chr($val & 0x7f);
    return $out;
}

// Test wire serialization
$fromKeypair = sodium_crypto_sign_seed_keypair(hash('sha256', 'sender_seed', true));
$fromPub = sodium_crypto_sign_publickey($fromKeypair);
$fromSecret = sodium_crypto_sign_secretkey($fromKeypair);

$toKeypair = sodium_crypto_sign_seed_keypair(hash('sha256', 'receiver_seed', true));
$toPub = sodium_crypto_sign_publickey($toKeypair);

$systemProgramPub = str_repeat("\x00", 32);

// Message header
$header = chr(1) . chr(0) . chr(1); // 1 req sig, 0 ro signed, 1 ro unsigned
$accountKeys = encodeCompactU16(3) . $fromPub . $toPub . $systemProgramPub;
$recentBlockhash = hash('sha256', 'mock_blockhash_seed', true);

// Instruction: Transfer lamports
$instructionData = pack('V', 2) . pack('P', 1000000); // 0.001 SOL
$instruction = chr(2) . encodeCompactU16(2) . chr(0) . chr(1) . encodeCompactU16(strlen($instructionData)) . $instructionData;
$instructions = encodeCompactU16(1) . $instruction;

$message = $header . $accountKeys . $recentBlockhash . $instructions;

// Sign message
$sig = sodium_crypto_sign_detached($message, $fromSecret);
$wireTx = encodeCompactU16(1) . $sig . $message;
$txBase64 = base64_encode($wireTx);

echo "Serialized Wire Tx Base64: " . substr($txBase64, 0, 40) . "... (Len: " . strlen($txBase64) . ")\n";
echo "Signature Base58: " . Base58::encode($sig) . "\n";
assert(strlen($sig) === 64);
assert(strlen($wireTx) > 100);
