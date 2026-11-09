<?php

declare(strict_types=1);

/**
 * Vercel & Supabase Cron Handler: Escrow Expiration Broadcaster
 *
 * Runs automatically every minute via Vercel Cron or Supabase pg_cron
 * to process expired pending transfers and broadcast transactions on-chain.
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Load framework bootstrap & hydrate session
require_once __DIR__ . '/../app/bootstrap.php';
ensure_session_started();

// 1. Authorization check
// Permitted if:
// - Local development (localhost / 127.0.0.1 / CLI)
// - Authenticated session (active user or admin)
// - Supabase pg_cron User-Agent ('Supabase-pg_cron')
// - Vercel Cron User-Agent ('vercel-cron')
// - Bearer token or ?secret= query param matching CRON_SECRET
$cronSecret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: null;
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($clientIp, ['127.0.0.1', '::1', 'localhost'], true) || (php_sapi_name() === 'cli');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
$querySecret = $_GET['secret'] ?? '';
$bearerMatch = preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches) ? $matches[1] : '';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isSupabaseCron = str_contains($userAgent, 'Supabase-pg_cron');
$isVercelCron = str_contains(strtolower($userAgent), 'vercel-cron');
$isAuthenticatedUser = !empty($_SESSION['user_id']);
$matchesSecret = $cronSecret && (
    ($bearerMatch !== '' && hash_equals($cronSecret, $bearerMatch)) ||
    ($querySecret !== '' && hash_equals($cronSecret, $querySecret))
);

$isAuthorized = $isLocal || $isSupabaseCron || $isVercelCron || $isAuthenticatedUser || $matchesSecret || empty($cronSecret);

if (!$isAuthorized) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized cron request']);
    exit;
}

use App\Core\Database;
use App\Services\KeyManagementService;
use App\Services\SolanaRpcService;
use App\Services\TransferService;

$startTime = microtime(true);
$now = date('Y-m-d H:i:s');

try {
    $db = Database::getInstance();
    $rpcService = new SolanaRpcService();
    $keyService = new KeyManagementService();
    $transferService = new TransferService($db, $rpcService, $keyService);

    // Process expired pending transfers on Solana on-chain
    $processed = $transferService->processExpiredTransfers();

    // Scan and credit incoming deposits for active custodial users
    $users = $db->fetchAll("SELECT id FROM users WHERE encrypted_private_key IS NOT NULL AND id != 13 AND solana_deposit_address IS NOT NULL AND solana_deposit_address != ''");
    $totalDepositsCredited = 0;
    foreach ($users as $u) {
        try {
            $totalDepositsCredited += $transferService->syncUserDeposits((int) $u['id']);
        } catch (\Throwable) {
            // Continue scanning other users
        }
    }

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'deposits_credited' => $totalDepositsCredited,
        'timestamp' => $now,
        'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
    ]);
}
