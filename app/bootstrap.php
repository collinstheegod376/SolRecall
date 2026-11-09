<?php

declare(strict_types=1);

/**
 * Application Bootstrap & Standalone Autoloader
 *
 * Provides PSR-4 class autoloading for App\ namespace without requiring
 * Composer to have been executed, plus environment loading and helper functions.
 */

// Define fundamental path constants
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('APP_PATH')) {
    define('APP_PATH', __DIR__);
}

// ------------------------------------------------------------------------------
// 1. PSR-4 Autoloader & Composer Autoload Fallback
// ------------------------------------------------------------------------------
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = APP_PATH . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// ------------------------------------------------------------------------------
// 2. Environment File Parser (.env loader fallback)
// ------------------------------------------------------------------------------
if (!function_exists('load_environment_file')) {
    function load_environment_file(string $path): void {
        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip enclosing quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }
            putenv("{$key}={$value}");
        }
    }
}

// Load .env if it exists, otherwise try .env.example
$envPath = BASE_PATH . '/.env';
if (file_exists($envPath)) {
    load_environment_file($envPath);
} else {
    load_environment_file(BASE_PATH . '/.env.example');
}

// ------------------------------------------------------------------------------
// 3. Global Helpers: env(), config(), base_path(), etc.
// ------------------------------------------------------------------------------

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        if (array_key_exists($key, $_ENV)) {
            $val = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $val = $_SERVER[$key];
        } else {
            $val = getenv($key);
            if ($val === false) {
                return $default;
            }
        }

        if (is_string($val)) {
            $lower = strtolower($val);
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
            if ($lower === 'null') {
                return null;
            }
            if ($lower === 'empty') {
                return '';
            }
        }

        return $val;
    }
}

if (!function_exists('config')) {
    /**
     * Retrieve configuration values using dot notation.
     * e.g., config('solana.rpc_url') or config('tokens.SOL.threshold')
     */
    function config(?string $key = null, mixed $default = null): mixed {
        static $configs = null;

        if ($configs === null) {
            $configFile = BASE_PATH . '/config/config.php';
            if (file_exists($configFile)) {
                $configs = require $configFile;
            } else {
                $configs = [];
            }
        }

        if ($key === null) {
            return $configs;
        }

        $segments = explode('.', $key);
        $current = $configs;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string {
        return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string {
        return APP_PATH . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string {
        return BASE_PATH . '/public' . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('hydrate_session_from_db_if_needed')) {
    function hydrate_session_from_db_if_needed(): void {
        // If user_id is already set, ensure is_admin and profile fields are loaded
        if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            $uid = (int) $_SESSION['user_id'];
            if (!isset($_SESSION['is_admin']) || empty($_SESSION['username'])) {
                try {
                    $db = \App\Core\Database::getInstance();
                    $u = $db->fetch('SELECT id, username, email, is_admin, solana_deposit_address, two_factor_enabled FROM users WHERE id = ?', [$uid]);
                    if ($u) {
                        $_SESSION['username'] = (string) $u['username'];
                        $_SESSION['email'] = (string) $u['email'];
                        $_SESSION['is_admin'] = (int) ($u['is_admin'] ?? 0);
                        $_SESSION['solana_deposit_address'] = (string) ($u['solana_deposit_address'] ?? '');
                        $_SESSION['two_factor_enabled'] = (int) ($u['two_factor_enabled'] ?? 0);
                    }
                } catch (\Throwable) {}
            }
            return;
        }

        // Collect all candidate session identifiers: active ID, cookies, Bearer header
        $candidates = [];
        $sid = session_id();
        if (!empty($sid)) {
            $candidates[] = $sid;
        }
        $cookieName = (string) config('session.cookie_name', 'undo_session');
        if (!empty($_COOKIE[$cookieName])) {
            $candidates[] = $_COOKIE[$cookieName];
        }
        if (!empty($_COOKIE['PHPSESSID'])) {
            $candidates[] = $_COOKIE['PHPSESSID'];
        }
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (!empty($authHeader) && preg_match('/Bearer\s+([a-zA-Z0-9,\-_]{16,128})/i', $authHeader, $matches)) {
            $candidates[] = $matches[1];
        }

        $candidates = array_unique(array_filter($candidates));
        if (empty($candidates)) {
            return;
        }

        // Look up active session in PostgreSQL
        try {
            $db = \App\Core\Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $sess = $db->fetch(
                "SELECT id, user_id, payload, expires_at FROM public.sessions WHERE id IN ({$placeholders}) AND expires_at > NOW() ORDER BY last_activity DESC LIMIT 1",
                array_values($candidates)
            );

            if ($sess) {
                // Restore session payload if present
                if (!empty($sess['payload'])) {
                    $decoded = json_decode((string) $sess['payload'], true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            if (!isset($_SESSION[$k])) {
                                $_SESSION[$k] = $v;
                            }
                        }
                    }
                }

                if (!empty($sess['user_id'])) {
                    $uid = (int) $sess['user_id'];
                    $u = $db->fetch('SELECT id, username, email, is_admin, solana_deposit_address, two_factor_enabled FROM users WHERE id = ?', [$uid]);
                    if ($u) {
                        $_SESSION['user_id'] = $uid;
                        $_SESSION['username'] = (string) $u['username'];
                        $_SESSION['email'] = (string) $u['email'];
                        $_SESSION['is_admin'] = (int) ($u['is_admin'] ?? 0);
                        $_SESSION['solana_deposit_address'] = (string) ($u['solana_deposit_address'] ?? '');
                        $_SESSION['two_factor_enabled'] = (int) ($u['two_factor_enabled'] ?? 0);

                        $db->execute("UPDATE public.sessions SET last_activity = EXTRACT(EPOCH FROM NOW())::integer WHERE id = ?", [$sess['id']]);
                    }
                }
            }
        } catch (\Throwable) {
            // Database recovery non-fatal
        }
    }
}

if (!function_exists('sync_session_to_db')) {
    function sync_session_to_db(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $sid = session_id();
        if (empty($sid)) {
            return;
        }

        $userId = $_SESSION['user_id'] ?? $_SESSION['pending_2fa_user_id'] ?? null;
        if (!$userId || !is_numeric($userId)) {
            return;
        }

        try {
            $db = \App\Core\Database::getInstance();
            $lifetime = (int) config('session.lifetime', 86400 * 30);
            $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $payload = json_encode($_SESSION);

            $db->execute("
                INSERT INTO public.sessions (id, user_id, ip_address, user_agent, payload, expires_at, last_activity)
                VALUES (?, ?, ?, ?, ?, ?, EXTRACT(EPOCH FROM NOW())::integer)
                ON CONFLICT (id) DO UPDATE SET 
                    user_id = EXCLUDED.user_id,
                    ip_address = COALESCE(EXCLUDED.ip_address, public.sessions.ip_address),
                    user_agent = COALESCE(EXCLUDED.user_agent, public.sessions.user_agent),
                    payload = EXCLUDED.payload,
                    expires_at = EXCLUDED.expires_at,
                    last_activity = EXCLUDED.last_activity
            ", [$sid, (int) $userId, $ip, $ua, $payload, $expiresAt]);
        } catch (\Throwable) {
            // DB session sync non-fatal
        }
    }
}

if (!function_exists('ensure_session_started')) {
    function ensure_session_started(): void {
        static $registeredShutdown = false;

        if (session_status() === PHP_SESSION_ACTIVE) {
            hydrate_session_from_db_if_needed();
            return;
        }

        $lifetime = (int) config('session.lifetime', 86400 * 30);
        $cookieName = (string) config('session.cookie_name', 'undo_session');

        if (!headers_sent()) {
            // Adopt existing session cookie or Bearer token if present
            if (isset($_COOKIE[$cookieName]) && preg_match('/^[a-zA-Z0-9,\-_]{16,128}$/', $_COOKIE[$cookieName])) {
                @session_id($_COOKIE[$cookieName]);
            } elseif (isset($_COOKIE['PHPSESSID']) && preg_match('/^[a-zA-Z0-9,\-_]{16,128}$/', $_COOKIE['PHPSESSID'])) {
                @session_id($_COOKIE['PHPSESSID']);
            } else {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
                if (empty($authHeader) && function_exists('apache_request_headers')) {
                    $headers = apache_request_headers();
                    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
                }
                if (!empty($authHeader) && preg_match('/Bearer\s+([a-zA-Z0-9,\-_]{16,128})/i', $authHeader, $matches)) {
                    @session_id($matches[1]);
                }
            }
            session_name($cookieName);
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
                       (!empty($_SERVER['VERCEL']) || !empty($_SERVER['NOW_REGION']));

            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        @session_start();

        hydrate_session_from_db_if_needed();

        if (!$registeredShutdown) {
            $registeredShutdown = true;
            register_shutdown_function('sync_session_to_db');
        }
    }
}

// ------------------------------------------------------------------------------
// 4. Runtime Configuration
// ------------------------------------------------------------------------------
date_default_timezone_set('UTC');

$debug = (bool) config('app.debug', true);
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
