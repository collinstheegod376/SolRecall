<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Utils\Base58;

// Test Ed25519 on-curve test using bcmath
function isEd25519OnCurve(string $pointBytes): bool
{
    if (strlen($pointBytes) !== 32) {
        return false;
    }

    $p = '57896044618658097711785492504343953926634992332820282019728792003956564819949';
    // d = -121665 * 121666^(-1) mod p
    $d = '37095705934474400726359807042170324144434223467649514787731238080242240973551';

    // Extract y coordinate (first 255 bits, little endian)
    $yBytes = $pointBytes;
    $lastByte = ord($yBytes[31]);
    $signX = ($lastByte >> 7) & 1;
    $yBytes[31] = chr($lastByte & 0x7f);

    // Convert $yBytes (little endian) to bcmath decimal
    $y = '0';
    $power = '1';
    for ($i = 0; $i < 32; $i++) {
        $byte = (string) ord($yBytes[$i]);
        $y = bcadd($y, bcmul($byte, $power));
        $power = bcmul($power, '256');
    }

    if (bccomp($y, $p) >= 0) {
        return false;
    }

    // x^2 = (y^2 - 1) / (d*y^2 + 1) mod p
    $y2 = bcpowmod($y, '2', $p);
    $u = bcmod(bcsub($y2, '1'), $p);
    if (bccomp($u, '0') < 0) {
        $u = bcadd($u, $p);
    }

    $v = bcmod(bcadd(bcmul($d, $y2), '1'), $p);

    // Modular inverse of v: v^(p-2) mod p
    $pMinus2 = bcsub($p, '2');
    $vInv = bcpowmod($v, $pMinus2, $p);

    $x2 = bcmod(bcmul($u, $vInv), $p);

    if (bccomp($x2, '0') === 0) {
        return $signX === 0;
    }

    // Euler's criterion: x2^((p-1)/2) mod p == 1
    $pMinus1Div2 = bcdiv(bcsub($p, '1'), '2', 0);
    $euler = bcpowmod($x2, $pMinus1Div2, $p);

    return bccomp($euler, '1') === 0;
}

function findProgramAddress(array $seeds, string $programIdBase58): array
{
    $programIdBytes = Base58::decode($programIdBase58);

    for ($bump = 255; $bump >= 0; $bump--) {
        $buffer = '';
        foreach ($seeds as $seed) {
            $buffer .= $seed;
        }
        $buffer .= chr($bump);
        $buffer .= $programIdBytes;
        $buffer .= 'ProgramDerivedAddress';

        $hash = hash('sha256', $buffer, true);

        if (!isEd25519OnCurve($hash)) {
            return [
                'address' => Base58::encode($hash),
                'bump' => $bump,
            ];
        }
    }

    throw new RuntimeException('Unable to find viable PDA address');
}

$wallet = '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU';
$mint = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
$tokenProgram = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
$ataProgram = 'ATokenGPvbdGVxr1b2hvZbsiqW5P428224796412';

$seeds = [
    Base58::decode($wallet),
    Base58::decode($tokenProgram),
    Base58::decode($mint),
];

$res = findProgramAddress($seeds, $ataProgram);
echo "Derived ATA: {$res['address']}, bump: {$res['bump']}\n";
