<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Utils\Base58;

$appKey = 'base64:' . base64_encode(random_bytes(32));
$appEncryptionKey = bin2hex(random_bytes(32));
$cronSecret = bin2hex(random_bytes(32));

$hotKeypair = sodium_crypto_sign_keypair();
$hotSecret = Base58::encode(sodium_crypto_sign_secretkey($hotKeypair));
$hotPubkey = Base58::encode(sodium_crypto_sign_publickey($hotKeypair));

$keys = [
    'APP_KEY' => $appKey,
    'APP_ENCRYPTION_KEY' => $appEncryptionKey,
    'CRON_SECRET' => $cronSecret,
    'SOLANA_HOT_WALLET_PUBKEY' => $hotPubkey,
    'SOLANA_HOT_WALLET_PRIVATE_KEY' => $hotSecret,
];

echo json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
