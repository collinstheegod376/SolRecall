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

if (!function_exists('ensure_session_started')) {
    function ensure_session_started(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                $lifetime = (int) config('session.lifetime', 86400);
                $cookieName = (string) config('session.cookie_name', 'undo_session');

                session_name($cookieName);
                session_set_cookie_params([
                    'lifetime' => $lifetime,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            @session_start();
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
