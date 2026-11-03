<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Utils\Base58;

$str = 'Hello Solana Escrow!';
$encoded = Base58::encode($str);
$decoded = Base58::decode($encoded);

echo "Original: {$str}\n";
echo "Encoded:  {$encoded}\n";
echo "Decoded:  {$decoded}\n";
echo "Match:    " . ($str === $decoded ? "YES" : "NO") . "\n";

// Test a 32-byte public key (System Program: 32 zero bytes)
$systemProgramBytes = str_repeat("\x00", 32);
$systemProgramB58 = Base58::encode($systemProgramBytes);
echo "System Program B58: {$systemProgramB58} (Length: " . strlen($systemProgramB58) . ")\n";
assert($systemProgramB58 === '11111111111111111111111111111111', 'System program Base58 mismatch');

echo "All Base58 tests passed!\n";
