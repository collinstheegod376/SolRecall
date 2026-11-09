<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
ensure_session_started();

// Redirect if already logged in
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

// Clear pending 2FA challenge if returning to login
if (isset($_GET['action']) && $_GET['action'] === 'cancel_2fa') {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_token'], $_SESSION['pending_2fa_expires']);
}

// Check for error/success flashes in session
$error = $_SESSION['flash_error'] ?? null;
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
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
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '2.0' ?>">
</head>
<body class="min-h-screen bg-[#000000] text-zinc-100 flex flex-col justify-between selection:bg-zinc-800 selection:text-white font-body">

  <!-- Main Container -->
  <div class="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">

      <!-- Brand Header -->
      <div class="text-center">
        <a href="/" class="inline-flex items-center justify-center gap-3 group">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-14 h-14 rounded-2xl border border-[#222228] shadow-2xl group-hover:scale-105 transition-transform duration-200 object-cover">
        </a>
        <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white font-display">
          Welcome back
        </h1>
        <p class="mt-2 text-sm text-[#94949e] font-body">
          Sign in to your <span class="text-white font-medium">SolRecall</span> escrow account
        </p>
      </div>

      <!-- Auth Card -->
      <div class="glass-panel rounded-3xl p-8 border border-[#222228] shadow-2xl relative bg-[#141418]/85 backdrop-blur-xl">

        <!-- Error / Success Alert -->
        <?php if ($error): ?>
          <div class="mb-5 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2 font-body">
            <svg class="w-4 h-4 shrink-0 text-rose-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="mb-5 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2 font-body">
            <svg class="w-4 h-4 shrink-0 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= htmlspecialchars($success) ?></span>
          </div>
        <?php endif; ?>

        <!-- Email & Password Form -->
        <form action="/api/auth/login" method="POST" id="loginForm" class="space-y-4">
          <div>
            <label for="email" class="block text-xs font-bold uppercase tracking-wider text-[#94949e] mb-1.5 font-nav">
              Email or Username
            </label>
            <input type="text"
                   id="email"
                   name="email"
                   required
                   autocomplete="username"
                   placeholder="jane@example.com or Jane Doe"
                   class="w-full px-4 py-2.5 rounded-xl bg-[#08080a] border border-[#222228] text-white placeholder-[#71717a] focus:outline-none focus:border-[#3f3f4c] text-sm transition font-body">
          </div>

          <div>
            <div class="flex items-center justify-between mb-1.5">
              <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#94949e] font-nav">
                Password
              </label>
              <a href="#" onclick="showToast('Password reset link has been dispatched to email if registered', 'info'); return false;" class="text-xs text-white hover:text-zinc-300 transition font-body">Forgot password?</a>
            </div>
            <div class="relative">
              <input type="password"
                     id="password"
                     name="password"
                     required
                     autocomplete="current-password"
                     placeholder="••••••••••••"
                     class="w-full px-4 py-2.5 rounded-xl bg-[#08080a] border border-[#222228] text-white placeholder-[#71717a] focus:outline-none focus:border-[#3f3f4c] text-sm transition pr-10 font-body">
              <button type="button"
                      id="togglePassword"
                      class="absolute inset-y-0 right-0 pr-3 flex items-center text-[#71717a] hover:text-white">
                <svg id="eyeIcon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <div class="flex items-center justify-between pt-1">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="remember" class="w-4 h-4 rounded bg-[#08080a] border-[#222228] text-zinc-100 focus:ring-0 focus:ring-offset-0">
              <span class="text-xs text-[#94949e] font-body">Remember this device</span>
            </label>
          </div>

          <button type="submit"
                  id="submitBtn"
                  class="w-full mt-2 py-3 px-4 rounded-xl font-bold text-black bg-white hover:bg-zinc-200 border border-white transition-all duration-200 flex items-center justify-center gap-2 text-sm font-cta cursor-pointer shadow-lg shadow-white/5 active:scale-95">
            <span id="submitSpinner" class="hidden spinner-sm !border-t-black !border-black/20"></span>
            <span id="submitBtnText">Sign In</span>
          </button>
        </form>

        <!-- Switch to Register -->
        <p class="mt-6 text-center text-xs text-[#94949e] font-body">
          Don't have an account yet?
          <a href="/register" class="font-bold text-white hover:underline ml-1 transition">
            Create an account &rarr;
          </a>
        </p>

      </div>

      <!-- Feature Badges -->
      <div class="grid grid-cols-3 gap-2 text-center pt-2">
        <div class="p-2.5 rounded-xl bg-[#08080a] border border-[#222228]">
          <div class="text-amber-400 text-xs font-bold font-timelock tracking-wider">5-15 MIN HOLD</div>
          <div class="text-[10px] text-[#94949e] font-body">Undo small mistakes</div>
        </div>
        <div class="p-2.5 rounded-xl bg-[#08080a] border border-[#222228]">
          <div class="text-emerald-400 text-xs font-bold font-timelock tracking-wider">SOL • USDC • USDT</div>
          <div class="text-[10px] text-[#94949e] font-body">Multi-token escrow</div>
        </div>
        <div class="p-2.5 rounded-xl bg-[#08080a] border border-[#222228]">
          <div class="text-white text-xs font-bold font-timelock tracking-wider">SELF-CUSTODIAL</div>
          <div class="text-[10px] text-[#94949e] font-body">Full export control</div>
        </div>
      </div>

    </div>
  </div>

  <!-- Brand Footer -->
  <footer class="relative z-10 py-6 text-center text-xs text-[#71717a] font-nav tracking-wider">
    SOLRECALL PROTOCOL &bull; MAINNET V2.0 &bull; REVERSIBLE TIME-LOCKED ESCROW
  </footer>

  <!-- Toast Container -->
  <div id="toast-container"></div>

  <!-- Scripts -->
  <script>
    // Toggle Password Visibility
    const toggleBtn = document.getElementById('togglePassword');
    const pwdInput = document.getElementById('password');
    if (toggleBtn && pwdInput) {
      toggleBtn.addEventListener('click', () => {
        const type = pwdInput.getAttribute('type') === 'password' ? 'text' : 'password';
        pwdInput.setAttribute('type', type);
      });
    }

    // Form submission helper
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        const spinner = document.getElementById('submitSpinner');
        const btnText = document.getElementById('submitBtnText');

        if (spinner) spinner.classList.remove('hidden');
        if (btnText) btnText.textContent = 'Authenticating...';

        try {
          const res = await fetch('/api/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
          });
          const data = await res.json();
          const requires2fa = Boolean(data.requires_2fa || (data.data && data.data.requires_2fa));
          if (requires2fa) {
            const dest = data.redirect || (data.data && data.data.redirect) || '/two-factor-verify';
            window.location.href = dest;
            return;
          }
          if (data.success) {
            const token = data.data?.session_token || data.session_token;
            if (token) {
              try { localStorage.setItem('solrecall_session_token', token); } catch (e) {}
            }
            window.location.href = '/dashboard';
          } else {
            showToast(data.message || data.error || 'Authentication failed. Please check your credentials.', 'error');
          }
        } catch (err) {
          showToast(err.message || 'Network error connecting to authentication service.', 'error');
        } finally {
          if (spinner) spinner.classList.add('hidden');
          if (btnText) btnText.textContent = 'Sign In';
        }
      });
    }

    // Toast function
    function showToast(msg, type = 'info') {
      const c = document.getElementById('toast-container');
      const t = document.createElement('div');
      t.className = `toast toast-${type}`;
      t.innerHTML = `<span class="flex-1">${msg}</span>`;
      c.appendChild(t);
      requestAnimationFrame(() => t.classList.add('show'));
      setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 300);
      }, 3500);
    }
  </script>

  <!-- PWA Service Worker Registration -->
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((err) => {
          console.warn('[SolRecall] Service Worker registration failed:', err);
        });
      });
    }
  </script>
</body>
</html>
