<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\KeyManagementService;

$service = new KeyManagementService('my-secret-test-key-123456789012');

// 1. Test Keypair Generation
$keypair = $service->generateSolanaKeypair();
echo "Generated Public Address: {$keypair['publicKey']} (Len: " . strlen($keypair['publicKey']) . ")\n";
echo "Generated Encrypted Key:  " . substr($keypair['encryptedSecretKey'], 0, 20) . "...\n";

// 2. Test Decryption
$decrypted = $service->decrypt($keypair['encryptedSecretKey']);
echo "Decrypted matches SecretKey: " . ($decrypted === $keypair['secretKey'] ? 'YES' : 'NO') . "\n";
assert($decrypted === $keypair['secretKey']);

// 3. Test Plaintext Encrypt/Decrypt
$text = "Transfer 1.5 SOL to 83astBRguLMdt2h5U1Tpdq5LM9KuKHLuGTu2uCpWbhQA";
$encrypted = $service->encrypt($text);
$plain = $service->decrypt($encrypted);
echo "Plaintext matches: " . ($text === $plain ? 'YES' : 'NO') . "\n";
assert($text === $plain);

echo "All KeyManagementService tests passed!\n";
