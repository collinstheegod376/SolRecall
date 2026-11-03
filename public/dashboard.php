<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce authenticated session
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$username = htmlspecialchars((string) ($_SESSION['username'] ?? 'User'), ENT_QUOTES, 'UTF-8');
$userEmail = htmlspecialchars((string) ($_SESSION['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$depositAddress = htmlspecialchars((string) ($_SESSION['solana_deposit_address'] ?? ''), ENT_QUOTES, 'UTF-8');
$isAdmin = (int) ($_SESSION['is_admin'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard • Undo Window for Solana Transfers</title>
  <link rel="icon" type="image/jpeg" href="/logo.jpg">
  <!-- Google Fonts: Inter (UI), JetBrains Mono (Addresses/Code), Space Grotesk (Wordmark) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'],
            mono: ['"JetBrains Mono"', '"IBM Plex Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
            display: ['"Space Grotesk"', 'Inter', 'sans-serif']
          },
          colors: {
            solana: {
              purple: '#9945FF',
              green: '#14F195',
              dark: '#07090e',
              card: '#0e1424',
              border: '#1e293b'
            }
          }
        }
      }
    }
  </script>
  <!-- Custom Styles -->
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="min-h-screen bg-[#07090e] text-slate-100 flex flex-col justify-between selection:bg-purple-500 selection:text-white pb-28 sm:pb-32 md:pb-8">

  <!-- Background subtle glows (non-blinking) -->
  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
    <div class="absolute -top-40 -left-40 w-[32rem] h-[32rem] bg-purple-600/10 rounded-full blur-[140px]"></div>
    <div class="absolute top-1/3 -right-40 w-[28rem] h-[28rem] bg-emerald-500/10 rounded-full blur-[140px]"></div>
    <div class="absolute -bottom-40 left-1/3 w-[30rem] h-[30rem] bg-amber-500/5 rounded-full blur-[150px]"></div>
  </div>

  <div class="relative z-10 flex-1 flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="border-b border-white/10 bg-[#090d16]/90 backdrop-blur-md sticky top-0 z-30">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">

        <!-- Left: Logo & Project Brand -->
        <div class="flex items-center gap-3">
          <a href="/dashboard.php" class="flex items-center gap-2.5 group">
            <img src="/logo.jpg" alt="Solana Undo Logo" class="w-9 h-9 rounded-xl shadow-md border border-purple-500/30 group-hover:scale-105 transition-transform duration-200 object-cover">
            <div>
              <div class="flex items-center gap-2">
                <span class="text-base font-bold tracking-tight text-white font-display">Undo Window</span>
                <span class="hidden sm:inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/10 text-purple-400 border border-purple-500/20">
                  SOLANA ESCROW
                </span>
              </div>
            </div>
          </a>
        </div>

        <!-- Center: Desktop Tab Navigation -->
        <nav class="hidden md:flex items-center space-x-1">
          <button type="button" data-tab-target="overview" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition nav-tab-active">
            Dashboard
          </button>
          <button type="button" data-tab-target="history" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition nav-tab-inactive">
            History
          </button>
          <button type="button" data-tab-target="settings" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition nav-tab-inactive">
            Settings & 2FA
          </button>
          <?php if ($isAdmin): ?>
          <button type="button" data-tab-target="admin" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition nav-tab-inactive flex items-center gap-1.5 text-amber-400">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Admin Ops
          </button>
          <?php endif; ?>
        </nav>

        <!-- Right: Deposit Pill, Profile, and Mobile Hamburger -->
        <div class="flex items-center gap-2.5">

          <!-- Network badge (Static dot, no animation) -->
          <div class="hidden lg:flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-medium">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
            <span>Devnet</span>
          </div>

          <!-- Deposit Address Pill -->
          <button type="button"
                  onclick="openDepositModal()"
                  class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-800/80 hover:bg-slate-700/80 border border-white/10 text-xs text-slate-200 transition group shadow-sm"
                  title="Click to view Deposit QR Code">
            <span class="text-slate-400 text-[11px] uppercase font-semibold hidden sm:inline">Deposit:</span>
            <span id="userDepositAddress" class="font-mono text-purple-300 font-medium">7xKX...gAsU</span>
            <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-white transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
            </svg>
          </button>

          <!-- Desktop User Menu -->
          <div class="hidden sm:flex items-center gap-2 pl-2 border-l border-white/10">
            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-purple-600 to-indigo-500 flex items-center justify-center text-xs font-bold text-white shadow-md">
              <?= strtoupper(substr($username, 0, 2)) ?>
            </div>
            <a href="/api/auth/logout" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition" title="Sign Out">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
          </div>

          <!-- Mobile Hamburger Toggle Button (Top-Right) -->
          <button type="button"
                  id="mobileHamburgerBtn"
                  onclick="toggleMobileMenu()"
                  class="md:hidden p-2 rounded-xl text-slate-300 hover:text-white bg-slate-800/80 border border-white/10 transition"
                  aria-label="Toggle Navigation">
            <svg id="hamburgerOpenIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            <svg id="hamburgerCloseIcon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>

        </div>

      </div>

      <!-- Mobile Dropdown Menu under Header -->
      <div id="mobileDropdownMenu" class="hidden md:hidden border-t border-white/10 bg-[#090d16]/98 backdrop-blur-xl px-4 py-3 space-y-1 shadow-2xl">
        <button type="button" onclick="switchTab('overview'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-200 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          Dashboard
        </button>
        <button type="button" onclick="switchTab('history'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-200 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          History & Ledger
        </button>
        <button type="button" onclick="switchTab('settings'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-200 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          Settings & 2FA
        </button>
        <?php if ($isAdmin): ?>
        <button type="button" onclick="switchTab('admin'); toggleMobileMenu(false);" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold text-amber-400 hover:bg-white/5 transition text-left">
          <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
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
                <img src="https://cryptologos.cc/logos/solana-sol-logo.png"
                     onerror="this.src='/assets/img/sol.png'"
                     alt="Solana (SOL)"
                     class="w-8 h-8 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-purple-500/30">
                <div>
                  <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">Solana (SOL)</h3>
                  <span id="bal-fiat-sol" class="text-[11px] text-slate-400 font-mono">≈ $0.00</span>
                </div>
              </div>
              <button onclick="openDepositModal()" class="text-xs text-purple-400 hover:text-purple-300 font-semibold transition">+ Deposit</button>
            </div>
            <div class="space-y-1.5 mt-2">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-400">Available:</span>
                <span id="bal-avail-sol" class="text-xl font-extrabold text-white font-mono">0.0000</span>
              </div>
              <div class="flex items-baseline justify-between text-xs pt-1 border-t border-white/5">
                <span class="text-amber-400/90 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> In Escrow:
                </span>
                <span id="bal-escrow-sol" class="font-mono text-amber-300 font-medium">0.0000</span>
              </div>
            </div>
          </div>

          <!-- USDC Balance Card -->
          <div class="glass-panel glass-panel-hover rounded-2xl p-5 relative overflow-hidden">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2.5">
                <img src="https://cryptologos.cc/logos/usd-coin-usdc-logo.png"
                     onerror="this.src='/assets/img/usdc.png'"
                     alt="USD Coin (USDC)"
                     class="w-8 h-8 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-blue-500/30">
                <div>
                  <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">USD Coin (USDC)</h3>
                  <span id="bal-fiat-usdc" class="text-[11px] text-slate-400 font-mono">≈ $0.00</span>
                </div>
              </div>
              <button onclick="openDepositModal()" class="text-xs text-blue-400 hover:text-blue-300 font-semibold transition">+ Deposit</button>
            </div>
            <div class="space-y-1.5 mt-2">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-400">Available:</span>
                <span id="bal-avail-usdc" class="text-xl font-extrabold text-white font-mono">0.00</span>
              </div>
              <div class="flex items-baseline justify-between text-xs pt-1 border-t border-white/5">
                <span class="text-amber-400/90 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> In Escrow:
                </span>
                <span id="bal-escrow-usdc" class="font-mono text-amber-300 font-medium">0.00</span>
              </div>
            </div>
          </div>

          <!-- USDT Balance Card -->
          <div class="glass-panel glass-panel-hover rounded-2xl p-5 relative overflow-hidden">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2.5">
                <img src="https://cryptologos.cc/logos/tether-usdt-logo.png"
                     onerror="this.src='/assets/img/usdt.png'"
                     alt="Tether (USDT)"
                     class="w-8 h-8 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-emerald-500/30">
                <div>
                  <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300">Tether (USDT)</h3>
                  <span id="bal-fiat-usdt" class="text-[11px] text-slate-400 font-mono">≈ $0.00</span>
                </div>
              </div>
              <button onclick="openDepositModal()" class="text-xs text-emerald-400 hover:text-emerald-300 font-semibold transition">+ Deposit</button>
            </div>
            <div class="space-y-1.5 mt-2">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-400">Available:</span>
                <span id="bal-avail-usdt" class="text-xl font-extrabold text-white font-mono">0.00</span>
              </div>
              <div class="flex items-baseline justify-between text-xs pt-1 border-t border-white/5">
                <span class="text-amber-400/90 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> In Escrow:
                </span>
                <span id="bal-escrow-usdt" class="font-mono text-amber-300 font-medium">0.00</span>
              </div>
            </div>
          </div>

          <!-- Escrow Shield Summary Card -->
          <div class="glass-panel rounded-2xl p-5 relative overflow-hidden border-amber-500/30 bg-gradient-to-br from-amber-500/10 via-transparent to-purple-600/10">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-bold uppercase tracking-wider text-amber-300 flex items-center gap-1.5">
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                Escrow Protected
              </span>
              <span id="pendingTransfersCount" class="hidden">0</span>
            </div>
            <div class="text-2xl font-black text-white font-mono tracking-tight mt-1" id="totalEscrowShielded">
              $0.00
            </div>
            <p class="text-[11px] text-slate-300 mt-2 leading-relaxed">
              Funds in the undo window can be revoked instantly before the countdown elapses.
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
                <span class="text-xs text-slate-400">Live Escrow</span>
              </div>
              <div class="text-xs text-slate-400 flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                <span>Auto-ticker active</span>
              </div>
            </div>

            <!-- Pending Transfers Container -->
            <div id="pendingTransfersContainer" class="space-y-3">
              <!-- Rendered dynamically by dashboard.js with official logos and draining progress bar -->
            </div>

            <!-- Empty State for Pending Transfers -->
            <div id="pendingTransfersEmpty" class="glass-panel rounded-2xl p-8 text-center border-dashed border-white/10">
              <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-800/80 border border-white/5 flex items-center justify-center text-slate-400 mb-3 shadow-inner">
                <svg class="w-7 h-7 text-purple-400/80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
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
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
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
                        <img id="formTokenLogo" src="https://cryptologos.cc/logos/solana-sol-logo.png" onerror="this.src='/assets/img/sol.png'" alt="Token" class="w-4 h-4 rounded-full object-contain">
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
                         placeholder="Base58 Solana address (e.g. 7xKX...)"
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

                <!-- Dynamic Threshold & Undo Notice Card -->
                <div id="transferNoticeBox" class="rounded-xl p-3 bg-slate-800/60 border border-white/5 text-slate-400 text-xs flex items-start gap-2.5">
                  <div id="transferNoticeText">
                    <strong>ℹ️ Small Transfer Protection:</strong> Transfers up to 2.0 SOL are held in a 10-minute escrow window before broadcasting.
                  </div>
                </div>

                <!-- Fee & Network info -->
                <div class="pt-1 flex items-center justify-between text-[11px] text-slate-500">
                  <span>Platform Fee: <strong class="text-slate-300">0.00 SOL</strong></span>
                  <span>Solana Rent & Gas: <strong class="text-emerald-400">Sponsored</strong></span>
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
              <svg class="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
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

        <!-- History Table Card -->
        <div class="glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-xl">
          <div class="overflow-x-auto">
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
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                    <img src="https://cryptologos.cc/logos/solana-sol-logo.png" onerror="this.src='/assets/img/sol.png'" alt="SOL" class="w-3.5 h-3.5 rounded-full inline">
                    SOL
                  </label>
                  <input type="number" id="settingsThreshSol" step="0.1" value="2.0" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
                </div>
                <div>
                  <label class="block text-[11px] font-semibold text-slate-400 mb-1 flex items-center gap-1">
                    <img src="https://cryptologos.cc/logos/usd-coin-usdc-logo.png" onerror="this.src='/assets/img/usdc.png'" alt="USDC" class="w-3.5 h-3.5 rounded-full inline">
                    USDC
                  </label>
                  <input type="number" id="settingsThreshUsdc" step="10" value="100.0" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
                </div>
                <div>
                  <label class="block text-[11px] font-semibold text-slate-400 mb-1 flex items-center gap-1">
                    <img src="https://cryptologos.cc/logos/tether-usdt-logo.png" onerror="this.src='/assets/img/usdt.png'" alt="USDT" class="w-3.5 h-3.5 rounded-full inline">
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
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  </div>
                  <div>
                    <h3 class="text-sm font-bold text-white">Two-Factor Authentication (2FA)</h3>
                    <p class="text-xs text-slate-400">Google Authenticator or TOTP</p>
                  </div>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                  Active
                </span>
              </div>
              <p class="text-xs text-slate-400">
                2FA protects critical actions like private key export and immediate token withdrawals.
              </p>
              <button type="button" onclick="open2FaModal()" class="w-full py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/10 text-xs font-medium text-slate-200 transition">
                Configure / Re-verify 2FA
              </button>
            </div>

            <!-- Custodial Key Export Security Card -->
            <div class="glass-panel rounded-3xl p-6 border border-rose-500/30 bg-rose-500/[0.03] space-y-4">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
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

        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Platform Hot Wallet & Escrow Ledger</h2>
            <p class="text-xs text-slate-400">Platform-wide pending transfers and RPC broadcaster status</p>
          </div>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
            Cron Daemon: Running
          </span>
        </div>

        <!-- Hot Wallet Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
          <div class="glass-panel rounded-2xl p-4">
            <div class="text-xs text-slate-400 font-semibold uppercase flex items-center gap-1.5">
              <img src="https://cryptologos.cc/logos/solana-sol-logo.png" onerror="this.src='/assets/img/sol.png'" alt="SOL" class="w-3.5 h-3.5 rounded-full inline">
              Hot Wallet SOL
            </div>
            <div class="text-xl font-black text-white font-mono mt-1">450.28 <span class="text-xs text-slate-400 font-normal">SOL</span></div>
            <div class="text-[11px] text-slate-500 mt-1">Gas reserve healthy</div>
          </div>
          <div class="glass-panel rounded-2xl p-4">
            <div class="text-xs text-slate-400 font-semibold uppercase flex items-center gap-1.5">
              <img src="https://cryptologos.cc/logos/usd-coin-usdc-logo.png" onerror="this.src='/assets/img/usdc.png'" alt="USDC" class="w-3.5 h-3.5 rounded-full inline">
              Hot Wallet USDC
            </div>
            <div class="text-xl font-black text-white font-mono mt-1">15,420.00 <span class="text-xs text-slate-400 font-normal">USDC</span></div>
            <div class="text-[11px] text-slate-500 mt-1">SPL ATA liquid</div>
          </div>
          <div class="glass-panel rounded-2xl p-4">
            <div class="text-xs text-slate-400 font-semibold uppercase">Pending System Escrow</div>
            <div class="text-xl font-black text-amber-400 font-mono mt-1">2 Active Holds</div>
            <div class="text-[11px] text-slate-500 mt-1">Across all users</div>
          </div>
          <div class="glass-panel rounded-2xl p-4">
            <div class="text-xs text-slate-400 font-semibold uppercase">Broadcaster Tick</div>
            <div class="text-xl font-black text-white font-mono mt-1">1m Cron</div>
            <div class="text-[11px] text-slate-500 mt-1">Status: Active</div>
          </div>
        </div>

        <!-- Admin Table of Platform Escrow -->
        <div class="glass-panel rounded-2xl border border-white/10 p-5">
          <h3 class="text-sm font-bold text-white mb-3">All Active Escrow Transfers (Platform-Wide)</h3>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
              <thead>
                <tr class="border-b border-white/10 text-slate-400 font-bold uppercase text-[10px]">
                  <th class="py-2.5 px-3">Transfer ID</th>
                  <th class="py-2.5 px-3">User</th>
                  <th class="py-2.5 px-3">Amount</th>
                  <th class="py-2.5 px-3">Destination</th>
                  <th class="py-2.5 px-3">Expires At</th>
                  <th class="py-2.5 px-3">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr>
                  <td class="py-3 px-3 font-mono text-purple-400">#TX-101</td>
                  <td class="py-3 px-3">alex@undo.sol</td>
                  <td class="py-3 px-3 font-bold text-white">
                    <span class="inline-flex items-center gap-1">
                      <img src="https://cryptologos.cc/logos/solana-sol-logo.png" onerror="this.src='/assets/img/sol.png'" alt="SOL" class="w-3.5 h-3.5 rounded-full inline">
                      0.75 SOL
                    </span>
                  </td>
                  <td class="py-3 px-3 font-mono text-slate-300">9WzD...AWWM</td>
                  <td class="py-3 px-3 text-amber-300 font-mono">In 6m 30s</td>
                  <td class="py-3 px-3"><span class="badge-pending px-2 py-0.5 rounded text-[10px]">Escrow Hold</span></td>
                </tr>
                <tr>
                  <td class="py-3 px-3 font-mono text-purple-400">#TX-102</td>
                  <td class="py-3 px-3">alex@undo.sol</td>
                  <td class="py-3 px-3 font-bold text-white">
                    <span class="inline-flex items-center gap-1">
                      <img src="https://cryptologos.cc/logos/usd-coin-usdc-logo.png" onerror="this.src='/assets/img/usdc.png'" alt="USDC" class="w-3.5 h-3.5 rounded-full inline">
                      50.00 USDC
                    </span>
                  </td>
                  <td class="py-3 px-3 font-mono text-slate-300">EPjF...Dt1v</td>
                  <td class="py-3 px-3 text-amber-300 font-mono">In 3m 00s</td>
                  <td class="py-3 px-3"><span class="badge-pending px-2 py-0.5 rounded text-[10px]">Escrow Hold</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </section>
      <?php endif; ?>

    </main>

  </div>

  <!-- Mobile Bottom Navigation Bar (fixed on mobile with ample clearance) -->
  <nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-[#090d16]/95 backdrop-blur-lg border-t border-white/10 px-4 py-2.5 flex items-center justify-around shadow-2xl">
    <button type="button" data-mobile-tab="overview" onclick="switchTab('overview')" class="flex flex-col items-center gap-1 text-purple-400 py-1 px-3 rounded-xl">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      <span class="text-[10px] font-semibold">Overview</span>
    </button>
    <button type="button" data-mobile-tab="history" onclick="switchTab('history')" class="flex flex-col items-center gap-1 text-slate-400 py-1 px-3 rounded-xl">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span class="text-[10px] font-semibold">History</span>
    </button>
    <button type="button" data-mobile-tab="settings" onclick="switchTab('settings')" class="flex flex-col items-center gap-1 text-slate-400 py-1 px-3 rounded-xl">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <span class="text-[10px] font-semibold">Settings</span>
    </button>
    <?php if ($isAdmin): ?>
    <button type="button" data-mobile-tab="admin" onclick="switchTab('admin')" class="flex flex-col items-center gap-1 text-slate-400 py-1 px-3 rounded-xl">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      <span class="text-[10px] font-semibold">Admin</span>
    </button>
    <?php endif; ?>
  </nav>

  <!-- ========================================== -->
  <!-- MODALS                                     -->
  <!-- ========================================== -->

  <!-- 1. Cancel Transfer Confirmation Modal -->
  <div id="cancelModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel rounded-3xl max-w-md w-full p-6 border border-white/10 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-2xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <div>
          <h3 class="text-base font-bold text-white">Cancel Pending Transfer?</h3>
          <p class="text-xs text-slate-400">Funds will immediately return to your balance</p>
        </div>
      </div>

      <div id="cancelModalDetails"></div>

      <p class="text-xs text-slate-300 leading-relaxed">
        Because this transfer is still in the undo escrow window, cancelling it stops the broadcast. <strong>100% of the funds will instantly credit back to your available balance.</strong>
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
    <div class="glass-panel rounded-3xl max-w-sm w-full p-6 border border-white/10 shadow-2xl text-center space-y-4 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-white">Your Custodial Deposit Address</h3>
        <button type="button" onclick="closeDepositModal()" class="text-slate-400 hover:text-white text-xs">✕</button>
      </div>

      <div class="bg-white p-3 rounded-2xl mx-auto inline-block shadow-md">
        <img id="depositQrCodeImg"
             src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($depositAddress) ?>"
             alt="Deposit QR Code"
             class="w-40 h-40">
      </div>

      <div class="text-left bg-slate-900 p-3 rounded-xl border border-white/5 space-y-1">
        <div class="text-[10px] uppercase font-bold text-slate-400">Solana Deposit Address</div>
        <div id="userDepositAddressFull" class="text-xs font-mono text-purple-300 break-all select-all leading-relaxed">
          <?= htmlspecialchars($depositAddress) ?>
        </div>
      </div>

      <button type="button"
              onclick="copyToClipboard('<?= htmlspecialchars($depositAddress) ?>', 'Deposit address')"
              class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-xs font-bold text-white transition flex items-center justify-center gap-1.5 shadow-lg shadow-purple-600/20">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
        Copy Deposit Address
      </button>

      <p class="text-[11px] text-slate-400 leading-tight">
        Send native <strong>SOL</strong>, <strong>USDC</strong>, or <strong>USDT</strong> to this address. Incoming deposits automatically credit your available balance.
      </p>
    </div>
  </div>

  <!-- 3. 2FA Setup Modal -->
  <div id="twoFaModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel rounded-3xl max-w-sm w-full p-6 border border-white/10 shadow-2xl space-y-4 text-center max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-white">Setup Authenticator App</h3>
        <button type="button" onclick="close2FaModal()" class="text-slate-400 hover:text-white text-xs">✕</button>
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

  <!-- 4. Export Private Key Modal -->
  <div id="exportKeyModal" class="modal-backdrop fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-panel rounded-3xl max-w-md w-full p-6 border border-rose-500/40 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">

      <!-- Step 1: Verification -->
      <div id="exportKeyStep1" class="space-y-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-rose-500/20 text-rose-400 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          </div>
          <div>
            <h3 class="text-base font-bold text-white">Security Check: Export Key</h3>
            <p class="text-xs text-rose-300 font-medium">Verify your identity to decrypt your custodial wallet</p>
          </div>
        </div>

        <div class="bg-rose-500/10 border border-rose-500/20 p-3 rounded-xl text-xs text-rose-300 leading-relaxed">
          <strong>CRITICAL WARNING:</strong> Anyone possessing this raw private key has complete, irreversible control over your Solana deposit funds. Never share it with support or enter it on unverified websites.
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Account Password</label>
            <input type="password" id="exportKeyPassword" placeholder="••••••••••••" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">2FA Authenticator Code</label>
            <input type="text" id="exportKeyTotp" maxlength="6" placeholder="6-digit TOTP code" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-white/10 text-white text-xs font-mono">
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeExportKeyModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-slate-300">Cancel</button>
          <button type="button" id="submitExportKeyVerifyBtn" onclick="verifyAndRevealKey()" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-bold text-white">Verify & Reveal Key</button>
        </div>
      </div>

      <!-- Step 2: Revealed Key View -->
      <div id="exportKeyStep2" class="space-y-4 hidden text-center">
        <h3 class="text-base font-bold text-white">Your Custodial Solana Keypair</h3>
        <p class="text-xs text-slate-400">Import this Base58 private key into Phantom or Solflare</p>

        <div class="p-3 bg-slate-950 rounded-xl border border-white/10 text-left">
          <div class="text-[10px] uppercase font-bold text-rose-400 mb-1">Raw Base58 Private Key</div>
          <div id="rawPrivateKeyDisplay" class="font-mono text-xs text-emerald-400 break-all select-all bg-black/60 p-2.5 rounded-lg border border-white/5">
            Loading...
          </div>
        </div>

        <button type="button"
                onclick="copyToClipboard(document.getElementById('rawPrivateKeyDisplay').innerText, 'Private Key')"
                class="w-full py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-xs font-bold text-white transition">
          Copy Private Key
        </button>

        <button type="button" onclick="closeExportKeyModal()" class="w-full py-2 rounded-xl bg-slate-800 text-xs font-medium text-slate-300">
          I Have Safely Saved It (Close)
        </button>
      </div>

    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container"></div>

  <!-- Mobile Menu & Deposit Modal Inline Helpers -->
  <script>
    function toggleMobileMenu(force) {
      const menu = document.getElementById('mobileDropdownMenu');
      const openIcon = document.getElementById('hamburgerOpenIcon');
      const closeIcon = document.getElementById('hamburgerCloseIcon');
      if (!menu) return;

      const shouldOpen = force !== undefined ? force : menu.classList.contains('hidden');
      if (shouldOpen) {
        menu.classList.remove('hidden');
        if (openIcon) openIcon.classList.add('hidden');
        if (closeIcon) closeIcon.classList.remove('hidden');
      } else {
        menu.classList.add('hidden');
        if (openIcon) openIcon.classList.remove('hidden');
        if (closeIcon) closeIcon.classList.add('hidden');
      }
    }

    function openDepositModal() {
      const modal = document.getElementById('depositModal');
      if (modal) modal.classList.remove('hidden');
    }

    function closeDepositModal() {
      const modal = document.getElementById('depositModal');
      if (modal) modal.classList.add('hidden');
    }
  </script>

  <!-- Core JavaScript modules -->
  <script src="/assets/js/wallet.js"></script>
  <script src="/assets/js/dashboard.js"></script>
</body>
</html>
