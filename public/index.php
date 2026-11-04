<?php

declare(strict_types=1);

/**
 * Front Controller & Routing Entry Point
 * Undo Window for Solana Transfers (Pure Supabase Architecture)
 */

// 1. Static file routing for PHP built-in web server (php -S)
if (php_sapi_name() === 'cli-server') {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $filePath = __DIR__ . $uriPath;
    if ($uriPath !== '/' && file_exists($filePath) && !is_dir($filePath)) {
        return false;
    }
}

// 2. Load framework bootstrap
$bootstrapFile = dirname(__DIR__) . '/app/bootstrap.php';
if (file_exists($bootstrapFile)) {
    require_once $bootstrapFile;
}

// 3. Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use App\Core\Request;
use App\Core\Response;
use App\Controllers\AuthController;
use App\Controllers\TransferController;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Helper to render PHP views
function renderView(string $fileName): void {
    $filePath = __DIR__ . '/' . ltrim($fileName, '/');
    if (file_exists($filePath)) {
        require $filePath;
        exit;
    }
    http_response_code(404);
    echo "<h1>404 - View Not Found</h1>";
    exit;
}

// Helper to send JSON responses
function sendJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// Web Page Routes
// ------------------------------------------------------------------------------

if ($path === '/' || $path === '/index.php') {
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        header('Location: /dashboard.php');
        exit;
    } else {
        header('Location: /login.php');
        exit;
    }
}

if ($path === '/dashboard' || $path === '/dashboard.php') {
    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    renderView('dashboard.php');
}

if ($path === '/login' || $path === '/login.php') {
    renderView('login.php');
}

if ($path === '/two-factor-verify' || $path === '/two-factor-verify.php') {
    renderView('two-factor-verify.php');
}

if ($path === '/register' || $path === '/register.php') {
    renderView('register.php');
}

if ($path === '/logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /login.php');
    exit;
}

// ------------------------------------------------------------------------------
// API Routes: /api/* (Pure Supabase - No Mock Fallbacks)
// ------------------------------------------------------------------------------

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // D. Cron Endpoint: /api/cron
        if ($path === '/api/cron') {
            require dirname(__DIR__) . '/api/cron.php';
            exit;
        }

        // A. Authentication Endpoints: /api/auth/*
        if (str_starts_with($path, '/api/auth/')) {
            $sub = substr($path, strlen('/api/auth/'));
            $controller = new AuthController();
            $req = Request::createFromGlobals();

            if ($sub === 'me' && $method === 'GET') {
                $controller->me($req)->send();
                exit;
            }

            if ($sub === 'login' && $method === 'POST') {
                $controller->login($req)->send();
                exit;
            }

            if ($sub === 'register' && $method === 'POST') {
                $controller->register($req)->send();
                exit;
            }

            if ($sub === 'logout') {
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
                header('Location: /login.php');
                exit;
            }

            if ($sub === 'solana-nonce' && $method === 'GET') {
                $controller->getSolanaNonce($req)->send();
                exit;
            }

            if ($sub === 'solana-verify' && $method === 'POST') {
                $controller->verifySolanaAuth($req)->send();
                exit;
            }

            if (($sub === '2fa/setup' || $sub === '2fa/generate')) {
                $controller->setup2FA($req)->send();
                exit;
            }

            if (($sub === '2fa/verify' || $sub === '2fa/enable') && $method === 'POST') {
                $controller->verify2FA($req)->send();
                exit;
            }

            if ($sub === '2fa/verify-login' && $method === 'POST') {
                $controller->verifyLogin2FA($req)->send();
                exit;
            }

            if ($sub === '2fa/disable' && $method === 'POST') {
                $controller->disable2FA($req)->send();
                exit;
            }

            if ($sub === 'export-key' && $method === 'POST') {
                $controller->exportKey($req)->send();
                exit;
            }

            sendJson(['success' => false, 'error' => "Auth endpoint '/api/auth/{$sub}' not found"], 404);
        }

        // B. Transfer Endpoints: /api/transfers/*
        if (str_starts_with($path, '/api/transfers/')) {
            $sub = substr($path, strlen('/api/transfers/'));
            $controller = new TransferController();
            $req = Request::createFromGlobals();

            $actionMethod = match ($sub) {
                'balances' => 'balances',
                'sync' => 'sync',
                'pending' => 'pending',
                'history' => 'history',
                'initiate' => 'initiate',
                'cancel' => 'cancel',
                'withdraw' => 'withdraw',
                default => null
            };

            if ($actionMethod && method_exists($controller, $actionMethod)) {
                $controller->$actionMethod($req)->send();
                exit;
            }

            sendJson(['success' => false, 'error' => "Transfer endpoint '/api/transfers/{$sub}' not found"], 404);
        }

        // C. Settings Endpoint: /api/settings
        if ($path === '/api/settings') {
            $controller = new TransferController();
            $req = Request::createFromGlobals();
            $controller->updateSettings($req)->send();
            exit;
        }

        sendJson(['success' => false, 'error' => "API endpoint not found: {$path}"], 404);
    } catch (\Throwable $e) {
        $code = (int) $e->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;
        sendJson([
            'success' => false,
            'error' => $e->getMessage()
        ], $status);
    }
}

// 404 Not Found for Web Pages
http_response_code(404);
echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body style='background:#07090e;color:#fff;font-family:sans-serif;text-align:center;padding:50px;'><h1>404 - Page Not Found</h1><p><a href='/' style='color:#9945FF;'>Return to Dashboard</a></p></body></html>";
