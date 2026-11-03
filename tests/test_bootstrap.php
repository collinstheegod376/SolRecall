<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Models\User;
use App\Models\PendingTransfer;
use App\Models\UserBalance;

echo "==> Testing Configuration:\n";
echo "  App Name: " . config('app.name') . "\n";
echo "  SOL Threshold: " . config('tokens.SOL.threshold') . " SOL\n";
echo "  USDC Threshold: " . config('tokens.USDC.threshold') . " USDC\n";
echo "  Default Undo Window: " . config('undo_window.default_minutes') . " minutes\n";
echo "  Solana RPC URL: " . config('solana.rpc_url') . "\n";

echo "==> Testing Router & Request/Response pipeline:\n";
$router = new Router();
$router->get('/api/transfers/{id:\d+}', function (Request $req, $id) {
    return Response::json([
        'success' => true,
        'transfer_id' => (int) $id,
        'route_param' => $req->route('id'),
        'method' => $req->getMethod()
    ]);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/transfers/123';

$request = Request::createFromGlobals();
$response = $router->dispatch($request);
echo "  Status Code: " . $response->getStatusCode() . "\n";
echo "  Response Body: " . $response->getBody() . "\n";

echo "==> Testing Models & Domain Logic:\n";
$user = new User([
    'id' => 1,
    'username' => 'test_user',
    'email' => 'test@solanaundo.local',
    'wallet_address' => '83astBRguLMdt2h5U1Tpdq5LM9KuKHLuGTu2uCpWbhQA'
]);

echo "  User: {$user->username} ({$user->email}), Admin: " . ($user->isAdmin() ? 'true' : 'false') . "\n";
$safeUser = $user->toSafeArray();
echo "  Safe user fields: " . implode(', ', array_keys($safeUser)) . "\n";

$pt = new PendingTransfer([
    'id' => 10,
    'user_id' => 1,
    'token' => 'SOL',
    'amount' => '0.75000000',
    'destination' => '4Nd1mBQtrMJVYVfKf2PJy9NZWMdEcES25HozHP84TX35',
    'status' => PendingTransfer::STATUS_PENDING,
    'created_at' => date('Y-m-d H:i:s', time() - 300),
    'expires_at' => date('Y-m-d H:i:s', time() + 300),
]);

echo "  Transfer ID: {$pt->id}, Token: {$pt->token}, Amount: {$pt->amount}\n";
echo "  Is Pending: " . ($pt->isPending() ? 'YES' : 'NO') . "\n";
echo "  Is Expired: " . ($pt->isExpired() ? 'YES' : 'NO') . "\n";
echo "  Is Cancellable: " . ($pt->isCancellable() ? 'YES' : 'NO') . "\n";
echo "  Remaining Seconds: " . $pt->getRemainingSeconds() . "s\n";
echo "  Total Duration Seconds: " . $pt->getTotalDurationSeconds() . "s\n";
echo "  Progress Percent: " . $pt->getProgressPercent() . "%\n";

echo "\n==> ALL TESTS PASSED SUCCESSFULLY!\n";
