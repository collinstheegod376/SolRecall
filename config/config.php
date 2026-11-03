<?php

declare(strict_types=1);

/**
 * Main Application Configuration
 *
 * Consolidates all environment settings, Solana network RPC endpoints,
 * token metadata and thresholds for the undo hold window.
 */

$databaseConfig = require __DIR__ . '/database.php';

$env = function (string $key, mixed $default = null): mixed {
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
};

return [
    'app' => [
        'name' => (string) $env('APP_NAME', 'Solana Undo Escrow'),
        'env' => (string) $env('APP_ENV', 'development'),
        'debug' => filter_var($env('APP_DEBUG', true), FILTER_VALIDATE_BOOLEAN),
        'url' => (string) $env('APP_URL', 'http://localhost:8000'),
        'key' => (string) $env('APP_KEY', ''),
        'encryption_key' => (string) $env('APP_ENCRYPTION_KEY', ''),
    ],

    'database' => $databaseConfig,

    'solana' => [
        'cluster' => (string) $env('SOLANA_CLUSTER', 'devnet'),
        'rpc_url' => (string) $env('SOLANA_RPC_URL', 'https://api.devnet.solana.com'),
        'commitment' => (string) $env('SOLANA_COMMITMENT', 'confirmed'),
        'hot_wallet_pubkey' => (string) $env('SOLANA_HOT_WALLET_PUBKEY', ''),
        'hot_wallet_private_key' => (string) $env('SOLANA_HOT_WALLET_PRIVATE_KEY', ''),
    ],

    'tokens' => [
        'SOL' => [
            'symbol' => 'SOL',
            'name' => 'Solana Native',
            'decimals' => 9,
            'is_native' => true,
            'mint' => 'So11111111111111111111111111111111111111112',
            'threshold' => (float) $env('THRESHOLD_SOL', 2.0),
            'icon' => 'solana',
        ],
        'USDC' => [
            'symbol' => 'USDC',
            'name' => 'USD Coin',
            'decimals' => 6,
            'is_native' => false,
            'mint' => (string) $env('SOLANA_TOKEN_MINT_USDC', '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU'),
            'threshold' => (float) $env('THRESHOLD_USDC', 100.0),
            'icon' => 'usdc',
        ],
        'USDT' => [
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'decimals' => 6,
            'is_native' => false,
            'mint' => (string) $env('SOLANA_TOKEN_MINT_USDT', 'EJwZgeZrdCraqiSCdtXYWhWsvQTxZeQXP3ZvGWcvppgw'),
            'threshold' => (float) $env('THRESHOLD_USDT', 100.0),
            'icon' => 'usdt',
        ],
    ],

    'undo_window' => [
        'default_minutes' => (int) $env('DEFAULT_UNDO_WINDOW_MINUTES', 10),
        'min_minutes' => (int) $env('MIN_UNDO_WINDOW_MINUTES', 5),
        'max_minutes' => (int) $env('MAX_UNDO_WINDOW_MINUTES', 60),
    ],

    'auth' => [
        'two_factor_issuer' => (string) $env('TWO_FACTOR_ISSUER', 'SolanaUndoEscrow'),
        'google' => [
            'client_id' => (string) $env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => (string) $env('GOOGLE_CLIENT_SECRET', ''),
            'redirect_uri' => (string) $env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/google/callback'),
        ],
    ],

    'session' => [
        'lifetime' => (int) $env('SESSION_LIFETIME', 86400),
        'cookie_name' => (string) $env('SESSION_COOKIE_NAME', 'undo_session'),
    ],
];
