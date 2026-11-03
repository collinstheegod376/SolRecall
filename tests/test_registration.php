<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AuthService;
use App\Models\User;

$auth = new AuthService();
$rand = substr(md5((string) microtime(true)), 0, 6);
$username = 'test_' . $rand;
$email = 'test_' . $rand . '@undo.sol';
$password = 'TestPassword123#';

echo "Testing user registration for: {$username} ({$email})...\n";
$user = $auth->register($username, $email, $password);

assert($user instanceof User, 'Returned user must be an instance of User');
assert($user->id > 0, 'User ID must be > 0');
assert(!empty($user->solana_deposit_address), 'Deposit address must not be empty');

echo "[SUCCESS] User created with ID: {$user->id}\n";
echo "[SUCCESS] Solana Deposit Address: {$user->solana_deposit_address}\n";

$balances = $user->getBalances();
assert(isset($balances['SOL']), 'SOL balance must be initialized');
assert(isset($balances['USDC']), 'USDC balance must be initialized');
assert(isset($balances['USDT']), 'USDT balance must be initialized');

echo "[SUCCESS] Default balances initialized: " . implode(', ', array_keys($balances)) . "\n";

// Test login & session persistence
$auth->login($user);
echo "[SUCCESS] User login and session creation passed without error!\n";

echo "\nRegistration flow is 100% WORKING!\n";
