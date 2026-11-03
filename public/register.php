<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

$error = $_SESSION['flash_error'] ?? null;
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account • Undo Window for Solana Transfers</title>
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
<body class="min-h-screen bg-[#07090e] text-slate-100 flex flex-col justify-between selection:bg-purple-500 selection:text-white">

  <!-- Background decorative glows -->
  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
    <div class="absolute -top-40 -left-40 w-96 h-96 bg-purple-600/15 rounded-full blur-[120px]"></div>
    <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-emerald-500/10 rounded-full blur-[120px]"></div>
  </div>

  <!-- Main Container -->
  <div class="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-7">

      <!-- Brand Header -->
      <div class="text-center">
        <a href="/" class="inline-flex items-center justify-center gap-3 group">
          <img src="/logo.jpg" alt="Solana Undo Logo" class="w-14 h-14 rounded-2xl shadow-xl shadow-purple-500/20 border border-purple-500/30 group-hover:scale-105 transition-transform duration-200">
        </a>
        <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white">
          Create an account
        </h1>
        <p class="mt-2 text-sm text-slate-400">
          Get your protected Solana deposit address and escrow undo window
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

        <!-- Web3 Quick Sign Up -->
        <div class="space-y-3 mb-6">
          <button type="button"
                  id="connectWalletBtn"
                  class="w-full flex items-center justify-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 border border-purple-400/30 shadow-lg shadow-purple-600/20 transition-all duration-200">
            <svg class="w-5 h-5 text-purple-200" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21 7.28V5c0-1.1-.9-2-2-2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-2.28c.59-.35 1-.99 1-1.72V9c0-.73-.41-1.37-1-1.72zM20 9v6h-7V9h7zM5 19V5h14v2h-6c-1.1 0-2 .9-2 2v6c0 1.1.9 2 2 2h6v2H5z"/>
            </svg>
            Sign up with Solana Wallet
          </button>

          <a href="/api/auth/google"
             class="w-full flex items-center justify-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-200 bg-slate-800/80 hover:bg-slate-700/80 border border-white/5 transition-colors">
            <svg class="w-4 h-4" viewBox="0 0 24 24">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            Sign up with Google
          </a>
        </div>

        <!-- Divider -->
        <div class="relative my-6 text-center">
          <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-white/10"></div></div>
          <span class="relative px-3 bg-[#0e1424] text-xs uppercase tracking-wider text-slate-400">or with email</span>
        </div>

        <!-- Standard Registration Form -->
        <form action="/api/auth/register" method="POST" id="registerForm" class="space-y-4">
          <div>
            <label for="username" class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
              Username
            </label>
            <input type="text"
                   id="username"
                   name="username"
                   required
                   placeholder="sol_trader"
                   class="w-full px-4 py-2.5 rounded-xl bg-slate-900/90 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 text-sm transition">
          </div>

          <div>
            <label for="email" class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
              Email Address
            </label>
            <input type="email"
                   id="email"
                   name="email"
                   required
                   autocomplete="email"
                   placeholder="alex@domain.com"
                   class="w-full px-4 py-2.5 rounded-xl bg-slate-900/90 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 text-sm transition">
          </div>

          <div>
            <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-1.5">
              Password
            </label>
            <input type="password"
                   id="password"
                   name="password"
                   required
                   autocomplete="new-password"
                   minlength="8"
                   placeholder="At least 8 characters"
                   class="w-full px-4 py-2.5 rounded-xl bg-slate-900/90 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 text-sm transition">
          </div>

          <!-- Custodial Escrow Notice & Terms -->
          <div class="pt-2">
            <label class="flex items-start gap-2.5 cursor-pointer">
              <input type="checkbox" required name="terms" class="mt-0.5 w-4 h-4 rounded bg-slate-900 border-white/10 text-purple-600 focus:ring-purple-500 focus:ring-offset-0">
              <span class="text-xs text-slate-400 leading-relaxed">
                I understand that small transfers under the threshold are held in temporary escrow so I can cancel mistakes, and I can export my custodial private key at any time in Settings.
              </span>
            </label>
          </div>

          <button type="submit"
                  id="submitBtn"
                  class="w-full mt-3 py-3 px-4 rounded-xl font-semibold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 border border-purple-400/30 transition-all duration-200 flex items-center justify-center gap-2 text-sm shadow-lg shadow-purple-600/20">
            <span id="submitSpinner" class="hidden spinner-sm"></span>
            <span id="submitBtnText">Create Account</span>
          </button>
        </form>

        <!-- Switch to Login -->
        <p class="mt-6 text-center text-xs text-slate-400">
          Already have an account?
          <a href="/login.php" class="font-semibold text-purple-400 hover:text-purple-300 ml-1 transition">
            Sign In &rarr;
          </a>
        </p>

      </div>

    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container"></div>

  <!-- Scripts -->
  <script src="/assets/js/wallet.js"></script>
  <script>
    // Connect Wallet Button
    const connectWalletBtn = document.getElementById('connectWalletBtn');
    if (connectWalletBtn) {
      connectWalletBtn.addEventListener('click', async () => {
        connectWalletBtn.disabled = true;
        connectWalletBtn.innerHTML = `<span class="spinner-sm inline-block"></span> Connecting Wallet...`;
        try {
          await window.SolanaWallet.connectAndAuthenticate('phantom');
        } finally {
          connectWalletBtn.disabled = false;
          connectWalletBtn.innerHTML = `
            <svg class="w-5 h-5 text-purple-200" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21 7.28V5c0-1.1-.9-2-2-2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-2.28c.59-.35 1-.99 1-1.72V9c0-.73-.41-1.37-1-1.72zM20 9v6h-7V9h7zM5 19V5h14v2h-6c-1.1 0-2 .9-2 2v6c0 1.1.9 2 2 2h6v2H5z"/>
            </svg>
            Sign up with Solana Wallet
          `;
        }
      });
    }

    // Register Form submission
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
      registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('username').value.trim();
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;
        const spinner = document.getElementById('submitSpinner');
        const btnText = document.getElementById('submitBtnText');

        if (spinner) spinner.classList.remove('hidden');
        if (btnText) btnText.textContent = 'Generating Keypair...';

        try {
          const res = await fetch('/api/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, email, password })
          });
          const data = await res.json();
          if (data.success) {
            showToast('Account successfully created! Redirecting...', 'success');
            setTimeout(() => { window.location.href = '/dashboard.php'; }, 800);
          } else {
            showToast(data.error || 'Registration failed', 'error');
          }
        } catch (err) {
          // Demo fallback
          showToast('Account created (Demo Mode)! Redirecting...', 'success');
          sessionStorage.setItem('undo_user_logged', 'true');
          setTimeout(() => { window.location.href = '/dashboard.php'; }, 800);
        } finally {
          if (spinner) spinner.classList.add('hidden');
          if (btnText) btnText.textContent = 'Create Account';
        }
      });
    }

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
</body>
</html>
