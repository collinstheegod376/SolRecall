<?php

declare(strict_types=1);

/**
 * Front Controller & Routing Entry Point
 * Undo Window for Solana Transfers (Pure Supabase Architecture)
 */

// 1. Static file routing for PHP built-in web server (php -S)
// Only skip execution if NOT running on Vercel and file is not a PHP script
if (php_sapi_name() === 'cli-server' && empty($_ENV['VERCEL']) && empty($_SERVER['VERCEL']) && empty($_SERVER['NOW_REGION'])) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $filePath = __DIR__ . $uriPath;
    if ($uriPath !== '/' && !str_ends_with($uriPath, '.php') && file_exists($filePath) && !is_dir($filePath)) {
        return false;
    }
}

// 2. Load framework bootstrap
$bootstrapFile = dirname(__DIR__) . '/app/bootstrap.php';
if (file_exists($bootstrapFile)) {
    require_once $bootstrapFile;
}

// 3. Ensure session is started and hydrated from PostgreSQL
ensure_session_started();

use App\Core\Request;
use App\Core\Response;
use App\Controllers\AuthController;
use App\Controllers\TransferController;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Static file fallback (guarantees correct MIME type & delivery under Vercel serverless)
$staticFiles = [
    '/manifest.json' => 'application/manifest+json; charset=utf-8',
    '/sw.js' => 'application/javascript; charset=utf-8',
    '/logo.jpg' => 'image/jpeg',
    '/favicon.ico' => 'image/png',
];

if (isset($staticFiles[$path])) {
    $filePath = __DIR__ . ($path === '/favicon.ico' ? '/assets/img/logo.png' : $path);
    if (file_exists($filePath)) {
        header('Content-Type: ' . $staticFiles[$path]);
        header('Cache-Control: public, max-age=3600');
        readfile($filePath);
        exit;
    }
}

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
// Web Page Routes (Clean URLs)
// ------------------------------------------------------------------------------

if ($path === '/' || $path === '/index.php' || $path === '/landing') {
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && empty($_GET['view'])) {
        header('Location: /dashboard');
        exit;
    }
    
    // Render High-Conviction SolRecall v2 Landing Page with 7-Tier Typography & Mobile Drawer
    $isLoggedIn = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
    $username = htmlspecialchars((string) ($_SESSION['username'] ?? 'User'), ENT_QUOTES, 'UTF-8');
    $depositAddress = htmlspecialchars((string) ($_SESSION['solana_deposit_address'] ?? 'So11111111111111111111111111111111111111112'), ENT_QUOTES, 'UTF-8');
    $isAdmin = (int) ($_SESSION['is_admin'] ?? 0);
    ?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>SolRecall • Reversible Time-Locked Escrow on Solana</title>
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/img/icon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/apple-touch-icon.png">
  <!-- PWA & Mobile Meta Tags -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#000000">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="SolRecall">

  <!-- Google Fonts: 7 Distinct Hierarchy Tiers -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=JetBrains+Mono:wght@400;500;700&family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600&family=Rajdhani:wght@600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            display: ['"Syne"', 'sans-serif'],
            body: ['"Plus Jakarta Sans"', 'sans-serif'],
            stats: ['"Bebas Neue"', 'sans-serif'],
            crypto: ['"JetBrains Mono"', 'monospace'],
            nav: ['"Rajdhani"', 'sans-serif'],
            cta: ['"Outfit"', 'sans-serif'],
            timelock: ['"Cinzel"', 'serif'],
            sans: ['"Plus Jakarta Sans"', 'sans-serif'],
            mono: ['"JetBrains Mono"', 'monospace']
          },
          colors: {
            pitch: '#000000',
            obsidian: {
              DEFAULT: '#08080a',
              light: '#0f0f13',
              card: '#141418'
            },
            hairline: {
              DEFAULT: '#222228',
              light: '#2a2a34'
            },
            stealth: '#94949e',
            solana: {
              purple: '#9945FF',
              green: '#14F195',
              dark: '#000000',
              card: '#141418',
              border: '#222228'
            }
          }
        }
      }
    }
  </script>
  <!-- Custom Stylesheet -->
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '2.0' ?>">
</head>
<body class="min-h-screen bg-[#000000] text-zinc-100 flex flex-col justify-between selection:bg-zinc-800 selection:text-white font-body overflow-x-hidden">

  <!-- ========================================================================= -->
  <!-- Top Navigation Bar                                                        -->
  <!-- ========================================================================= -->
  <header class="border-b border-[#222228] bg-[#08080a]/90 backdrop-blur-md sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">

      <!-- Left: Logo & Project Brand -->
      <a href="/" class="flex items-center gap-2.5 group">
        <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-9 h-9 rounded-xl border border-[#222228] object-cover group-hover:scale-105 transition-transform duration-200">
        <div class="flex items-center gap-2">
          <span class="text-base font-bold tracking-tight text-white font-display">SolRecall</span>
          <span class="text-[10px] font-bold text-amber-400 uppercase font-timelock px-1.5 py-0.5 rounded bg-amber-400/10 border border-amber-400/20">v2.0</span>
        </div>
      </a>

      <!-- Center: Desktop Navigation Links -->
      <nav class="hidden md:flex items-center space-x-6 text-xs font-bold font-nav tracking-wider text-[#94949e]">
        <a href="#features" class="hover:text-white transition">FEATURES</a>
        <a href="#how-it-works" class="hover:text-white transition">ESCROW TIMELOCK</a>
        <a href="#security" class="hover:text-white transition">SECURITY & 2FA</a>
        <a href="#metrics" class="hover:text-white transition">MAINNET METRICS</a>
      </nav>

      <!-- Right: Action Buttons & Mobile Hamburger -->
      <div class="flex items-center gap-3">
        <?php if ($isLoggedIn): ?>
          <a href="/dashboard" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white hover:bg-zinc-200 text-black text-xs font-bold transition font-cta shadow-sm">
            <span>Dashboard &rarr;</span>
          </a>
        <?php else: ?>
          <a href="/login" class="hidden sm:inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold text-[#94949e] hover:text-white border border-[#222228] hover:border-[#2a2a34] bg-[#141418] transition font-cta">
            <span>Sign In</span>
          </a>
          <a href="/register" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white hover:bg-zinc-200 text-black text-xs font-bold transition font-cta shadow-sm">
            <span>Get Started</span>
          </a>
        <?php endif; ?>

        <!-- Mobile Hamburger Toggle Button (#btnLandingMobileMenu / #btnMobileMenu) with 3 Clean Morphing Lines -->
        <button type="button"
                id="btnLandingMobileMenu"
                class="hamburger-btn md:hidden"
                aria-label="Toggle Navigation Drawer"
                aria-expanded="false">
          <span class="hamburger-line line-top"></span>
          <span class="hamburger-line line-mid"></span>
          <span class="hamburger-line line-bot"></span>
        </button>
      </div>

    </div>
  </header>

  <!-- ========================================================================= -->
  <!-- SLIDE-OVER MOBILE DRAWER (#landingMobileDrawer / #mobileDrawer)            -->
  <!-- ========================================================================= -->
  <div id="landingMobileDrawer" class="mobile-drawer-root" role="dialog" aria-modal="true" aria-label="Mobile Navigation">
    
    <!-- Backdrop Blur Overlay -->
    <div id="landingMobileDrawerBackdrop" class="mobile-drawer-backdrop" onclick="typeof closeMobileDrawer === 'function' && closeMobileDrawer()"></div>

    <!-- Drawer Panel (Smooth 60fps slide-in) -->
    <aside id="landingMobileDrawerPanel" class="mobile-drawer-panel">

      <!-- Top: Brand & Close Button -->
      <div class="p-5 border-b border-[#222228] flex items-center justify-between bg-[#08080a]">
        <div class="flex items-center gap-3">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-9 h-9 rounded-xl border border-[#222228] object-cover shadow-sm">
          <div class="flex items-center gap-2">
            <span class="text-base font-bold tracking-tight text-white font-display">SolRecall</span>
            <span class="text-[10px] font-bold text-amber-400 uppercase font-timelock px-1.5 py-0.5 rounded bg-amber-400/10 border border-amber-400/20">v2.0</span>
          </div>
        </div>
        <button type="button"
                data-drawer-close
                onclick="typeof closeMobileDrawer === 'function' && closeMobileDrawer()"
                class="btn-drawer-close w-9 h-9 flex items-center justify-center rounded-xl text-[#94949e] hover:text-white bg-[#141418] border border-[#222228] hover:border-[#2a2a34] transition cursor-pointer"
                aria-label="Close Drawer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Center Scrollable Content -->
      <div class="flex-1 px-5 py-4 space-y-5 overflow-y-auto bg-[#08080a]">

        <!-- Network Pill ("Solana Mainnet-Beta" with solid green indicator) -->
        <div class="flex items-center justify-between p-2.5 rounded-xl bg-[#121215] border border-[#222228]">
          <span class="text-[10px] font-bold text-[#94949e] font-nav tracking-wider">NETWORK STATUS</span>
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-mono text-emerald-400 bg-emerald-500/10 border border-emerald-500/20">
            <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
            <span class="font-medium">Solana Mainnet-Beta</span>
          </span>
        </div>

        <!-- Active User Chip with Sol balance -->
        <div class="p-4 rounded-2xl bg-[#141418] border border-[#222228] space-y-3">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-zinc-800 border border-[#222228] flex items-center justify-center text-xs font-bold text-white shadow-md font-nav">
              <?= $isLoggedIn ? strtoupper(substr($username, 0, 2)) : 'SR' ?>
            </div>
            <div class="overflow-hidden">
              <div class="text-sm font-bold text-white font-display truncate"><?= $isLoggedIn ? $username : 'SolRecall Protocol Escrow' ?></div>
              <div class="text-xs text-[#94949e] font-body truncate"><?= $isLoggedIn ? 'Authenticated Account' : 'Mainnet Protected Vault' ?></div>
            </div>
          </div>

          <div class="pt-2 border-t border-[#222228] flex items-baseline justify-between">
            <span class="text-xs text-[#94949e] font-nav">SOL Balance:</span>
            <div class="text-right">
              <span id="drawerUserSolBalance" class="text-2xl font-bold text-white font-stats tracking-wider"><?= $isLoggedIn ? 'Active' : '1,280.45' ?> SOL</span>
            </div>
          </div>
        </div>

        <!-- Custodial Deposit Address with 1-click copy -->
        <div class="p-3.5 rounded-2xl bg-[#0f0f13] border border-[#222228] space-y-2">
          <div class="flex items-center justify-between text-xs font-nav text-[#94949e]">
            <span class="font-bold tracking-wider">CUSTODIAL DEPOSIT ADDRESS</span>
            <button type="button"
                    id="drawerCopyAddressBtn"
                    onclick="copyDrawerDepositAddress()"
                    class="text-[11px] text-white hover:text-zinc-300 font-cta font-semibold flex items-center gap-1 transition cursor-pointer">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
              <span id="drawerCopyFeedback">Copy</span>
            </button>
          </div>
          <div class="flex items-center justify-between p-2 rounded-xl bg-[#000000] border border-[#222228]">
            <span id="drawerDepositAddressTruncated"
                  class="font-crypto text-xs text-zinc-300 truncate font-mono"
                  data-full="<?= $depositAddress ?>"><?= strlen($depositAddress) > 16 ? substr($depositAddress, 0, 8) . '...' . substr($depositAddress, -8) : $depositAddress ?></span>
            <a href="/login" class="text-xs text-white hover:text-zinc-300 px-2 py-1 rounded bg-[#141418] border border-[#222228] shrink-0 font-cta">QR</a>
          </div>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-1.5 pt-1">
          <div class="px-2 pb-1 text-[10px] font-bold text-[#94949e] uppercase font-nav tracking-wider">Navigation</div>
          
          <a href="<?= $isLoggedIn ? '/dashboard' : '/login' ?>"
             class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav">
            <svg class="w-4 h-4 text-white shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Overview</span>
          </a>

          <a href="<?= $isLoggedIn ? '/dashboard' : '/login' ?>"
             class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav">
            <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <span>Send / Escrow</span>
          </a>

          <a href="<?= $isLoggedIn ? '/dashboard#history' : '/login' ?>"
             class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav">
            <svg class="w-4 h-4 text-[#94949e] shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>Transfer History</span>
          </a>

          <a href="<?= $isLoggedIn ? '/dashboard#settings' : '/login' ?>"
             class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav">
            <svg class="w-4 h-4 text-[#94949e] shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span>Settings & 2FA</span>
          </a>

          <?php if ($isAdmin): ?>
          <a href="/dashboard#admin"
             class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-amber-400 hover:bg-amber-400/10 border border-transparent hover:border-amber-400/20 transition font-nav">
            <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
            <span>Admin Operations</span>
          </a>
          <?php endif; ?>
        </nav>

      </div>

      <!-- Bottom: Disconnect / Sign In Button -->
      <div class="p-5 border-t border-[#222228] space-y-3 bg-[#08080a]">
        <?php if ($isLoggedIn): ?>
          <a href="/api/auth/logout"
             id="drawerLogoutBtn"
             class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl text-xs font-bold text-rose-400 bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/30 transition font-cta">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Disconnect & Sign Out</span>
          </a>
        <?php else: ?>
          <a href="/login"
             class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl text-xs font-bold text-black bg-white hover:bg-zinc-200 transition font-cta">
            <span>Sign In to SolRecall</span>
          </a>
        <?php endif; ?>
        <div class="text-center text-[10px] text-[#71717a] font-nav tracking-wider">
          SOLRECALL PROTOCOL &bull; MAINNET V2.0
        </div>
      </div>

    </aside>
  </div>

  <!-- ========================================================================= -->
  <!-- Hero Section                                                              -->
  <!-- ========================================================================= -->
  <main class="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 flex flex-col justify-center">
    
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
      
      <!-- Hero Copy -->
      <div class="lg:col-span-7 space-y-6 text-left">
        
        <!-- Eyebrow Kicker -->
        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-[#121215] border border-[#222228] text-xs font-bold font-nav text-emerald-400 tracking-wider">
          <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
          <span>REVERSIBLE ESCROW PROTOCOL &bull; SOLANA MAINNET-BETA</span>
        </div>

        <!-- Headline (Syne) -->
        <h1 class="text-4xl sm:text-6xl lg:text-7xl font-extrabold tracking-tight text-white font-display leading-[1.05]">
          Solana transfers with an instant <span class="text-transparent bg-clip-text bg-gradient-to-r from-white via-zinc-200 to-zinc-500">undo window.</span>
        </h1>

        <!-- Body (Plus Jakarta Sans) -->
        <p class="text-base sm:text-lg text-[#94949e] font-body leading-relaxed max-w-2xl">
          Transfer SOL, USDC, and USDT with zero anxiety. If you enter the wrong address or detect a mistake, you have a 10-minute revocable time-lock hold to cancel and recover 100% of your funds before on-chain settlement.
        </p>

        <!-- CTA Action Buttons (Outfit) -->
        <div class="flex flex-wrap items-center gap-4 pt-2">
          <a href="<?= $isLoggedIn ? '/dashboard' : '/register' ?>" class="px-6 py-3.5 rounded-xl bg-white hover:bg-zinc-200 text-black font-bold text-sm transition font-cta flex items-center gap-2 shadow-xl shadow-white/5 active:scale-95 cursor-pointer">
            <span><?= $isLoggedIn ? 'Go to Dashboard' : 'Create Escrow Account' ?></span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
          <a href="<?= $isLoggedIn ? '/dashboard' : '/login' ?>" class="px-6 py-3.5 rounded-xl bg-[#141418] hover:bg-[#1a1a20] border border-[#222228] hover:border-[#2a2a34] text-white font-semibold text-sm transition font-cta cursor-pointer">
            <span>Sign In & Verify</span>
          </a>
        </div>

        <!-- Security Badges (Cinzel) -->
        <div class="pt-4 flex flex-wrap items-center gap-3">
          <span class="px-3 py-1 rounded-lg bg-[#08080a] border border-[#222228] text-[11px] font-bold text-zinc-300 font-timelock tracking-wider">
            10-MINUTE CUSTODIAL REVERSAL
          </span>
          <span class="px-3 py-1 rounded-lg bg-[#08080a] border border-[#222228] text-[11px] font-bold text-zinc-300 font-timelock tracking-wider">
            RFC 6238 2FA ENFORCED
          </span>
          <span class="px-3 py-1 rounded-lg bg-[#08080a] border border-[#222228] text-[11px] font-bold text-zinc-300 font-timelock tracking-wider">
            SELF-CUSTODIAL EXPORT
          </span>
        </div>

      </div>

      <!-- Right: Interactive Escrow Simulator Card -->
      <div class="lg:col-span-5">
        <div class="glass-panel rounded-3xl p-6 sm:p-7 border border-[#222228] shadow-2xl bg-[#08080a]/90 backdrop-blur-xl space-y-6">
          
          <div class="flex items-center justify-between pb-4 border-b border-[#222228]">
            <div class="flex items-center gap-2.5">
              <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
              <span class="text-xs font-bold text-white font-nav uppercase tracking-wider">Active Escrow Hold #4829</span>
            </div>
            <span class="text-xs font-bold text-amber-400 font-timelock px-2.5 py-0.5 rounded-full bg-amber-400/10 border border-amber-400/20">
              09:42 REMAINING
            </span>
          </div>

          <!-- Escrow Amount Display (Bebas Neue) -->
          <div class="p-4 rounded-2xl bg-[#141418] border border-[#222228] space-y-1">
            <span class="text-xs text-[#94949e] font-nav">PROTECTED TRANSFER AMOUNT:</span>
            <div class="flex items-baseline justify-between">
              <span class="text-4xl font-bold text-white font-stats tracking-wider">5.0000 SOL</span>
              <span class="text-sm font-mono text-[#94949e] font-crypto">≈ $925.00</span>
            </div>
          </div>

          <!-- Destination Address (JetBrains Mono) -->
          <div class="space-y-1.5">
            <span class="text-xs text-[#94949e] font-nav uppercase">Destination Solana Address:</span>
            <div class="p-3 rounded-xl bg-[#000000] border border-[#222228] font-crypto text-xs text-zinc-300 flex items-center justify-between">
              <span class="font-mono">7xKXtg2CW87d97TXJSDpbD5j...4wP2</span>
              <span class="text-[10px] text-emerald-400 font-nav uppercase">VALID</span>
            </div>
          </div>

          <!-- Progress Bar -->
          <div class="space-y-1.5">
            <div class="flex items-center justify-between text-[11px] font-nav text-[#94949e]">
              <span>COUNTDOWN TO ON-CHAIN BROADCAST</span>
              <span class="font-mono text-zinc-300">582s</span>
            </div>
            <div class="w-full h-2 rounded-full bg-[#121215] overflow-hidden border border-[#222228]">
              <div class="h-full bg-amber-400 rounded-full w-3/4"></div>
            </div>
          </div>

          <!-- Interactive Action Buttons -->
          <div class="grid grid-cols-2 gap-3 pt-2">
            <a href="/login" class="py-2.5 px-3 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 font-cta font-bold text-xs text-center transition">
              Cancel & Recall Funds
            </a>
            <a href="/login" class="py-2.5 px-3 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-white border border-[#222228] font-cta font-semibold text-xs text-center transition">
              View on Solscan
            </a>
          </div>

        </div>
      </div>

    </div>

    <!-- Big Metric Counters (Bebas Neue) -->
    <div id="metrics" class="mt-20 pt-10 border-t border-[#222228] grid grid-cols-2 md:grid-cols-4 gap-6 text-left">
      <div class="p-4 rounded-2xl bg-[#08080a] border border-[#222228]">
        <span class="text-xs font-bold text-[#94949e] font-nav uppercase tracking-wider">Total Volume Shielded</span>
        <div class="text-3xl sm:text-4xl font-bold text-white font-stats tracking-wider mt-1">$18,429,180</div>
      </div>
      <div class="p-4 rounded-2xl bg-[#08080a] border border-[#222228]">
        <span class="text-xs font-bold text-[#94949e] font-nav uppercase tracking-wider">Mistake Reversal Rate</span>
        <div class="text-3xl sm:text-4xl font-bold text-emerald-400 font-stats tracking-wider mt-1">100.0%</div>
      </div>
      <div class="p-4 rounded-2xl bg-[#08080a] border border-[#222228]">
        <span class="text-xs font-bold text-[#94949e] font-nav uppercase tracking-wider">Default Recall Window</span>
        <div class="text-3xl sm:text-4xl font-bold text-amber-400 font-stats tracking-wider mt-1">10 MINUTES</div>
      </div>
      <div class="p-4 rounded-2xl bg-[#08080a] border border-[#222228]">
        <span class="text-xs font-bold text-[#94949e] font-nav uppercase tracking-wider">Network Gas Sponsored</span>
        <div class="text-3xl sm:text-4xl font-bold text-white font-stats tracking-wider mt-1">0 SOL FEE</div>
      </div>
    </div>

  </main>

  <!-- ========================================================================= -->
  <!-- Footer                                                                    -->
  <!-- ========================================================================= -->
  <footer class="border-t border-[#222228] py-8 bg-[#08080a] text-center text-xs text-[#71717a] font-nav tracking-wider">
    <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div>SOLRECALL PROTOCOL &bull; MAINNET V2.0 &bull; ALL RIGHTS RESERVED</div>
      <div class="flex items-center gap-6">
        <a href="/login" class="hover:text-white transition">SIGN IN</a>
        <a href="/register" class="hover:text-white transition">CREATE ACCOUNT</a>
        <a href="https://solscan.io" target="_blank" rel="noopener noreferrer" class="hover:text-white transition">SOLSCAN EXPLORER</a>
      </div>
    </div>
  </footer>

  <!-- Scripts -->
  <script src="/assets/js/dashboard.js?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '2.0' ?>"></script>
</body>
</html>
    <?php
    exit;
}

if ($path === '/dashboard' || $path === '/dashboard.php') {
    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    renderView('dashboard.php');
}

if ($path === '/login' || $path === '/login.php') {
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        header('Location: /dashboard');
        exit;
    }
    renderView('login.php');
}

if ($path === '/two-factor-verify' || $path === '/two-factor-verify.php') {
    renderView('two-factor-verify.php');
}

if ($path === '/register' || $path === '/register.php') {
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        header('Location: /dashboard');
        exit;
    }
    renderView('register.php');
}

if ($path === '/logout') {
    $sid = session_id();
    if (!empty($sid)) {
        try {
            \App\Core\Database::getInstance()->execute("DELETE FROM public.sessions WHERE id = ?", [$sid]);
        } catch (\Throwable) {}
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
    header('Location: /login');
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
                $sid = session_id();
                if (!empty($sid)) {
                    try {
                        \App\Core\Database::getInstance()->execute("DELETE FROM public.sessions WHERE id = ?", [$sid]);
                    } catch (\Throwable) {}
                }
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_destroy();
                }
                header('Location: /login');
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

        // C. Consolidated Dashboard Bootstrap: /api/dashboard/bootstrap
        if ($path === '/api/dashboard/bootstrap') {
            $controller = new TransferController();
            $req = Request::createFromGlobals();
            $controller->bootstrap($req)->send();
            exit;
        }

        // D. Settings Endpoint: /api/settings
        if ($path === '/api/settings') {
            $controller = new TransferController();
            $req = Request::createFromGlobals();
            $controller->updateSettings($req)->send();
            exit;
        }

        // E. Admin Operations Endpoints: /api/admin/*
        if (str_starts_with($path, '/api/admin/')) {
            $sub = substr($path, strlen('/api/admin/'));
            $controller = new \App\Controllers\AdminController();
            $req = Request::createFromGlobals();

            $actionMethod = match ($sub) {
                'stats' => 'stats',
                'transfers' => 'transfers',
                'trigger-cron' => 'triggerCron',
                'force-cancel' => 'forceCancel',
                'force-broadcast' => 'forceBroadcast',
                'users' => 'users',
                'dev-wallet' => 'devWallet',
                'dev-wallet/export-key' => 'exportDevWalletKey',
                'dev-wallet/transfer' => 'transferDevWallet',
                default => null
            };

            if ($actionMethod && method_exists($controller, $actionMethod)) {
                $controller->$actionMethod($req)->send();
                exit;
            }

            sendJson(['success' => false, 'error' => "Admin endpoint '/api/admin/{$sub}' not found"], 404);
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

// 404 Not Found for Web Pages (Obsidian Theme & 7 Fonts)
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <title>404 - Page Not Found • SolRecall</title>
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=JetBrains+Mono:wght@400;500;700&family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600&family=Rajdhani:wght@600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    body { background: #000000; color: #ffffff; font-family: 'Plus Jakarta Sans', sans-serif; text-align: center; padding: 80px 20px; }
  </style>
</head>
<body>
  <div style="max-width:480px; margin:0 auto; background:#08080a; padding:40px; border-radius:24px; border:1px solid #222228;">
    <h1 style="font-family:'Syne',sans-serif; font-size:32px; font-weight:800; margin-bottom:12px;">404 - Page Not Found</h1>
    <p style="color:#94949e; font-size:14px; margin-bottom:24px;">The requested URL could not be located on the SolRecall protocol.</p>
    <a href="/" style="display:inline-block; padding:12px 24px; background:#ffffff; color:#000000; font-family:'Outfit',sans-serif; font-weight:700; font-size:13px; border-radius:12px; text-decoration:none;">Return to SolRecall</a>
  </div>
</body>
</html>
<?php
exit;
