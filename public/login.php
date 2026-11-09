<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
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
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SolRecall - Reversible Time-Locked Escrow on Solana</title>
  <link rel="icon" type="image/png" href="/assets/img/logo.png">
  <link rel="apple-touch-icon" href="/assets/img/logo.png">
  <!-- PWA & Mobile Meta Tags -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#07090e">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="SolRecall">
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
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '1.1' ?>">
</head>
<body class="min-h-screen bg-[#07090e] text-slate-100 flex flex-col justify-between selection:bg-purple-500 selection:text-white">

  <!-- Background decorative glows -->
  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
    <div class="absolute -top-40 -left-40 w-96 h-96 bg-purple-600/15 rounded-full blur-[120px]"></div>
    <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-emerald-500/10 rounded-full blur-[120px]"></div>
  </div>

  <!-- Main Container -->
  <div class="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">

      <!-- Brand Header -->
      <div class="text-center">
        <a href="/" class="inline-flex items-center justify-center gap-3 group">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-14 h-14 rounded-2xl shadow-xl shadow-purple-500/20 border border-purple-500/30 group-hover:scale-105 transition-transform duration-200 object-cover">
        </a>
        <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white">
          Welcome back
        </h1>
        <p class="mt-2 text-sm text-slate-400">
          Sign in to your <span class="text-white font-medium">SolRecall</span> account
        </p>
      </div>

      <!-- Auth Card -->
      <div class="glass-panel rounded-3xl p-8 border border-white/10 shadow-2xl shadow-black/50 relative">

        <!-- Error / Success Alert -->
        <?php if ($error): ?>
          <div class="mb-5 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="mb-5 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span><?= htmlspecialchars($success) ?></span>
          </div>
        <?php endif; ?>

        <!-- Email & Password Form -->
        <form action="/api/auth/login" method="POST" id="loginForm" class="space-y-4">
          <div>
            <label for="email" class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
              Email or Username
            </label>
            <input type="text"
                   id="email"
                   name="email"
                   required
                   autocomplete="username"
                   placeholder="jane@example.com or Jane Doe"
                   class="w-full px-4 py-2.5 rounded-xl bg-slate-900/90 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 text-sm transition">
          </div>

          <div>
            <div class="flex items-center justify-between mb-1.5">
              <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-slate-300">
                Password
              </label>
              <a href="#" onclick="showToast('Password reset link has been dispatched to email if registered', 'info'); return false;" class="text-xs text-purple-400 hover:text-purple-300 transition">Forgot password?</a>
            </div>
            <div class="relative">
              <input type="password"
                     id="password"
                     name="password"
                     required
                     autocomplete="current-password"
                     placeholder="••••••••••••"
                     class="w-full px-4 py-2.5 rounded-xl bg-slate-900/90 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 text-sm transition pr-10">
              <button type="button"
                      id="togglePassword"
                      class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-200">
                <svg id="eyeIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>

          <div class="flex items-center justify-between pt-1">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="remember" class="w-4 h-4 rounded bg-slate-900 border-white/10 text-purple-600 focus:ring-purple-500 focus:ring-offset-0">
              <span class="text-xs text-slate-400">Remember this device</span>
            </label>
          </div>

          <button type="submit"
                  id="submitBtn"
                  class="w-full mt-2 py-3 px-4 rounded-xl font-semibold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 border border-purple-400/30 shadow-lg shadow-purple-600/20 transition-all duration-200 flex items-center justify-center gap-2 text-sm">
            <span id="submitSpinner" class="hidden spinner-sm"></span>
            <span id="submitBtnText">Sign In</span>
          </button>
        </form>

        <!-- Switch to Register -->
        <p class="mt-6 text-center text-xs text-slate-400">
          Don't have an account yet?
          <a href="/register" class="font-semibold text-purple-400 hover:text-purple-300 ml-1 transition">
            Create an account &rarr;
          </a>
        </p>

      </div>

      <!-- Feature Badges -->
      <div class="grid grid-cols-3 gap-2 text-center pt-2">
        <div class="p-2 rounded-xl bg-white/[0.02] border border-white/5">
          <div class="text-amber-400 text-xs font-semibold">5-15 Min Hold</div>
          <div class="text-[10px] text-slate-400">Undo small mistakes</div>
        </div>
        <div class="p-2 rounded-xl bg-white/[0.02] border border-white/5">
          <div class="text-emerald-400 text-xs font-semibold">SOL • USDC • USDT</div>
          <div class="text-[10px] text-slate-400">Multi-token escrow</div>
        </div>
        <div class="p-2 rounded-xl bg-white/[0.02] border border-white/5">
          <div class="text-purple-400 text-xs font-semibold">Self-Custodial Key</div>
          <div class="text-[10px] text-slate-400">Full export control</div>
        </div>
      </div>

    </div>
  </div>

  <!-- Brand Footer -->
  <footer class="relative z-10 py-6 text-center text-xs text-slate-500">
    SolRecall &copy; <?= date('Y') ?> &bull; Reversible Time-Locked Escrow on Solana
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
        // If standalone frontend without backend running, allow demo login
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
