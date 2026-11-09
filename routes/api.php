<?php

declare(strict_types=1);

/**
 * RESTful API Route Definitions
 * Undo Window for Solana Transfers (SolRecall)
 */

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\TransferController;
use App\Controllers\AdminController;

/**
 * Register API routes onto Router instance if provided.
 */
return function (Router $router): void {
    // -------------------------------------------------------------------------
    // Authentication & Security Routes
    // -------------------------------------------------------------------------
    $router->post('/api/auth/register', [AuthController::class, 'register']);
    $router->post('/api/auth/login', [AuthController::class, 'login']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);
    $router->get('/api/auth/me', [AuthController::class, 'me']);
    $router->get('/api/auth/solana-nonce', [AuthController::class, 'getSolanaNonce']);
    $router->post('/api/auth/solana-verify', [AuthController::class, 'verifySolanaAuth']);

    // Two-Factor Authentication (2FA) Dedicated Endpoints
    $router->post('/api/auth/2fa/setup', [AuthController::class, 'setup2FA']);
    $router->get('/api/auth/2fa/generate', [AuthController::class, 'setup2FA']);
    $router->post('/api/auth/2fa/verify', [AuthController::class, 'verify2FA']);
    $router->post('/api/auth/2fa/enable', [AuthController::class, 'verify2FA']);
    $router->post('/api/auth/2fa/verify-login', [AuthController::class, 'verifyLogin2FA']);
    $router->post('/api/auth/2fa/disable', [AuthController::class, 'disable2FA']);

    // Custodial Key Export
    $router->post('/api/auth/export-key', [AuthController::class, 'exportKey']);

    // -------------------------------------------------------------------------
    // Consolidated Dashboard Bootstrap Endpoint (1-Round-Trip SWR)
    // -------------------------------------------------------------------------
    $router->get('/api/dashboard/bootstrap', [TransferController::class, 'bootstrap']);

    // -------------------------------------------------------------------------
    // Transfers & Escrow Endpoints
    // -------------------------------------------------------------------------
    $router->get('/api/transfers/balances', [TransferController::class, 'balances']);
    $router->post('/api/transfers/sync', [TransferController::class, 'sync']);
    $router->get('/api/transfers/pending', [TransferController::class, 'pending']);
    $router->get('/api/transfers/history', [TransferController::class, 'history']);
    $router->post('/api/transfers/initiate', [TransferController::class, 'initiate']);
    $router->post('/api/transfers/cancel', [TransferController::class, 'cancel']);
    $router->post('/api/transfers/withdraw', [TransferController::class, 'withdraw']);

    // Settings
    $router->post('/api/settings', [TransferController::class, 'updateSettings']);

    // -------------------------------------------------------------------------
    // Administrator Operations Endpoints
    // -------------------------------------------------------------------------
    $router->get('/api/admin/stats', [AdminController::class, 'stats']);
    $router->get('/api/admin/transfers', [AdminController::class, 'transfers']);
    $router->post('/api/admin/trigger-cron', [AdminController::class, 'triggerCron']);
    $router->post('/api/admin/force-cancel', [AdminController::class, 'forceCancel']);
    $router->post('/api/admin/force-broadcast', [AdminController::class, 'forceBroadcast']);
    $router->get('/api/admin/users', [AdminController::class, 'users']);
};
