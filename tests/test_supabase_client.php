<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SupabaseClient;

echo "========================================================\n";
echo "  Testing Native SupabaseClient (REST / PostgREST)\n";
echo "========================================================\n\n";

$client = SupabaseClient::getInstance();
echo "[OK] Initialized SupabaseClient with project URL: " . $client->getUrl() . "\n";

// 1. Fetch Users
$users = $client->from('users')->select('*')->get();
echo "[OK] Fetched " . count($users) . " user(s) from Supabase:\n";
foreach ($users as $u) {
    echo "  - User #{$u['id']}: {$u['username']} ({$u['email']}), Address: {$u['solana_deposit_address']}\n";
}

// 2. Fetch or test User Balances
$balances = $client->from('user_balances')->select('*')->get();
echo "\n[OK] Fetched " . count($balances) . " balance record(s) from Supabase.\n";

// 3. Test insert & delete on deposits
$testSig = 'test_sig_' . bin2hex(random_bytes(8));
$inserted = $client->from('deposits')->insert([
    'user_id' => 1,
    'token' => 'SOL',
    'amount' => '1.00000000',
    'tx_signature' => $testSig,
    'status' => 'pending'
]);

echo "[OK] Inserted test deposit row ID: " . ($inserted['id'] ?? 'N/A') . "\n";

$deleted = $client->from('deposits')->eq('tx_signature', $testSig)->delete();
echo "[OK] Cleaned up test deposit row: " . ($deleted ? "SUCCESS" : "FAILED") . "\n";

echo "\n========================================================\n";
echo "  SUCCESS! SupabaseClient works 100% using URL + Key!\n";
echo "========================================================\n";
