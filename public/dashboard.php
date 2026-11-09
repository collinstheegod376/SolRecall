<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
ensure_session_started();

// Anti-cache headers to prevent browser from serving stale session or shell assets
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Enforce authenticated session
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Guarantee user details and custodial deposit address are loaded from DB
if (empty($_SESSION['solana_deposit_address']) || empty($_SESSION['username']) || !isset($_SESSION['is_admin'])) {
    try {
        $userModel = \App\Models\User::find($userId);
        if ($userModel) {
            $_SESSION['username'] = (string) $userModel->username;
            $_SESSION['email'] = (string) $userModel->email;
            $_SESSION['solana_deposit_address'] = (string) $userModel->solana_deposit_address;
            $_SESSION['is_admin'] = (int) $userModel->is_admin;
            $_SESSION['two_factor_enabled'] = (int) $userModel->two_factor_enabled;
        }
    } catch (\Throwable $e) {
        // Fall back gracefully
    }
}

$username = htmlspecialchars((string) ($_SESSION['username'] ?? 'User'), ENT_QUOTES, 'UTF-8');
$userEmail = htmlspecialchars((string) ($_SESSION['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$depositAddress = htmlspecialchars((string) ($_SESSION['solana_deposit_address'] ?? ''), ENT_QUOTES, 'UTF-8');
$isAdmin = (int) ($_SESSION['is_admin'] ?? 0);

// Pre-fetch server-side for zero-flicker SSR
$ssrBalances = [
    'SOL'  => ['available' => 0.0, 'escrow' => 0.0, 'price' => 185.0],
    'USDC' => ['available' => 0.0, 'escrow' => 0.0, 'price' => 1.0],
    'USDT' => ['available' => 0.0, 'escrow' => 0.0, 'price' => 1.0],
];
$ssrPending = [];
$ssrHistory = [];
$ssrSettings = ['undoWindowMinutes' => 10];

try {
    $transferService = new \App\Services\TransferService();
    // Fast path: check $_SESSION['cached_bootstrap'][$userId] first (0ms instantaneous load)
    if (!empty($_SESSION['cached_bootstrap'][$userId]) && is_array($_SESSION['cached_bootstrap'][$userId])) {
        $cachedBoot = $_SESSION['cached_bootstrap'][$userId];
        if (!empty($cachedBoot['balances'])) $ssrBalances = $cachedBoot['balances'];
        if (isset($cachedBoot['pending'])) $ssrPending = $cachedBoot['pending'];
        if (isset($cachedBoot['history'])) $ssrHistory = $cachedBoot['history'];
        if (isset($cachedBoot['settings'])) $ssrSettings = $cachedBoot['settings'];
    } else {
        $bootstrapData = $transferService->getBootstrapData($userId);
        if (!empty($bootstrapData['balances'])) $ssrBalances = $bootstrapData['balances'];
        if (isset($bootstrapData['pending'])) $ssrPending = $bootstrapData['pending'];
        if (isset($bootstrapData['history'])) $ssrHistory = $bootstrapData['history'];
        if (isset($bootstrapData['settings'])) $ssrSettings = $bootstrapData['settings'];
    }
    if (isset($ssrBalances['SOL']) && !isset($ssrBalances['SOL']['price'])) $ssrBalances['SOL']['price'] = 185.0;
    if (isset($ssrBalances['USDC']) && !isset($ssrBalances['USDC']['price'])) $ssrBalances['USDC']['price'] = 1.0;
    if (isset($ssrBalances['USDT']) && !isset($ssrBalances['USDT']['price'])) $ssrBalances['USDT']['price'] = 1.0;
} catch (\Throwable $e) {
    // Graceful fallback to default values
}

// Pre-calculate display balances and escrow values for SSR
$ssrSolAvail = (float) ($ssrBalances['SOL']['available'] ?? 0);
$ssrSolEscrow = (float) ($ssrBalances['SOL']['escrow'] ?? 0);
$ssrSolPrice = (float) ($ssrBalances['SOL']['price'] ?? 185.0);
$ssrSolFiat = ($ssrSolAvail + $ssrSolEscrow) * $ssrSolPrice;

$ssrUsdcAvail = (float) ($ssrBalances['USDC']['available'] ?? 0);
$ssrUsdcEscrow = (float) ($ssrBalances['USDC']['escrow'] ?? 0);
$ssrUsdcFiat = ($ssrUsdcAvail + $ssrUsdcEscrow) * 1.0;

$ssrUsdtAvail = (float) ($ssrBalances['USDT']['available'] ?? 0);
$ssrUsdtEscrow = (float) ($ssrBalances['USDT']['escrow'] ?? 0);
$ssrUsdtFiat = ($ssrUsdtAvail + $ssrUsdtEscrow) * 1.0;

// Escrow shielded amounts
$ssrPendingSol = 0.0;
$ssrPendingUsdc = 0.0;
$ssrPendingUsdt = 0.0;
$ssrActivePendingCount = 0;
if (is_array($ssrPending)) {
    foreach ($ssrPending as $p) {
        if (($p['status'] ?? '') === 'pending') {
            $ssrActivePendingCount++;
            $amt = (float) ($p['amount'] ?? 0);
            $tok = strtoupper((string) ($p['token'] ?? ''));
            if ($tok === 'SOL') $ssrPendingSol += $amt;
            elseif ($tok === 'USDC') $ssrPendingUsdc += $amt;
            elseif ($tok === 'USDT') $ssrPendingUsdt += $amt;
        }
    }
}
$ssrActiveSolEscrow = max($ssrSolEscrow, $ssrPendingSol);
$ssrActiveUsdcEscrow = max($ssrUsdcEscrow, $ssrPendingUsdc);
$ssrActiveUsdtEscrow = max($ssrUsdtEscrow, $ssrPendingUsdt);
$ssrActiveStablesEscrow = $ssrActiveUsdcEscrow + $ssrActiveUsdtEscrow;
?>
<!DOCTYPE html>
<html lang="en" class="dark overflow-x-hidden">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SolRecall - Reversible Time-Locked Escrow on Solana</title>
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
  <!-- Client-side QRCode Generator (Offline / Ad-blocker proof) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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
  <!-- Custom Styles -->
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '1.1' ?>">
</head>
<body class="min-h-screen bg-[#000000] text-zinc-100 flex flex-col justify-between selection:bg-zinc-800 selection:text-white pb-28 sm:pb-32 md:pb-8 overflow-x-hidden w-full font-body">

  <!-- Ambient Pure Obsidian Space (Purged AI Radial Blobs) -->

  <div class="relative z-10 flex-1 flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="border-b border-[#222228] bg-[#08080a]/90 backdrop-blur-md sticky top-0 z-30">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">

        <!-- Left: Logo & Project Brand -->
        <div class="flex items-center gap-3">
          <a href="/dashboard" class="flex items-center gap-2.5 group">
            <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-9 h-9 rounded-xl shadow-md border border-purple-500/30 group-hover:scale-105 transition-transform duration-200 object-cover">
            <div>
              <div class="flex items-center gap-2">
                <span class="text-base font-bold tracking-tight text-white font-display">SolRecall</span>
              </div>
            </div>
          </a>
        </div>

        <!-- Center: Desktop Tab Navigation -->
        <nav class="hidden md:flex items-center space-x-1">
          <button type="button" data-tab-target="overview" onclick="switchTab('overview')" class="px-3.5 py-1.5 text-xs font-bold rounded-lg transition nav-tab-active cursor-pointer font-nav tracking-wider">
            Dashboard
          </button>
          <button type="button" data-tab-target="history" onclick="switchTab('history')" class="px-3.5 py-1.5 text-xs font-bold rounded-lg transition nav-tab-inactive cursor-pointer font-nav tracking-wider">
            History
          </button>
          <button type="button" data-tab-target="settings" onclick="switchTab('settings')" class="px-3.5 py-1.5 text-xs font-bold rounded-lg transition nav-tab-inactive cursor-pointer font-nav tracking-wider">
            Settings & 2FA
          </button>
          <?php if ($isAdmin): ?>
          <button type="button" data-tab-target="admin" onclick="switchTab('admin')" class="px-3.5 py-1.5 text-xs font-bold rounded-lg transition nav-tab-inactive flex items-center gap-1.5 text-amber-400 cursor-pointer font-nav tracking-wider">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
            Admin Ops
          </button>
          <?php endif; ?>
        </nav>

        <!-- Right: Deposit Pill, Profile, and Mobile Hamburger -->
        <div class="flex items-center gap-2.5">

          <!-- Deposit Address Pill -->
          <button type="button"
                  onclick="openDepositModal()"
                  class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-[#141418] hover:bg-[#1a1a20] border border-[#222228] hover:border-[#2a2a34] text-xs text-zinc-200 transition group shadow-sm cursor-pointer"
                  title="Click to view Deposit QR Code">
            <span class="text-slate-400 text-[11px] uppercase font-semibold hidden sm:inline">Deposit:</span>
            <span id="userDepositAddress" class="font-mono text-purple-300 font-medium" data-full="<?= $depositAddress ?>"><?= !empty($depositAddress) ? (strlen($depositAddress) > 12 ? substr($depositAddress, 0, 4) . '...' . substr($depositAddress, -4) : $depositAddress) : 'Deposit' ?></span>
            <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-white transition" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
          </button>

          <!-- Desktop User Menu -->
          <div class="hidden sm:flex items-center gap-2 pl-2 border-l border-white/10">
            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-purple-600 to-indigo-500 flex items-center justify-center text-xs font-bold text-white shadow-md">
              <?= strtoupper(substr($username, 0, 2)) ?>
            </div>
            <a href="/api/auth/logout" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition" title="Sign Out">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
          </div>

          <!-- Mobile Hamburger Toggle Button (#btnMobileMenu) with 3 Clean Morphing Lines -->
          <button type="button"
                  id="btnMobileMenu"
                  class="hamburger-btn md:hidden"
                  aria-label="Toggle Mobile Navigation Drawer"
                  aria-expanded="false">
            <span class="hamburger-line line-top"></span>
            <span class="hamburger-line line-mid"></span>
            <span class="hamburger-line line-bot"></span>
          </button>

        </div>

      </div>

      <!-- Mobile Dropdown Menu under Header -->
      <div id="mobileDropdownMenu" class="hidden md:hidden border-t border-white/10 bg-[#090d16]/98 backdrop-blur-xl px-4 py-3 space-y-1 shadow-2xl">
        <button type="button" onclick="switchTab('overview'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-200 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
          Dashboard
        </button>
        <button type="button" onclick="switchTab('history'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-200 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          History & Ledger
        </button>
        <button type="button" onclick="switchTab('settings'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-200 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
          Settings & 2FA
        </button>
        <?php if ($isAdmin): ?>
        <button type="button" onclick="switchTab('admin'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-amber-400 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          Admin Ops
        </button>
        <?php endif; ?>
        <div class="pt-2 mt-2 border-t border-white/10 flex items-center justify-between text-xs text-slate-400 px-3 py-1">
          <div class="flex items-center gap-2">
            <div class="w-6 h-6 rounded-full bg-purple-600 flex items-center justify-center text-[10px] font-bold text-white">
              <?= strtoupper(substr($username, 0, 2)) ?>
            </div>
            <span><?= htmlspecialchars($username) ?></span>
          </div>
          <a href="/api/auth/logout" class="text-rose-400 font-semibold hover:underline">Sign Out</a>
        </div>
      </div>
    </header>

    <!-- Main Content Area -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full flex-1">

      <!-- ========================================== -->
      <!-- TAB 1: OVERVIEW / DASHBOARD               -->
      <!-- ========================================== -->
      <section id="tab-content-overview" class="dashboard-tab-content space-y-8">

        <!-- Balance Summary Grid (SOL, USDC, USDT, and Escrow Shield) with Real Official Logos -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

          <!-- SOL Balance Card -->
          <div class="glass-panel glass-panel-hover rounded-2xl p-5 relative overflow-hidden">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2.5">
                <img src="/assets/img/sol.png"
                     onerror="this.src='/assets/img/sol.png'"
                     alt="Solana (SOL)"
                     class="w-8 h-8 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-purple-500/30">
                <div>
                  <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">Solana (SOL)</h3>
                  <span id="bal-fiat-sol" class="text-[11px] text-slate-400 font-mono">≈ $<?= number_format($ssrSolFiat, 2) ?></span>
                </div>
              </div>
              <button onclick="openDepositModal()" class="text-xs text-purple-400 hover:text-purple-300 font-semibold transition">+ Deposit</button>
            </div>
            <div class="space-y-1.5 mt-2">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-400">Available:</span>
                <span id="bal-avail-sol" class="text-2xl font-bold text-white font-stats tracking-wider"><?= number_format($ssrSolAvail, 4, '.', '') ?></span>
              </div>
              <div class="flex items-baseline justify-between text-xs pt-1 border-t border-white/5">
                <span class="text-amber-400/90 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> In Escrow:
                </span>
                <span id="bal-escrow-sol" class="font-crypto text-amber-300 font-semibold"><?= number_format($ssrSolEscrow, 4, '.', '') ?></span>
              </div>
            </div>
          </div>

          <!-- USDC Balance Card -->
          <div class="glass-panel glass-panel-hover rounded-2xl p-5 relative overflow-hidden">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2.5">
                <img src="/assets/img/usdc.png"
                     onerror="this.src='/assets/img/usdc.png'"
                     alt="USD Coin (USDC)"
                     class="w-8 h-8 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-blue-500/30">
                <div>
                  <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">USD Coin (USDC)</h3>
                  <span id="bal-fiat-usdc" class="text-[11px] text-slate-400 font-mono">≈ $<?= number_format($ssrUsdcFiat, 2) ?></span>
                </div>
              </div>
              <button onclick="openDepositModal()" class="text-xs text-blue-400 hover:text-blue-300 font-semibold transition">+ Deposit</button>
            </div>
            <div class="space-y-1.5 mt-2">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-400">Available:</span>
                <span id="bal-avail-usdc" class="text-2xl font-bold text-white font-stats tracking-wider"><?= number_format($ssrUsdcAvail, 2, '.', '') ?></span>
              </div>
              <div class="flex items-baseline justify-between text-xs pt-1 border-t border-white/5">
                <span class="text-amber-400/90 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> In Escrow:
                </span>
                <span id="bal-escrow-usdc" class="font-crypto text-amber-300 font-semibold"><?= number_format($ssrUsdcEscrow, 2, '.', '') ?></span>
              </div>
            </div>
          </div>

          <!-- USDT Balance Card -->
          <div class="glass-panel glass-panel-hover rounded-2xl p-5 relative overflow-hidden">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2.5">
                <img src="/assets/img/usdt.png"
                     onerror="this.src='/assets/img/usdt.png'"
                     alt="Tether (USDT)"
                     class="w-8 h-8 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-emerald-500/30">
                <div>
                  <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">Tether (USDT)</h3>
                  <span id="bal-fiat-usdt" class="text-[11px] text-slate-400 font-mono">≈ $<?= number_format($ssrUsdtFiat, 2) ?></span>
                </div>
              </div>
              <button onclick="openDepositModal()" class="text-xs text-emerald-400 hover:text-emerald-300 font-semibold transition">+ Deposit</button>
            </div>
            <div class="space-y-1.5 mt-2">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-400">Available:</span>
                <span id="bal-avail-usdt" class="text-2xl font-bold text-white font-stats tracking-wider"><?= number_format($ssrUsdtAvail, 2, '.', '') ?></span>
              </div>
              <div class="flex items-baseline justify-between text-xs pt-1 border-t border-white/5">
                <span class="text-amber-400/90 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> In Escrow:
                </span>
                <span id="bal-escrow-usdt" class="font-crypto text-amber-300 font-semibold"><?= number_format($ssrUsdtEscrow, 2, '.', '') ?></span>
              </div>
            </div>
          </div>

          <!-- Escrow Shield Summary Card -->
          <div class="glass-panel rounded-2xl p-5 relative overflow-hidden border-amber-500/30 bg-gradient-to-br from-amber-500/10 via-transparent to-purple-600/10">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-bold uppercase tracking-wider text-amber-300 flex items-center gap-1.5 font-sans">
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                Escrow Protected
              </span>
              <span id="pendingTransfersCount" class="<?= $ssrActivePendingCount > 0 ? 'inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-amber-300 bg-amber-500/20 border border-amber-500/40 rounded-full' : 'hidden' ?>"><?= $ssrActivePendingCount ?></span>
            </div>
            <div class="text-2xl font-black text-white font-mono tracking-tight mt-1 flex items-baseline gap-2 flex-wrap" id="totalEscrowShielded">
              <?php if ($ssrActiveSolEscrow > 0 && $ssrActiveStablesEscrow > 0): ?>
                <span class="font-mono text-white text-2xl font-black"><?= number_format($ssrActiveSolEscrow, 4, '.', '') ?></span>
                <span class="text-xs font-bold text-amber-400 font-sans tracking-normal ml-1">SOL</span>
                <span class="text-slate-400 text-sm font-sans mx-2">+</span>
                <span class="font-mono text-emerald-400 text-2xl font-black">$<?= number_format($ssrActiveStablesEscrow, 2, '.', '') ?></span>
              <?php elseif ($ssrActiveSolEscrow > 0): ?>
                <span class="font-mono text-white text-3xl font-black tracking-tight"><?= number_format($ssrActiveSolEscrow, 4, '.', '') ?></span>
                <span class="text-sm font-bold text-amber-400 font-sans tracking-normal ml-1.5">SOL</span>
              <?php elseif ($ssrActiveStablesEscrow > 0): ?>
                <span class="font-mono text-emerald-400 text-3xl font-black tracking-tight">$<?= number_format($ssrActiveStablesEscrow, 2, '.', '') ?></span>
                <span class="text-xs font-bold text-slate-300 font-sans ml-1.5"><?= ($ssrActiveUsdcEscrow > 0 && $ssrActiveUsdtEscrow > 0) ? 'USDC + USDT' : ($ssrActiveUsdcEscrow > 0 ? 'USDC' : 'USDT') ?></span>
              <?php else: ?>
                <span class="font-mono text-slate-400 text-2xl font-black">0.0000 SOL</span>
              <?php endif; ?>
            </div>
            <div id="escrowBreakdownBadges" class="flex items-center gap-1.5 mt-2 flex-wrap">
              <?php if ($ssrActiveSolEscrow > 0): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-mono bg-purple-500/15 text-purple-300 border border-purple-500/30">
                  <span class="w-1.5 h-1.5 rounded-full bg-purple-400"></span> <?= number_format($ssrActiveSolEscrow, 4, '.', '') ?> SOL (≈$<?= number_format($ssrActiveSolEscrow * $ssrSolPrice, 2) ?>)
                </span>
              <?php endif; ?>
              <?php if ($ssrActiveUsdcEscrow > 0): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-mono bg-blue-500/15 text-blue-300 border border-blue-500/30">
                  <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span> $<?= number_format($ssrActiveUsdcEscrow, 2, '.', '') ?> USDC
                </span>
              <?php endif; ?>
              <?php if ($ssrActiveUsdtEscrow > 0): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-mono bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> $<?= number_format($ssrActiveUsdtEscrow, 2, '.', '') ?> USDT
                </span>
              <?php endif; ?>
            </div>
            <p class="text-[11px] text-slate-300 mt-2 leading-relaxed" id="escrowProtectedSubtext">
              <?= $ssrActivePendingCount > 0 ? "{$ssrActivePendingCount} active hold" . ($ssrActivePendingCount > 1 ? 's' : '') . " in undo window. Revocable instantly before countdown elapses." : "Funds in the undo window can be revoked instantly before the countdown elapses." ?>
            </p>
          </div>

        </div>

        <!-- Two Column Layout: Pending Transfers (Left) & Sticky Send Form (Right) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

          <!-- Left Column (7 cols): Active Pending Escrow Transfers -->
          <div class="lg:col-span-7 space-y-4">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <h2 class="text-lg font-bold text-white tracking-tight">Active Undo Holds</h2>
              </div>
            </div>

            <!-- Pending Transfers Container -->
            <div id="pendingTransfersContainer" class="space-y-3">
              <!-- Rendered dynamically by dashboard.js with official logos and draining progress bar -->
            </div>

            <!-- Empty State for Pending Transfers -->
            <div id="pendingTransfersEmpty" class="glass-panel rounded-2xl p-8 text-center border-dashed border-white/10">
              <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-800/80 border border-white/5 flex items-center justify-center text-slate-400 mb-3 shadow-inner">
                <svg class="w-7 h-7 text-purple-400/80" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
              </div>
              <h4 class="text-sm font-bold text-white mb-1">No Active Escrow Holds</h4>
              <p class="text-xs text-slate-400 max-w-sm mx-auto leading-relaxed">
                When you initiate a small transfer under the threshold, it will appear here with an active countdown timer and 1-click cancel button.
              </p>
            </div>
          </div>

          <!-- Right Column (5 cols): Sticky Send Form on Desktop -->
          <div class="lg:col-span-5 lg:sticky lg:top-24">
            <div class="glass-panel rounded-3xl p-6 sm:p-7 border border-white/10 shadow-xl relative">

              <div class="flex items-center justify-between mb-5">
                <div>
                  <h2 class="text-base font-bold text-white">Send Solana Assets</h2>
                  <p class="text-xs text-slate-400">Small amounts qualify for undo window</p>
                </div>
                <div class="w-7 h-7 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </div>
              </div>

              <!-- Form -->
              <form id="newTransferForm" class="space-y-4">

                <!-- Token Selector & Amount Grid with Token Logo Indicator -->
                <div class="grid grid-cols-3 gap-3">
                  <div class="col-span-1">
                    <label for="transferToken" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">
                      Token
                    </label>
                    <div class="relative">
                      <select id="transferToken" class="w-full pl-9 pr-3 py-2.5 rounded-xl bg-slate-900 border border-white/10 text-white font-medium text-sm focus:outline-none focus:border-purple-500 transition appearance-none">
                        <option value="SOL">SOL</option>
                        <option value="USDC">USDC</option>
                        <option value="USDT">USDT</option>
                      </select>
                      <div class="absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none">
                        <img id="formTokenLogo" src="/assets/img/sol.png" onerror="this.src='/assets/img/sol.png'" alt="Token" class="w-4 h-4 rounded-full object-contain">
                      </div>
                      <div class="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400 text-xs">
                        ▼
                      </div>
                    </div>
                  </div>

                  <div class="col-span-2">
                    <div class="flex items-center justify-between mb-1.5">
                      <label for="transferAmount" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Amount
                      </label>
                      <button type="button" id="maxAmountBtn" class="text-[11px] font-bold text-purple-400 hover:text-purple-300">
                        MAX
                      </button>
                    </div>
                    <input type="number"
                           id="transferAmount"
                           step="any"
                           min="0.0001"
                           required
                           placeholder="0.00"
                           class="w-full px-3 py-2.5 rounded-xl bg-slate-900 border border-white/10 text-white font-mono text-sm focus:outline-none focus:border-purple-500 transition">
                  </div>
                </div>

                <!-- Available Balance Hint & Quick Percent Buttons -->
                <div class="flex items-center justify-between text-xs">
                  <span id="availableAmountHint" class="text-slate-400 font-mono">Available: 0.00 SOL</span>
                  <div class="flex items-center gap-1.5">
                    <button type="button" data-percent="25" class="percent-btn px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-[10px] text-slate-300 transition">25%</button>
                    <button type="button" data-percent="50" class="percent-btn px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-[10px] text-slate-300 transition">50%</button>
                    <button type="button" data-percent="100" class="percent-btn px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-[10px] text-slate-300 transition">100%</button>
                  </div>
                </div>

                <!-- Recipient Solana Address -->
                <div>
                  <label for="transferDestination" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">
                    Destination Solana Address
                  </label>
                  <input type="text"
                         id="transferDestination"
                         required
                         autocomplete="off"
                         placeholder="Recipient Solana wallet address..."
                         class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-purple-500 transition">
                </div>

                <!-- Memo / Note -->
                <div>
                  <label for="transferNote" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">
                    Note / Memo <span class="text-slate-500 font-normal lowercase">(optional)</span>
                  </label>
                  <input type="text"
                         id="transferNote"
                         maxlength="80"
                         placeholder="e.g. Design invoice #1029"
                         class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs focus:outline-none focus:border-purple-500 transition">
                </div>

                <!-- Escrow Hold / Timelock Duration Selection (Minutes to Years) -->
                <div>
                  <div class="flex items-center justify-between mb-1.5">
                    <label for="transferWindowPreset" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                      Escrow Hold / Timelock Duration
                    </label>
                    <span class="text-[11px] text-purple-400 font-medium">Minutes to Years</span>
                  </div>
                  <select id="transferWindowPreset" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-white/10 text-white text-xs focus:outline-none focus:border-purple-500 transition">
                    <option value="5">5 Minutes</option>
                    <option value="10" selected>10 Minutes (Default)</option>
                    <option value="60">1 Hour</option>
                    <option value="1440">24 Hours (1 Day)</option>
                    <option value="10080">7 Days (1 Week)</option>
                    <option value="43200">30 Days (1 Month)</option>
                    <option value="525600">1 Year (365 Days)</option>
                    <option value="1051200">2 Years</option>
                    <option value="2628000">5 Years</option>
                    <option value="custom">Custom Duration (Years, Months, Days)...</option>
                  </select>

                  <div id="customDurationBox" class="hidden grid grid-cols-2 gap-2 mt-2">
                    <input type="number" id="customDurationValue" min="1" value="1" placeholder="Amount" class="px-3.5 py-2 rounded-xl bg-slate-950 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-purple-500">
                    <select id="customDurationUnit" class="px-3 py-2 rounded-xl bg-slate-950 border border-white/10 text-white text-xs focus:outline-none focus:border-purple-500">
                      <option value="minutes">Minutes</option>
                      <option value="hours">Hours</option>
                      <option value="days">Days</option>
                      <option value="weeks">Weeks</option>
                      <option value="months">Months</option>
                      <option value="years" selected>Years</option>
                    </select>
                  </div>
                </div>

                <!-- Dynamic Threshold & Fee Breakdown Card -->
                <div id="transferNoticeBox" class="rounded-xl p-3.5 bg-slate-800/60 border border-white/5 text-slate-400 text-xs">
                  <div id="transferNoticeText">
                    <strong>ℹ️ Small Transfer Protection:</strong> Transfers up to 2.0 SOL are held in a 10-minute escrow window before broadcasting.
                  </div>
                </div>

                <!-- Live Fee & Gas Status Footer -->
                <div class="pt-1 flex items-center justify-between text-[11px] text-slate-400">
                  <span id="transferPlatformFeeFooter">Platform Fee (12%): <strong id="transferPlatformFeeFooterVal" class="text-amber-400 font-mono">+0.0000 SOL</strong></span>
                  <span id="transferGasFeeFooter">Network Gas: <strong id="transferGasFeeFooterVal" class="text-slate-300 font-mono">0.000005 SOL</strong></span>
                </div>

                <!-- Submit Button -->
                <button type="submit"
                        id="submitTransferBtn"
                        class="w-full py-3.5 px-4 rounded-xl font-bold text-white bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:to-indigo-500 border border-purple-400/30 transition shadow-lg shadow-purple-600/20 flex items-center justify-center gap-2 text-sm mt-2">
                  <span id="submitTransferBtnSpinner" class="hidden spinner-sm"></span>
                  <span id="submitTransferBtnText">Initiate Transfer</span>
                </button>

              </form>

            </div>
          </div>

        </div>

      </section>

      <!-- ========================================== -->
      <!-- TAB 2: HISTORY                            -->
      <!-- ========================================== -->
      <section id="tab-content-history" class="dashboard-tab-content hidden space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Transfer Ledger & History</h2>
            <p class="text-xs text-slate-400">Audit trail of all pending, completed, and undone transfers</p>
          </div>

          <!-- Search & Filter Controls -->
          <div class="flex flex-wrap items-center gap-3">
            <div class="relative">
              <input type="text"
                     id="historySearchInput"
                     placeholder="Search destination or signature..."
                     class="px-3.5 py-1.5 pl-8 rounded-xl bg-slate-900 border border-white/10 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-purple-500 w-56 sm:w-64">
              <svg class="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>

            <!-- Filter Badges -->
            <div class="flex items-center gap-1 bg-slate-900 p-1 rounded-xl border border-white/10 text-xs">
              <button type="button" data-filter="all" class="history-filter-btn px-2.5 py-1 rounded-lg font-medium bg-purple-600 text-white transition">All</button>
              <button type="button" data-filter="pending" class="history-filter-btn px-2.5 py-1 rounded-lg font-medium text-slate-400 hover:text-white transition">Pending</button>
              <button type="button" data-filter="completed" class="history-filter-btn px-2.5 py-1 rounded-lg font-medium text-slate-400 hover:text-white transition">Completed</button>
              <button type="button" data-filter="cancelled" class="history-filter-btn px-2.5 py-1 rounded-lg font-medium text-slate-400 hover:text-white transition">Cancelled</button>
            </div>
          </div>
        </div>

        <!-- History Table Card with Dual Desktop Table & Sleek Mobile Cards -->
        <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-xl">
          <!-- Desktop View: Full Responsive Table (Hidden on Mobile) -->
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr class="border-b border-white/10 bg-slate-900/60 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                  <th class="py-3.5 px-4">Date / Time</th>
                  <th class="py-3.5 px-4">Amount</th>
                  <th class="py-3.5 px-4">Destination</th>
                  <th class="py-3.5 px-4">Memo</th>
                  <th class="py-3.5 px-4">Status</th>
                  <th class="py-3.5 px-4">On-Chain Explorer</th>
                </tr>
              </thead>
              <tbody id="historyTableBody">
                <!-- Rendered by dashboard.js with official token logos -->
              </tbody>
            </table>
          </div>

          <!-- Mobile View: High-Conviction Compact Cards (Hidden on Desktop) -->
          <div id="historyMobileList" class="md:hidden space-y-3 p-3.5">
            <!-- Rendered dynamically by dashboard.js for mobile screens -->
          </div>

          <!-- Empty Row -->
          <div id="historyEmptyRow" class="hidden py-12 text-center text-slate-400 text-xs">
            No transfer records match your current filter or search criteria.
          </div>
        </div>

      </section>

      <!-- ========================================== -->
      <!-- TAB 3: SETTINGS & 2FA                     -->
      <!-- ========================================== -->
      <section id="tab-content-settings" class="dashboard-tab-content hidden space-y-6">

        <div>
          <h2 class="text-xl font-bold text-white tracking-tight">Security & Escrow Settings</h2>
          <p class="text-xs text-slate-400">Configure your undo window delays, per-token thresholds, and key backup</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

          <!-- Undo Window & Threshold Preferences -->
          <div class="glass-panel rounded-3xl p-6 border border-white/10 space-y-5">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-purple-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </div>
              <div>
                <h3 class="text-sm font-bold text-white">Undo Window Duration</h3>
                <p class="text-xs text-slate-400">Escrow hold time before on-chain broadcast</p>
              </div>
            </div>

            <div>
              <label for="settingsUndoWindow" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">
                Default Escrow Hold Length
              </label>
              <select id="settingsUndoWindow" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-white/10 text-white text-sm focus:outline-none focus:border-purple-500 transition">
                <option value="5">5 Minutes (Quick check)</option>
                <option value="10" selected>10 Minutes (Standard)</option>
                <option value="60">1 Hour</option>
                <option value="1440">24 Hours (1 Day)</option>
                <option value="10080">7 Days (1 Week)</option>
                <option value="43200">30 Days (1 Month)</option>
                <option value="525600">1 Year (365 Days)</option>
                <option value="1051200">2 Years</option>
                <option value="2628000">5 Years</option>
              </select>
            </div>

            <!-- Per Token Thresholds -->
            <div class="pt-3 border-t border-white/5 space-y-3">
              <h4 class="text-xs font-bold uppercase tracking-wider text-slate-300">Small Transfer Thresholds</h4>
              <p class="text-xs text-slate-400">Transfers equal or below these values enter the undo window. Larger transfers broadcast instantly.</p>

              <div class="grid grid-cols-3 gap-3 pt-1">
                <div>
                  <label class="block text-[11px] font-semibold text-slate-400 mb-1 flex items-center gap-1">
                    <img src="/assets/img/sol.png" onerror="this.src='/assets/img/sol.png'" alt="SOL" class="w-3.5 h-3.5 rounded-full inline">
                    SOL
                  </label>
                  <input type="number" id="settingsThreshSol" step="0.1" value="2.0" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
                </div>
                <div>
                  <label class="block text-[11px] font-semibold text-slate-400 mb-1 flex items-center gap-1">
                    <img src="/assets/img/usdc.png" onerror="this.src='/assets/img/usdc.png'" alt="USDC" class="w-3.5 h-3.5 rounded-full inline">
                    USDC
                  </label>
                  <input type="number" id="settingsThreshUsdc" step="10" value="100.0" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
                </div>
                <div>
                  <label class="block text-[11px] font-semibold text-slate-400 mb-1 flex items-center gap-1">
                    <img src="/assets/img/usdt.png" onerror="this.src='/assets/img/usdt.png'" alt="USDT" class="w-3.5 h-3.5 rounded-full inline">
                    USDT
                  </label>
                  <input type="number" id="settingsThreshUsdt" step="10" value="100.0" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
                </div>
              </div>
            </div>

            <button type="button" id="saveSettingsBtn" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 font-semibold text-white text-xs transition">
              Save Preferences
            </button>
          </div>

          <!-- 2FA & Custodial Key Export -->
          <div class="space-y-6">

            <!-- 2FA Setup Card -->
            <div class="glass-panel rounded-3xl p-6 border border-white/10 space-y-4">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  </div>
                  <div>
                    <h3 class="text-sm font-bold text-white">Two-Factor Authentication (2FA)</h3>
                    <p class="text-xs text-slate-400">Google Authenticator or TOTP</p>
                  </div>
                </div>
                <span id="settingsTwoFaBadge" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= !empty($_SESSION['two_factor_enabled']) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-amber-500/10 text-amber-400 border border-amber-500/30' ?>">
                  <?= !empty($_SESSION['two_factor_enabled']) ? 'Active' : 'Inactive' ?>
                </span>
              </div>
              <p class="text-xs text-slate-400">
                2FA protects critical actions like private key export and immediate token withdrawals.
              </p>
              <div class="flex flex-col sm:flex-row gap-2">
                <button type="button" onclick="open2FaModal()" class="flex-1 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/10 text-xs font-medium text-slate-200 transition">
                  Configure / Re-verify 2FA
                </button>
                <button type="button"
                        id="btnDelete2Fa"
                        onclick="openDelete2FaModal()"
                        class="<?= empty($_SESSION['two_factor_enabled']) ? 'hidden ' : '' ?>py-2 px-3 rounded-xl border border-rose-500/40 text-rose-400 hover:bg-rose-500/10 hover:border-rose-500/60 text-xs font-medium transition flex items-center justify-center gap-1.5 cursor-pointer">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                  Delete / Reset 2FA Key
                </button>
              </div>
            </div>

            <!-- Custodial Key Export Security Card -->
            <div class="glass-panel rounded-3xl p-6 border border-rose-500/30 bg-rose-500/[0.03] space-y-4">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                </div>
                <div>
                  <h3 class="text-sm font-bold text-white">Export Custodial Private Key</h3>
                  <p class="text-xs text-rose-300">Self-custody transition</p>
                </div>
              </div>
              <p class="text-xs text-slate-400 leading-relaxed">
                You can decrypt and export your raw Solana deposit keypair. Once exported, you can import it into Phantom, Backpack, or Solflare for direct non-custodial ownership.
              </p>
              <button type="button" onclick="openExportKeyModal()" class="w-full py-2.5 rounded-xl bg-rose-500/20 hover:bg-rose-500/30 border border-rose-500/40 text-xs font-bold text-rose-300 transition">
                Reveal Private Key (Requires 2FA)
              </button>
            </div>

          </div>

        </div>

      </section>

      <!-- ========================================== -->
      <!-- TAB 4: ADMIN OPS (IF ADMIN)               -->
      <!-- ========================================== -->
      <?php if ($isAdmin): ?>
      <section id="tab-content-admin" class="dashboard-tab-content hidden space-y-6">

        <!-- Admin Header & Power Tools -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div class="flex items-center gap-2">
              <h2 class="text-xl font-bold text-white tracking-tight">Platform Hot Wallet & Escrow Ledger</h2>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-300 border border-amber-500/30">ADMINISTRATOR</span>
            </div>
            <p class="text-xs text-slate-400 mt-0.5">Platform-wide pending transfers, real-time RPC broadcaster, and administrative overrides</p>
          </div>

          <div class="flex items-center gap-2 flex-wrap">
            <button type="button"
                    id="btnAdminTriggerCron"
                    onclick="adminTriggerCron()"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 border border-emerald-400/30 shadow-lg shadow-emerald-600/20 transition flex items-center gap-1.5 cursor-pointer active:scale-95">
              <span id="adminCronSpinner" class="hidden spinner-sm"></span>
              <svg id="adminCronIcon" class="w-3.5 h-3.5 text-emerald-200" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
              <span>Run Broadcaster & Sync</span>
            </button>

            <button type="button"
                    onclick="loadAdminData(true)"
                    class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700/80 border border-white/10 transition flex items-center gap-1.5 cursor-pointer">
              <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
              <span>Refresh</span>
            </button>
          </div>
        </div>

        <!-- Hot Wallet & Live Platform Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

          <!-- Metric 1: Platform Hot Wallet -->
          <div class="glass-panel rounded-2xl p-4 border border-white/10 relative overflow-hidden">
            <div class="text-xs text-slate-400 font-semibold uppercase flex items-center justify-between">
              <span class="flex items-center gap-1.5">
                <img src="/assets/img/sol.png" onerror="this.src='/assets/img/sol.png'" alt="SOL" class="w-3.5 h-3.5 rounded-full inline">
                Hot Wallet SOL
              </span>
              <span class="inline-flex items-center gap-1.5 text-[11px] text-emerald-400 font-medium font-sans" title="Connected to Solana Mainnet"><span class="w-2 h-2 rounded-full bg-emerald-400"></span><span>Connected to Solana Mainnet</span></span>
            </div>
            <div class="text-2xl font-black text-white font-mono mt-1" id="adminHotWalletSol">
              0.0000 <span class="text-xs text-slate-400 font-normal">SOL</span>
            </div>
            <div class="text-[11px] text-slate-400 mt-1.5 flex items-center justify-between">
              <span id="adminHotWalletAddrShort" class="font-mono text-purple-300 truncate max-w-[130px]">Loading...</span>
              <a id="adminHotWalletExplorerLink" href="https://explorer.solana.com/" target="_blank" rel="noopener" class="text-purple-400 hover:text-purple-300 hover:underline">Explorer ↗</a>
            </div>
          </div>

          <!-- Metric 2: Active System Escrow -->
          <div class="glass-panel rounded-2xl p-4 border border-amber-500/20 bg-gradient-to-br from-amber-500/5 via-transparent to-transparent">
            <div class="text-xs text-amber-300 font-semibold uppercase flex items-center justify-between">
              <span>Platform Escrow Locked</span>
              <span id="adminActiveHoldsBadge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">0 Holds</span>
            </div>
            <div class="text-2xl font-black text-white font-mono mt-1" id="adminActiveEscrow">
              0.0000 SOL
            </div>
            <div class="text-[11px] text-slate-400 mt-1.5" id="adminActiveEscrowSubtext">
              Across all users in undo window
            </div>
          </div>

          <!-- Metric 3: Platform Fees (12% Accumulated) -->
          <div class="glass-panel rounded-2xl p-4 border border-purple-500/20 bg-gradient-to-br from-purple-500/5 via-transparent to-transparent">
            <div class="text-xs text-purple-300 font-semibold uppercase flex items-center justify-between">
              <span>Platform Fee Revenue</span>
              <span class="text-[10px] text-slate-400">12% Fee</span>
            </div>
            <div class="text-2xl font-black text-purple-300 font-mono mt-1" id="adminFeeRevenue">
              0.0000 SOL
            </div>
            <div class="text-[11px] text-slate-400 mt-1.5" id="adminCompletedCount">
              From completed transfers
            </div>
          </div>

          <!-- Metric 4: Platform Users & Timer Status -->
          <div class="glass-panel rounded-2xl p-4 border border-white/10">
            <div class="text-xs text-slate-400 font-semibold uppercase flex items-center justify-between">
              <span>System & Timer Engine</span>
              <span class="inline-flex items-center gap-1 text-[10px] text-emerald-400 font-medium">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Live
              </span>
            </div>
            <div class="text-xl font-bold text-white tracking-tight mt-1" id="adminTotalUsers">
              0 <span class="text-xs text-slate-400 font-normal">Registered Users</span>
            </div>
            <div class="text-[11px] text-emerald-300/90 mt-1.5 font-mono truncate" title="Supabase pg_cron + Opportunistic Engine">
              Supabase pg_cron &bull; 1m timer
            </div>
          </div>

        </div>

        <!-- Dev & Platform Fee Vault Control Center -->
        <div class="glass-panel rounded-3xl border border-purple-500/30 p-6 bg-gradient-to-br from-purple-900/20 via-slate-900/60 to-black/80 shadow-2xl relative overflow-hidden">
          

          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-5 border-b border-white/10">
            <div class="flex items-center gap-3.5">
              <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-purple-600/30 to-indigo-600/30 border border-purple-500/40 flex items-center justify-center text-purple-300 shadow-lg shadow-purple-500/20">
                <svg class="w-6 h-6 text-purple-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/></svg>
              </div>
              <div>
                <div class="flex items-center gap-2">
                  <h3 class="text-lg font-black text-white tracking-tight font-display">Dev & Platform Fee Vault</h3>
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/20 text-purple-300 border border-purple-500/40">12% Fee Vault</span>
                </div>
                <p class="text-xs text-slate-400 mt-0.5">
                  Autonomous protocol fee collection vault &bull; Direct on-chain custody with private key export
                </p>
              </div>
            </div>

            <!-- Action Buttons: Export Keys & Transfer Out -->
            <div class="flex items-center gap-2.5">
              <button type="button"
                      onclick="openExportDevKeyModal()"
                      class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-purple-500/30 hover:border-purple-500/60 text-xs font-semibold text-purple-300 hover:text-white transition shadow-sm flex items-center gap-1.5 cursor-pointer">
                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                Export Private Key
              </button>
              <button type="button"
                      onclick="openTransferDevFeesModal()"
                      class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-xs font-semibold text-white shadow-lg shadow-purple-600/30 transition flex items-center gap-1.5 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                Transfer Fees Out
              </button>
            </div>
          </div>

          <!-- Dev Wallet Address & Balances Grid -->
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-5">
            <!-- Dev Address Pill -->
            <div class="glass-panel rounded-2xl p-4 border border-white/10 bg-slate-900/50">
              <span class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Dev Wallet Address</span>
              <div class="flex items-center justify-between gap-2 mt-1">
                <span id="devWalletAddressShort" class="font-mono text-xs font-bold text-purple-300 truncate">Loading...</span>
                <button type="button" onclick="copyDevWalletAddress()" class="p-1 text-slate-400 hover:text-white transition cursor-pointer" title="Copy Address">
                  <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-white transition" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                </button>
              </div>
              <a id="devWalletExplorerLink" href="#" target="_blank" rel="noopener" class="inline-block mt-2 text-[11px] text-purple-400 hover:text-purple-300 hover:underline">
                View on Solscan ↗
              </a>
            </div>

            <!-- SOL Fee Balance -->
            <div class="glass-panel rounded-2xl p-4 border border-white/10 bg-slate-900/50">
              <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">SOL Fee Balance</span>
                <img src="/assets/img/sol.png" alt="SOL" class="w-4 h-4 rounded-full">
              </div>
              <div class="text-xl font-black text-white font-mono mt-1" id="devWalletBalSol">0.0000 SOL</div>
              <span class="block text-[10px] text-slate-500 mt-1" id="devWalletOnChainSol">On-chain: 0.0000 SOL</span>
            </div>

            <!-- USDC Fee Balance -->
            <div class="glass-panel rounded-2xl p-4 border border-white/10 bg-slate-900/50">
              <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">USDC Fee Balance</span>
                <img src="/assets/img/usdc.png" alt="USDC" class="w-4 h-4 rounded-full">
              </div>
              <div class="text-xl font-black text-white font-mono mt-1" id="devWalletBalUsdc">$0.00 USDC</div>
              <span class="block text-[10px] text-slate-500 mt-1">Available to sweep</span>
            </div>

            <!-- USDT Fee Balance -->
            <div class="glass-panel rounded-2xl p-4 border border-white/10 bg-slate-900/50">
              <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">USDT Fee Balance</span>
                <img src="/assets/img/usdt.png" alt="USDT" class="w-4 h-4 rounded-full">
              </div>
              <div class="text-xl font-black text-white font-mono mt-1" id="devWalletBalUsdt">$0.00 USDT</div>
              <span class="block text-[10px] text-slate-500 mt-1">Available to sweep</span>
            </div>
          </div>

          <!-- Recent Fee Collections Ledger Sub-table -->
          <div class="mt-5 pt-4 border-t border-white/5">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-bold text-slate-300">Fee Ledger & Sweep History</span>
              <button type="button" onclick="loadDevWalletData()" class="text-[11px] text-purple-400 hover:text-purple-300 font-semibold cursor-pointer">
                Refresh Vault
              </button>
            </div>
            <div class="overflow-x-auto max-h-48 overflow-y-auto">
              <table class="w-full text-left text-xs border-collapse">
                <thead>
                  <tr class="text-slate-500 text-[10px] uppercase font-bold border-b border-white/5">
                    <th class="py-1.5 px-2">Time</th>
                    <th class="py-1.5 px-2">Type</th>
                    <th class="py-1.5 px-2">Amount</th>
                    <th class="py-1.5 px-2">Token</th>
                    <th class="py-1.5 px-2">Details / Tx Signature</th>
                  </tr>
                </thead>
                <tbody id="devWalletHistoryBody" class="divide-y divide-white/5 text-[11px] font-mono text-slate-300">
                  <tr>
                    <td colspan="5" class="py-4 text-center text-slate-500 font-sans">No fee collections or withdrawals yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Admin Table of Platform Escrow -->
        <div class="glass-panel rounded-2xl border border-white/10 p-5 space-y-4">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h3 class="text-base font-bold text-white tracking-tight">Active Escrow Holds (Platform-Wide)</h3>
              <p class="text-xs text-slate-400">Every timelocked transfer currently in flight across all registered users</p>
            </div>
            <span class="text-xs text-slate-400 font-mono" id="adminTableCountLabel">Showing 0 transfers</span>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
              <thead>
                <tr class="border-b border-white/10 text-slate-400 font-bold uppercase text-[10px]">
                  <th class="py-2.5 px-3">Transfer ID</th>
                  <th class="py-2.5 px-3">User</th>
                  <th class="py-2.5 px-3">Principal Amount</th>
                  <th class="py-2.5 px-3">Platform Fee (12%)</th>
                  <th class="py-2.5 px-3">Destination</th>
                  <th class="py-2.5 px-3">Expires At / Timer</th>
                  <th class="py-2.5 px-3 text-right">Admin Override Actions</th>
                </tr>
              </thead>
              <tbody id="adminTransfersTableBody" class="divide-y divide-white/5">
                <tr>
                  <td colspan="7" class="py-8 text-center text-slate-500">
                    <span class="spinner-sm inline-block mr-2"></span> Loading platform escrow transfers...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Empty state for admin table -->
          <div id="adminTransfersEmpty" class="hidden py-8 text-center">
            <div class="w-10 h-10 mx-auto rounded-xl bg-slate-800/80 flex items-center justify-center text-slate-400 mb-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <p class="text-xs font-bold text-slate-300">No Active Escrow Holds System-Wide</p>
            <p class="text-[11px] text-slate-500 mt-0.5">All user transfers have settled or been refunded.</p>
          </div>
        </div>

        <!-- Registered Platform Users Sub-Section -->
        <div class="glass-panel rounded-2xl border border-white/10 p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-white tracking-tight">Registered Platform Users & Deposit Wallets</h3>
              <p class="text-xs text-slate-400">Users, custodial Solana deposit keys, and ledger balances on Supabase</p>
            </div>
            <button type="button"
                    onclick="loadAdminUsers()"
                    class="text-xs text-purple-400 hover:text-purple-300 font-semibold cursor-pointer">
              Refresh Directory
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
              <thead>
                <tr class="border-b border-white/10 text-slate-400 font-bold uppercase text-[10px]">
                  <th class="py-2.5 px-3">User ID</th>
                  <th class="py-2.5 px-3">Username & Email</th>
                  <th class="py-2.5 px-3">Custodial Deposit Address</th>
                  <th class="py-2.5 px-3">SOL Balance</th>
                  <th class="py-2.5 px-3">USDC Balance</th>
                  <th class="py-2.5 px-3">USDT Balance</th>
                  <th class="py-2.5 px-3">Security & Role</th>
                </tr>
              </thead>
              <tbody id="adminUsersTableBody" class="divide-y divide-white/5">
                <tr>
                  <td colspan="7" class="py-6 text-center text-slate-500">
                    Click "Refresh Directory" or view tab to inspect user balances.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </section>
      <?php endif; ?>

    </main>

    <!-- SolRecall Brand Footer -->
    <footer class="border-t border-white/5 mt-12 py-6 text-xs text-slate-500">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-center sm:text-left">
        <div class="flex items-center gap-2.5">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-5 h-5 rounded-lg border border-purple-500/20 object-cover">
          <span class="font-semibold text-slate-300 font-display">SolRecall</span>
          <span class="text-slate-600">&bull;</span>
          <span>Reversible Time-Locked Escrow on Solana</span>
        </div>
        <div class="text-[11px] text-slate-500">
          &copy; <?= date('Y') ?> SolRecall. All rights reserved.
        </div>
      </div>
    </footer>

  </div>

  <!-- Mobile Bottom Navigation Bar (fixed on mobile with ample clearance) -->
  <nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-[#08080a]/95 backdrop-blur-lg border-t border-[#222228] px-4 py-2.5 flex items-center justify-around shadow-2xl font-nav">
    <button type="button" data-mobile-tab="overview" onclick="switchTab('overview')" class="touch-target flex flex-col items-center justify-center gap-1 text-purple-400 py-1 px-3 rounded-xl min-h-[48px]">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
      <span class="text-[10px] font-semibold">Overview</span>
    </button>
    <button type="button" data-mobile-tab="history" onclick="switchTab('history')" class="flex flex-col items-center gap-1 text-slate-400 py-1 px-3 rounded-xl">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <span class="text-[10px] font-semibold">History</span>
    </button>
    <button type="button" data-mobile-tab="settings" onclick="switchTab('settings')" class="touch-target flex flex-col items-center justify-center gap-1 text-slate-400 py-1 px-3 rounded-xl min-h-[48px]">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
      <span class="text-[10px] font-semibold">Settings</span>
    </button>
    <?php if ($isAdmin): ?>
    <button type="button" data-mobile-tab="admin" onclick="switchTab('admin')" class="touch-target flex flex-col items-center justify-center gap-1 text-slate-400 py-1 px-3 rounded-xl min-h-[48px]">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
      <span class="text-[10px] font-semibold">Admin</span>
    </button>
    <?php endif; ?>
  </nav>

  <!-- ========================================== -->
  <!-- MODALS                                     -->
  <!-- ========================================== -->

  <!-- 1. Cancel Transfer Confirmation Modal -->
  <div id="cancelModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-md w-full p-6 border border-white/10 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto relative">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-2xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div>
          <h3 class="text-base font-bold text-white">Cancel Pending SolRecall Transfer?</h3>
          <p class="text-xs text-slate-400">Funds will immediately return to your balance</p>
        </div>
      </div>

      <div id="cancelModalDetails"></div>

      <p class="text-xs text-slate-300 leading-relaxed">
        Because this transfer is still in the SolRecall undo escrow window, cancelling it stops the broadcast. <strong>100% of the funds will instantly credit back to your available balance.</strong>
      </p>

      <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/5">
        <button type="button" onclick="closeCancelModal()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition">
          Keep Transfer
        </button>
        <button type="button" id="confirmCancelBtn" onclick="confirmCancelTransfer()" class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-500 shadow-lg shadow-rose-600/30 transition">
          Yes, Cancel Transfer
        </button>
      </div>
    </div>
  </div>

  <!-- 2. Deposit Address & QR Modal -->
  <div id="depositModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-sm w-full p-6 border border-white/10 shadow-2xl text-center space-y-4 max-h-[90vh] overflow-y-auto relative">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-white">SolRecall Custodial Deposit Address</h3>
        <button type="button" onclick="closeDepositModal()" class="touch-target w-11 h-11 min-w-[44px] min-h-[44px] rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition flex items-center justify-center cursor-pointer" aria-label="Close modal"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>

      <div class="bg-white p-3 rounded-2xl mx-auto inline-block shadow-md min-w-[184px] min-h-[184px] flex items-center justify-center">
        <div id="depositQrCanvas" class="flex items-center justify-center"></div>
        <img id="depositQrCodeImg"
             src="<?= !empty($depositAddress) ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($depositAddress) : '' ?>"
             alt="Deposit QR Code"
             class="<?= empty($depositAddress) ? 'hidden ' : '' ?>w-40 h-40 object-contain mx-auto">
      </div>

      <div class="text-left bg-slate-900 p-3 rounded-xl border border-white/5 space-y-1">
        <div class="text-[10px] uppercase font-bold text-slate-400">Solana Deposit Address</div>
        <div id="userDepositAddressFull" class="text-xs font-mono text-purple-300 break-all select-all leading-relaxed font-semibold">
          <?= htmlspecialchars($depositAddress) ?>
        </div>
      </div>

      <button type="button"
              id="copyDepositAddressBtn"
              onclick="copyDepositAddress()"
              class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-xs font-bold text-white transition flex items-center justify-center gap-1.5 shadow-lg shadow-purple-600/20 active:scale-95">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
        <span id="copyDepositBtnText">Copy Deposit Address</span>
      </button>

      <p class="text-[11px] text-slate-400 leading-tight">
        Send native <strong>SOL</strong>, <strong>USDC</strong>, or <strong>USDT</strong> to this address. Incoming deposits automatically credit your available balance.
      </p>
    </div>
  </div>

  <!-- 3. 2FA Setup Modal -->
  <div id="twoFaModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-sm w-full p-6 border border-white/10 shadow-2xl space-y-4 text-center max-h-[90vh] overflow-y-auto relative">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-white">SolRecall 2FA Security Setup</h3>
        <button type="button" onclick="close2FaModal()" class="touch-target w-11 h-11 min-w-[44px] min-h-[44px] rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition flex items-center justify-center cursor-pointer" aria-label="Close modal"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>

      <div class="bg-white p-3 rounded-2xl mx-auto inline-block">
        <img id="twoFaQrImage" src="" alt="2FA QR Code" class="w-36 h-36">
      </div>

      <div class="text-xs text-slate-400">
        Scan QR code or enter manual key:
        <div id="twoFaSecretText" class="font-mono text-purple-300 font-bold text-xs mt-1 select-all">JBSWY3DPEHPK3PXP</div>
      </div>

      <div>
        <label for="twoFaVerifyCode" class="block text-xs font-semibold text-slate-300 mb-1">Enter 6-Digit Code</label>
        <input type="text" id="twoFaVerifyCode" maxlength="6" placeholder="000000" class="w-full text-center tracking-widest text-lg font-mono py-2 rounded-xl bg-slate-900 border border-white/10 text-white focus:outline-none focus:border-purple-500">
      </div>

      <div class="flex gap-2">
        <button type="button" onclick="close2FaModal()" class="flex-1 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-slate-300">Cancel</button>
        <button type="button" id="confirm2FaBtn" onclick="verifyAndEnable2Fa()" class="flex-1 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-xs font-bold text-white">Verify & Enable</button>
      </div>
    </div>
  </div>

  <!-- 3b. Delete / Reset 2FA Key Modal -->
  <div id="delete2FaModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-sm w-full p-6 border border-rose-500/30 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto relative">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2.5 text-rose-400">
          <div class="w-8 h-8 rounded-xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
          </div>
          <h3 class="text-sm font-bold text-white">Delete / Reset 2FA Key</h3>
        </div>
        <button type="button" onclick="closeDelete2FaModal()" class="touch-target w-11 h-11 min-w-[44px] min-h-[44px] rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition flex items-center justify-center cursor-pointer" aria-label="Close modal"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>

      <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-xs text-rose-300 leading-relaxed text-left">
        <strong>Security Warning:</strong> Removing your Two-Factor Authentication key disables 2FA on your account. Authorize removal using your account password and/or 6-digit TOTP code.
      </div>

      <div class="space-y-3 text-left">
        <div>
          <label for="delete2FaPassword" class="block text-xs font-semibold text-slate-300 mb-1">Account Password</label>
          <input type="password" id="delete2FaPassword" placeholder="Enter current password" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-white/10 text-white text-xs placeholder-slate-500 focus:outline-none focus:border-rose-500 transition">
        </div>
        <div>
          <label for="delete2FaTotp" class="block text-xs font-semibold text-slate-300 mb-1">6-Digit 2FA Authenticator Code</label>
          <input type="text" id="delete2FaTotp" maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="000000" class="w-full text-center tracking-widest font-mono text-base py-2 rounded-xl bg-slate-900 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-rose-500 transition">
        </div>
      </div>

      <div class="flex gap-2 pt-2">
        <button type="button" onclick="closeDelete2FaModal()" class="flex-1 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition cursor-pointer">Cancel</button>
        <button type="button" id="confirmDelete2FaBtn" onclick="submitDelete2Fa()" class="flex-1 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-bold text-white shadow-lg shadow-rose-600/20 transition flex items-center justify-center gap-1.5 cursor-pointer">
          <span id="delete2FaSpinner" class="hidden spinner-sm"></span>
          <span>Delete 2FA Key</span>
        </button>
      </div>
    </div>
  </div>

  <!-- 4. Export Private Key Modal -->
  <div id="exportKeyModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-md w-full p-6 border border-rose-500/40 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto relative">

      <!-- Step 1: Verification -->
      <div id="exportKeyStep1" class="space-y-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-rose-500/20 text-rose-400 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div>
              <h3 class="text-base font-bold text-white">SolRecall Security Check: Export Key</h3>
              <p class="text-xs text-rose-300 font-medium">Verify your identity to decrypt your custodial wallet</p>
            </div>
          </div>
          <button type="button" onclick="closeExportKeyModal()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/5 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <div class="bg-rose-500/10 border border-rose-500/20 p-3 rounded-xl text-xs text-rose-300 leading-relaxed">
          <strong>CRITICAL WARNING:</strong> Anyone possessing this raw private key has complete, irreversible control over your Solana deposit funds. Never share it with support or enter it on unverified websites.
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Account Password</label>
            <input type="password" id="exportKeyPassword" placeholder="••••••••••••" onkeydown="if(event.key === 'Enter') verifyAndRevealKey()" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">2FA Authenticator Code</label>
            <input type="text" id="exportKeyTotp" maxlength="6" placeholder="6-digit TOTP code" onkeydown="if(event.key === 'Enter') verifyAndRevealKey()" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeExportKeyModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition">Cancel</button>
          <button type="button" id="submitExportKeyVerifyBtn" onclick="verifyAndRevealKey()" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-bold text-white shadow-lg shadow-rose-600/30 transition">Verify & Reveal Key</button>
        </div>
      </div>

      <!-- Step 2: Revealed Key View with Box Blur & Reveal/Copy Toggle -->
      <div id="exportKeyStep2" class="space-y-4 hidden">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-purple-500/20 text-purple-400 border border-purple-500/30 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
            </div>
            <div>
              <h3 class="text-base font-bold text-white">SolRecall Custodial Solana Keypair</h3>
              <p class="text-xs text-slate-400">Import this Base58 private key into Phantom or Solflare</p>
            </div>
          </div>
          <button type="button" onclick="closeExportKeyModal()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/5 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <!-- Deposit Address info row -->
        <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-slate-900/80 border border-white/5 text-[11px]">
          <span class="text-slate-400 font-mono">Deposit Address:</span>
          <span id="exportKeyDepositAddress" class="text-slate-200 font-mono truncate max-w-[210px]"></span>
        </div>

        <!-- Box Blur Menu with the keys -->
        <div class="p-4 bg-slate-950/90 rounded-2xl border border-white/10 text-left relative shadow-2xl backdrop-blur-xl space-y-2">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5 text-[10px] uppercase font-bold text-rose-400 tracking-wider">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              <span>Raw Base58 Private Key</span>
            </div>
            <span id="keyStatusBadge" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/20 text-rose-300 border border-rose-500/30 flex items-center gap-1">
              <span class="w-1.5 h-1.5 rounded-full bg-rose-400 animate-pulse"></span>
              <span>Encrypted in View</span>
            </span>
          </div>

          <!-- Blurred Key Display Box -->
          <div class="relative overflow-hidden rounded-xl border border-white/10 bg-black/80 my-2">
            <div id="privateKeyBlurBox" class="key-blurred filter blur-md select-none pointer-events-none p-3.5 font-mono text-xs text-emerald-400 break-all leading-relaxed transition-all duration-300 min-h-[76px] flex items-center">
              <span id="rawPrivateKeyDisplay">Loading...</span>
            </div>
            <div id="privateKeyOverlay" class="absolute inset-0 flex items-center justify-center bg-black/40 backdrop-blur-[2px] transition-all duration-300 pointer-events-none">
              <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-900/95 border border-white/10 text-[11px] font-medium text-slate-300 shadow-xl">
                <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <span>Click Reveal to unblur key</span>
              </div>
            </div>
          </div>

          <p class="text-[11px] text-slate-400 flex items-start gap-1.5 pt-1">
            <svg class="w-3.5 h-3.5 text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Anyone with this key has irreversible access to your Solana funds. Never disclose it.</span>
          </p>
        </div>

        <!-- Action Button: Reveal -> changes to Copy -->
        <button type="button"
                id="revealCopyKeyBtn"
                onclick="toggleRevealOrCopyKey()"
                class="w-full py-3 px-4 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:to-indigo-500 shadow-lg shadow-purple-600/25 flex items-center justify-center gap-2 transition-all duration-200 cursor-pointer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          <span>Reveal Private Key</span>
        </button>

        <!-- Close Button -->
        <button type="button"
                id="closeExportKeyBtn"
                onclick="closeExportKeyModal()"
                class="w-full py-2.5 rounded-xl bg-slate-800/90 hover:bg-slate-700/90 text-xs font-semibold text-slate-300 hover:text-white border border-white/10 transition flex items-center justify-center gap-2 cursor-pointer">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          <span>Close</span>
        </button>
      </div>

    </div>
  </div>

  <!-- Dev Wallet Export Key Modal -->
  <div id="exportDevKeyModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-md w-full p-6 border border-purple-500/40 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto relative">
      <!-- Step 1: Verification -->
      <div id="exportDevKeyStep1" class="space-y-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-purple-500/20 text-purple-400 border border-purple-500/30 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
            </div>
            <div>
              <h3 class="text-base font-bold text-white">Export Dev Wallet Key</h3>
              <p class="text-xs text-purple-300 font-medium">Verify admin credentials to decrypt the protocol vault</p>
            </div>
          </div>
          <button type="button" onclick="closeExportDevKeyModal()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/5 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <div class="bg-purple-500/10 border border-purple-500/20 p-3 rounded-xl text-xs text-purple-300 leading-relaxed">
          <strong>SECURITY WARNING:</strong> This key holds complete authority over all collected 12% platform fees. Never share it or expose it in public repositories.
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Admin Password</label>
            <input type="password" id="exportDevKeyPassword" placeholder="••••••••••••" onkeydown="if(event.key === 'Enter') submitExportDevKey()" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">2FA Authenticator Code (if enabled)</label>
            <input type="text" id="exportDevKeyTotp" maxlength="6" placeholder="6-digit TOTP code" onkeydown="if(event.key === 'Enter') submitExportDevKey()" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeExportDevKeyModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition">Cancel</button>
          <button type="button" id="submitExportDevKeyBtn" onclick="submitExportDevKey()" class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-xs font-bold text-white shadow-lg shadow-purple-600/30 transition">Verify & Reveal Key</button>
        </div>
      </div>

      <!-- Step 2: Revealed Key View -->
      <div id="exportDevKeyStep2" class="space-y-4 hidden">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <div>
              <h3 class="text-base font-bold text-white">Dev Wallet Private Keys</h3>
              <p class="text-xs text-slate-400">Ready for Phantom, Solflare, or Solana CLI</p>
            </div>
          </div>
          <button type="button" onclick="closeExportDevKeyModal()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/5 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <!-- Dev Address row -->
        <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-slate-900/80 border border-white/5 text-[11px]">
          <span class="text-slate-400 font-mono">Dev Address:</span>
          <span id="exportDevKeyAddress" class="text-purple-300 font-mono truncate max-w-[210px]"></span>
        </div>

        <!-- Base58 Key Box -->
        <div class="p-3.5 bg-slate-950/90 rounded-2xl border border-white/10 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-[10px] font-bold text-purple-300 uppercase tracking-wider">Base58 Private Key (Phantom / Solflare)</span>
            <button type="button" onclick="copyDevKeyBase58()" class="text-[11px] text-purple-400 hover:text-purple-300 font-semibold cursor-pointer">Copy Key</button>
          </div>
          <div id="exportDevKeyBase58" class="font-mono text-xs text-slate-200 bg-slate-900 p-2.5 rounded-xl break-all select-all border border-white/5"></div>
          <p class="text-[10px] text-slate-400">Import in Phantom: Settings &rarr; Manage Accounts &rarr; Add/Connect Wallet &rarr; Import Private Key</p>
        </div>

        <!-- JSON Array Box (Solana CLI) -->
        <div class="p-3.5 bg-slate-950/90 rounded-2xl border border-white/10 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-[10px] font-bold text-purple-300 uppercase tracking-wider">Keypair JSON Array (Solana CLI / id.json)</span>
            <button type="button" onclick="copyDevKeyJson()" class="text-[11px] text-purple-400 hover:text-purple-300 font-semibold cursor-pointer">Copy JSON</button>
          </div>
          <div id="exportDevKeyJson" class="font-mono text-[11px] text-slate-300 bg-slate-900 p-2.5 rounded-xl max-h-24 overflow-y-auto break-all select-all border border-white/5"></div>
          <p class="text-[10px] text-slate-400">Save to <code class="text-purple-300">~/.config/solana/id.json</code> to use with the <code class="text-purple-300">solana</code> CLI</p>
        </div>

        <button type="button" onclick="closeExportDevKeyModal()" class="w-full py-2.5 rounded-xl bg-slate-800 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition">
          Done
        </button>
      </div>
    </div>
  </div>

  <!-- Dev Wallet Transfer Out / Sweep Modal -->
  <div id="transferDevFeesModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-md w-full p-6 border border-purple-500/40 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto relative">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-purple-500/20 text-purple-400 border border-purple-500/30 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
          </div>
          <div>
            <h3 class="text-base font-bold text-white">Transfer Fees Out</h3>
            <p class="text-xs text-slate-400">Sweep collected protocol fees to any Solana address</p>
          </div>
        </div>
        <button type="button" onclick="closeTransferDevFeesModal()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/5 transition">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="space-y-3">
        <!-- Token Selection -->
        <div>
          <label class="block text-xs font-semibold text-slate-400 mb-1">Select Token</label>
          <select id="transferDevToken" onchange="onDevTokenChange()" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-medium focus:outline-none focus:border-purple-500">
            <option value="SOL">SOL</option>
            <option value="USDC">USDC</option>
            <option value="USDT">USDT</option>
          </select>
        </div>

        <!-- Available Balance Display -->
        <div class="flex items-center justify-between text-xs font-mono text-slate-400">
          <span>Dev Vault Available:</span>
          <span id="transferDevAvailableHint" class="text-purple-300 font-bold">0.0000 SOL</span>
        </div>

        <!-- Amount & Max button -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-xs font-semibold text-slate-400">Transfer Amount</label>
            <button type="button" onclick="setDevTransferMax()" class="text-[11px] font-bold text-purple-400 hover:text-purple-300 cursor-pointer">MAX</button>
          </div>
          <input type="number" id="transferDevAmount" step="any" min="0.0001" placeholder="0.00" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-purple-500">
        </div>

        <!-- Destination Address -->
        <div>
          <label class="block text-xs font-semibold text-slate-400 mb-1">Destination Solana Address</label>
          <input type="text" id="transferDevDestination" placeholder="Recipient Solana wallet address..." class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white font-mono text-xs focus:outline-none focus:border-purple-500">
        </div>

        <!-- Security Verification -->
        <div class="pt-2 border-t border-white/5 space-y-2">
          <label class="block text-xs font-semibold text-slate-300">Security Verification</label>
          <input type="password" id="transferDevPassword" placeholder="Admin Account Password" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs">
          <input type="text" id="transferDevTotp" maxlength="6" placeholder="2FA TOTP Code (if enabled)" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
        </div>

        <!-- Gas info -->
        <div class="text-[11px] text-slate-500 flex items-center justify-between">
          <span>Network Fee:</span>
          <span class="text-emerald-400 font-mono">Sponsored by Hot Wallet (0 SOL)</span>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2 border-t border-white/10">
        <button type="button" onclick="closeTransferDevFeesModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition">Cancel</button>
        <button type="button" id="btnSubmitTransferDevFees" onclick="submitTransferDevFees()" class="px-5 py-2 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-xs font-bold text-white shadow-lg shadow-purple-600/30 transition flex items-center gap-1.5 cursor-pointer">
          <span id="transferDevBtnSpinner" class="hidden spinner-sm"></span>
          <span id="transferDevBtnText">Confirm & Transfer Out</span>
        </button>
      </div>
    </div>
  </div>

  <!-- 5. Phantom-Style Transfer Loading & Success Receipt Modal -->
  <div id="transferReceiptModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel modal-panel rounded-3xl max-w-md w-full p-6 sm:p-8 border border-white/10 shadow-[0_0_60px_rgba(0,0,0,0.85)] text-center relative overflow-hidden backdrop-blur-2xl transition-all duration-300 max-h-[90vh] overflow-y-auto">

      <!-- Ambient Glows -->
      
      

      <!-- Close Button (Visible on Success) -->
      <button type="button"
              id="transferReceiptCloseBtn"
              onclick="closeTransferReceiptModal()"
              class="hidden absolute top-4 right-4 text-slate-400 hover:text-white p-2 rounded-xl hover:bg-white/5 transition z-10 cursor-pointer"
              title="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>

      <!-- VIEW A: Full-Fidelity Loading Screen -->
      <div id="transferReceiptLoadingView" class="py-6 space-y-6">
        <!-- Animated Progress Ring with Pulsing Solana Shield -->
        <div class="relative w-28 h-28 mx-auto flex items-center justify-center">
          
          
          <!-- Animated SVG Progress Ring -->
          <svg class="w-28 h-28 -rotate-90 transform" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="42" stroke="currentColor" stroke-width="6" class="text-slate-800/80" fill="transparent"/>
            <circle cx="50" cy="50" r="42" stroke="url(#loadingGradient)" stroke-width="6" stroke-linecap="round" class="loading-ring-spinner" fill="transparent" stroke-dasharray="264" stroke-dashoffset="66"/>
            <defs>
              <linearGradient id="loadingGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#9945FF" />
                <stop offset="50%" stop-color="#6366F1" />
                <stop offset="100%" stop-color="#14F195" />
              </linearGradient>
            </defs>
          </svg>

          <!-- Centered Pulsing Solana Shield Icon -->
          <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-purple-600/30 to-emerald-500/20 border border-purple-500/40 flex items-center justify-center shadow-lg shadow-purple-500/20 animate-pulse-ring">
              <svg class="w-7 h-7 text-purple-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <h3 class="text-xl font-bold text-white tracking-tight font-display">Securing into SolRecall Escrow...</h3>
          <p class="text-xs text-slate-400 max-w-xs mx-auto leading-relaxed">
            Reserving funds into revocable escrow hold on Solana.
          </p>
        </div>

        <!-- Micro status pulse steps -->
        <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-slate-900/80 border border-white/5 text-[11px] text-purple-300 font-mono">
          <span class="w-2 h-2 rounded-full bg-purple-400 shrink-0"></span>
          <span id="transferReceiptLoadingStep">Validating timelock & reserving escrow balance...</span>
        </div>
      </div>

      <!-- VIEW B: Phantom-Style Success Receipt Screen (Designed for Screenshots & Sharing) -->
      <div id="transferReceiptSuccessView" class="hidden py-2 space-y-5 text-center">
        
        <!-- Glowing green checkmark / shield badge -->
        <div class="relative w-20 h-20 mx-auto flex items-center justify-center">
          
          <div class="w-20 h-20 rounded-full bg-gradient-to-tr from-emerald-500/20 via-emerald-500/30 to-purple-500/20 border-2 border-emerald-400/60 flex items-center justify-center shadow-[0_0_30px_rgba(20,241,149,0.35)]">
            <svg class="w-10 h-10 text-emerald-400 drop-shadow-[0_0_8px_rgba(20,241,149,0.6)]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          </div>
        </div>

        <!-- Headline -->
        <div>
          <h3 class="text-xl sm:text-2xl font-black text-white tracking-tight font-display">Transfer Escrowed & Protected</h3>
          <p class="text-xs text-slate-400 mt-1">Secured inside SolRecall Undo Window</p>
        </div>

        <!-- Large Bold Amount with Official Token Logo -->
        <div class="p-4 rounded-2xl bg-black/50 border border-white/10 shadow-inner">
          <div class="flex items-center justify-center gap-3">
            <img id="receiptTokenLogo"
                 src="/assets/img/sol.png"
                 onerror="this.src='/assets/img/sol.png'"
                 alt="Token"
                 class="w-10 h-10 rounded-full object-contain p-1 bg-black/60 border border-white/15 shrink-0 shadow-md">
            <span id="receiptAmount" class="font-mono text-3xl sm:text-4xl font-black text-white tracking-tight">0.1000</span>
            <span id="receiptTokenSymbol" class="text-lg font-bold text-slate-300 font-sans">SOL</span>
          </div>
          <div id="receiptFiatValue" class="text-xs text-slate-400 font-mono mt-1">≈ $18.45 USD</div>
        </div>

        <!-- Details Card -->
        <div class="bg-slate-900/90 rounded-2xl p-4 border border-white/5 space-y-3 text-left">
          
          <!-- Recipient row with 1-click copy icon -->
          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-400 font-medium">Recipient Address:</span>
            <div class="flex items-center gap-1.5">
              <span id="receiptDestinationTruncated" class="font-mono text-purple-300 font-semibold bg-slate-950 px-2.5 py-1 rounded-lg border border-white/5">
                7xKX...gAsU
              </span>
              <button type="button"
                      id="receiptCopyDestBtn"
                      onclick="copyReceiptDestination()"
                      class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer"
                      title="Copy full recipient address">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Transfer Status Badge -->
          <div class="flex items-center justify-between text-xs pt-2.5 border-t border-white/5">
            <span class="text-slate-400 font-medium">Status:</span>
            <span id="receiptStatusBadge" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30">
              <span id="receiptStatusDot" class="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0"></span>
              <span id="receiptStatusText">Pending Escrow</span>
            </span>
          </div>

          <!-- Undo Timelock Duration Badge -->
          <div class="flex items-center justify-between text-xs pt-2.5 border-t border-white/5">
            <span class="text-slate-400 font-medium">Timelock Window:</span>
            <span id="receiptWindowBadge" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30">
              <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span id="receiptWindowText">10m Undo Window Active</span>
            </span>
          </div>

          <!-- Platform Fee (12%) -->
          <div class="flex items-center justify-between text-xs pt-2.5 border-t border-white/5">
            <span class="text-slate-400 font-medium">Platform Fee (12%):</span>
            <span id="receiptFeeValue" class="font-mono text-amber-300 font-semibold">+0.0000 SOL</span>
          </div>

          <!-- Network Gas -->
          <div class="flex items-center justify-between text-xs pt-2.5 border-t border-white/5">
            <span class="text-slate-400 font-medium">Network Gas:</span>
            <span id="receiptGasValue" class="font-mono text-slate-300 font-semibold">0.000005 SOL</span>
          </div>

          <!-- Solana Mainnet Explorer Link -->
          <div class="flex items-center justify-between text-xs pt-2.5 border-t border-white/5">
            <span class="text-slate-400 font-medium">Solana Explorer:</span>
            <a id="receiptExplorerLink" href="https://solscan.io/" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-mono text-purple-400 hover:text-purple-300 hover:underline">
              <span id="receiptExplorerLinkText">View on Solscan</span>
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
          </div>

          <!-- Note/Memo row (if present) -->
          <div id="receiptNoteRow" class="hidden flex items-center justify-between text-xs pt-2.5 border-t border-white/5">
            <span class="text-slate-400 font-medium">Memo:</span>
            <span id="receiptNoteText" class="text-slate-300 truncate max-w-[200px]"></span>
          </div>

        </div>

        <!-- Explainer note -->
        <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-left flex items-start gap-3">
          <div class="w-6 h-6 rounded-lg bg-amber-500/20 text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          </div>
          <p class="text-xs text-amber-200/90 leading-relaxed font-medium">
            Funds can be revoked and refunded instantly before the countdown expires.
          </p>
        </div>

        <!-- Action Buttons -->
        <div class="space-y-2.5 pt-2">
          <!-- Primary: Copy Share Text -->
          <button type="button"
                  id="receiptCopyShareBtn"
                  onclick="copyReceiptShareText()"
                  class="w-full py-3.5 px-4 rounded-xl font-bold text-white bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:to-indigo-500 border border-purple-400/30 transition shadow-lg shadow-purple-600/30 flex items-center justify-center gap-2 text-xs sm:text-sm active:scale-95 cursor-pointer">
            <svg class="w-4 h-4 text-purple-200" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            <span id="receiptCopyShareBtnText">Copy Share Text</span>
          </button>

          <!-- Secondary: View in Active Holds -->
          <button type="button"
                  onclick="viewReceiptInActiveHolds()"
                  class="w-full py-3 px-4 rounded-xl font-semibold text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700/80 border border-white/10 transition flex items-center justify-center gap-2 text-xs cursor-pointer">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <span>View in Active Holds</span>
          </button>
        </div>

      </div>

    </div>
  </div>

  
  <!-- ========================================================================= -->
  <!-- SLIDE-OVER MOBILE DRAWER (#mobileDrawer)                                  -->
  <!-- ========================================================================= -->
  <div id="mobileDrawer" class="mobile-drawer-root" role="dialog" aria-modal="true" aria-label="Mobile Navigation">
    
    <!-- Backdrop Blur Overlay -->
    <div id="mobileDrawerBackdrop" class="mobile-drawer-backdrop" onclick="typeof closeMobileDrawer === 'function' && closeMobileDrawer()"></div>

    <!-- Drawer Panel (Smooth 60fps hardware-accelerated slide-in) -->
    <aside id="mobileDrawerPanel" class="mobile-drawer-panel">

      <!-- Top: Brand Header & Close Button -->
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
                aria-label="Close Mobile Drawer">
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
              <?= strtoupper(substr($username, 0, 2)) ?>
            </div>
            <div class="overflow-hidden">
              <div class="text-sm font-bold text-white font-display truncate"><?= $username ?></div>
              <div class="text-xs text-[#94949e] font-body truncate"><?= $userEmail ?: 'Protected Account' ?></div>
            </div>
          </div>

          <div class="pt-2 border-t border-[#222228] flex items-baseline justify-between">
            <span class="text-xs text-[#94949e] font-nav">SOL Available:</span>
            <div class="text-right">
              <span id="drawerUserSolBalance" class="text-2xl font-bold text-white font-stats tracking-wider"><?= number_format($ssrSolAvail, 4, '.', '') ?> SOL</span>
              <?php if ($ssrSolEscrow > 0): ?>
                <div class="text-[11px] text-amber-400 font-mono font-medium">+<?= number_format($ssrSolEscrow, 4, '.', '') ?> In Escrow</div>
              <?php endif; ?>
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
                  data-full="<?= $depositAddress ?>"><?= !empty($depositAddress) ? (strlen($depositAddress) > 16 ? substr($depositAddress, 0, 8) . '...' . substr($depositAddress, -8) : $depositAddress) : 'No address assigned' ?></span>
            <button type="button" onclick="openDepositModal(); typeof closeMobileDrawer === 'function' && closeMobileDrawer();" class="text-xs text-white hover:text-zinc-300 px-2 py-1 rounded bg-[#141418] border border-[#222228] shrink-0 font-cta cursor-pointer">QR</button>
          </div>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-1.5 pt-1">
          <div class="px-2 pb-1 text-[10px] font-bold text-[#94949e] uppercase font-nav tracking-wider">Navigation</div>
          
          <!-- Overview / Dashboard -->
          <button type="button"
                  data-tab-target="overview"
                  onclick="switchTab('overview'); typeof closeMobileDrawer === 'function' && closeMobileDrawer();"
                  class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav text-left cursor-pointer">
            <svg class="w-4 h-4 text-white shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Overview</span>
          </button>

          <!-- Send / Escrow -->
          <button type="button"
                  onclick="switchTab('overview'); scrollToEscrowForm(); typeof closeMobileDrawer === 'function' && closeMobileDrawer();"
                  class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav text-left cursor-pointer">
            <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <span>Send / Escrow</span>
          </button>

          <!-- Transfer History -->
          <button type="button"
                  data-tab-target="history"
                  onclick="switchTab('history'); typeof closeMobileDrawer === 'function' && closeMobileDrawer();"
                  class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav text-left cursor-pointer">
            <svg class="w-4 h-4 text-[#94949e] shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>Transfer History</span>
          </button>

          <!-- Settings & 2FA -->
          <button type="button"
                  data-tab-target="settings"
                  onclick="switchTab('settings'); typeof closeMobileDrawer === 'function' && closeMobileDrawer();"
                  class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-white hover:bg-[#141418] border border-transparent hover:border-[#222228] transition font-nav text-left cursor-pointer">
            <svg class="w-4 h-4 text-[#94949e] shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span>Settings & 2FA</span>
          </button>

          <?php if ($isAdmin): ?>
          <!-- Admin Ops -->
          <button type="button"
                  data-tab-target="admin"
                  onclick="switchTab('admin'); typeof closeMobileDrawer === 'function' && closeMobileDrawer();"
                  class="drawer-nav-item w-full flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-amber-400 hover:bg-amber-400/10 border border-transparent hover:border-amber-400/20 transition font-nav text-left cursor-pointer">
            <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
            <span>Admin Operations</span>
          </button>
          <?php endif; ?>
        </nav>

      </div>

      <!-- Bottom: Disconnect / Logout Button -->
      <div class="p-5 border-t border-[#222228] space-y-3 bg-[#08080a]">
        <a href="/api/auth/logout"
           id="drawerLogoutBtn"
           class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl text-xs font-bold text-rose-400 bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/30 transition font-cta cursor-pointer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Disconnect & Sign Out</span>
        </a>
        <div class="text-center text-[10px] text-[#71717a] font-nav tracking-wider">
          SOLRECALL PROTOCOL &bull; MAINNET V2.0
        </div>
      </div>

    </aside>
  </div>

  <!-- Toast Container -->
  <div id="toast-container"></div>

    <!-- Mobile Menu & Deposit Modal Inline Helpers -->
  <script>
    function toggleMobileMenu(force) {
      if (typeof window.toggleMobileDrawer === 'function') {
        window.toggleMobileDrawer();
      }
    }
    function openMobileMenu() {
      if (typeof window.openMobileDrawer === 'function') {
        window.openMobileDrawer();
      }
    }
    function closeMobileMenu() {
      if (typeof window.closeMobileDrawer === 'function') {
        window.closeMobileDrawer();
      }
    }

    function openDepositModal() {
      const modal = document.getElementById('depositModal');
      if (modal) {
        modal.classList.remove('hidden');
        if (typeof window.renderDepositModal === 'function') {
          window.renderDepositModal();
        }
      }
    }

    function closeDepositModal() {
      const modal = document.getElementById('depositModal');
      if (modal) modal.classList.add('hidden');
    }
  </script>

  <!-- Core JavaScript modules with automatic cache busting -->
  <script src="/assets/js/wallet.js?v=<?= file_exists(__DIR__ . '/assets/js/wallet.js') ? filemtime(__DIR__ . '/assets/js/wallet.js') : '1.1' ?>"></script>
  <script>
    window.__INITIAL_STATE__ = <?= json_encode([
      'sessionToken' => session_id(),
      'user' => [
        'id' => $userId,
        'username' => $_SESSION['username'] ?? 'User',
        'email' => $_SESSION['email'] ?? '',
        'solana_deposit_address' => $_SESSION['solana_deposit_address'] ?? '',
        'two_factor_enabled' => (int) ($_SESSION['two_factor_enabled'] ?? 0),
        'is_admin' => $isAdmin
      ],
      'balances' => $ssrBalances,
      'pendingTransfers' => $ssrPending,
      'historyTransfers' => $ssrHistory,
      'settings' => $ssrSettings
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="/assets/js/dashboard.js?v=<?= file_exists(__DIR__ . '/assets/js/dashboard.js') ? filemtime(__DIR__ . '/assets/js/dashboard.js') : '1.1' ?>"></script>

  <!-- PWA Service Worker Registration & Cache Sync -->
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
          reg.update();
        }).catch((err) => {
          console.warn('[SolRecall] Service Worker registration failed:', err);
        });
      });
    }
  </script>
</body>
</html>
