<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard');
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
  <div class="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-7">

      <!-- Brand Header -->
      <div class="text-center">
        <a href="/" class="inline-flex items-center justify-center gap-3 group">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-14 h-14 rounded-2xl shadow-xl shadow-purple-500/20 border border-purple-500/30 group-hover:scale-105 transition-transform duration-200 object-cover">
        </a>
        <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white">
          Create an account
        </h1>
        <p class="mt-2 text-sm text-slate-400">
          Get your protected Solana deposit address and SolRecall undo window
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
                   placeholder="Jane Doe"
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
                   placeholder="jane@example.com"
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
          <a href="/login" class="font-semibold text-purple-400 hover:text-purple-300 ml-1 transition">
            Sign In &rarr;
          </a>
        </p>

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
            setTimeout(() => { window.location.href = '/dashboard'; }, 800);
          } else {
            showToast(data.message || data.error || 'Registration failed.', 'error');
          }
        } catch (err) {
          showToast(err.message || 'Network error during registration.', 'error');
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
