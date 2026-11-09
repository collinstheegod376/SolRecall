<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
ensure_session_started();

if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

$error = $_SESSION['flash_error'] ?? null;
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="en" class="dark overflow-x-hidden">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Create Account • SolRecall</title>
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
  <div class="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-7">

      <!-- Brand Header -->
      <div class="text-center">
        <a href="/" class="inline-flex items-center justify-center gap-3 group">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-14 h-14 rounded-2xl border border-[#222228] shadow-2xl group-hover:scale-105 transition-transform duration-200 object-cover">
        </a>
        <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white font-display">
          Create an account
        </h1>
        <p class="mt-2 text-sm text-[#94949e] font-body">
          Get your protected Solana deposit address and SolRecall undo window
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

        <!-- Standard Registration Form -->
        <form action="/api/auth/register" method="POST" id="registerForm" class="space-y-4">
          <div>
            <label for="username" class="block text-xs font-bold uppercase tracking-wider text-[#94949e] mb-1.5 font-nav">
              Username
            </label>
            <input type="text"
                   id="username"
                   name="username"
                   required
                   placeholder="Jane Doe"
                   class="w-full px-4 py-2.5 rounded-xl bg-[#08080a] border border-[#222228] text-white placeholder-[#71717a] focus:outline-none focus:border-[#3f3f4c] text-sm transition font-body">
          </div>

          <div>
            <label for="email" class="block text-xs font-bold uppercase tracking-wider text-[#94949e] mb-1.5 font-nav">
              Email Address
            </label>
            <input type="email"
                   id="email"
                   name="email"
                   required
                   autocomplete="email"
                   placeholder="jane@example.com"
                   class="w-full px-4 py-2.5 rounded-xl bg-[#08080a] border border-[#222228] text-white placeholder-[#71717a] focus:outline-none focus:border-[#3f3f4c] text-sm transition font-body">
          </div>

          <div>
            <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#94949e] mb-1.5 font-nav">
              Password
            </label>
            <input type="password"
                   id="password"
                   name="password"
                   required
                   autocomplete="new-password"
                   minlength="8"
                   placeholder="At least 8 characters"
                   class="w-full px-4 py-2.5 rounded-xl bg-[#08080a] border border-[#222228] text-white placeholder-[#71717a] focus:outline-none focus:border-[#3f3f4c] text-sm transition font-body">
          </div>

          <!-- Custodial Escrow Notice & Terms -->
          <div class="pt-2">
            <label class="flex items-start gap-2.5 cursor-pointer">
              <input type="checkbox" required name="terms" class="mt-0.5 w-4 h-4 rounded bg-[#08080a] border-[#222228] text-zinc-100 focus:ring-0 focus:ring-offset-0">
              <span class="text-xs text-[#94949e] leading-relaxed font-body">
                I understand that small transfers under the threshold are held in temporary escrow so I can cancel mistakes, and I can export my custodial private key at any time in Settings.
              </span>
            </label>
          </div>

          <button type="submit"
                  id="submitBtn"
                  class="w-full mt-3 py-3 px-4 rounded-xl font-bold text-black bg-white hover:bg-zinc-200 border border-white transition-all duration-200 flex items-center justify-center gap-2 text-sm shadow-lg shadow-white/5 font-cta cursor-pointer active:scale-95">
            <span id="submitSpinner" class="hidden spinner-sm !border-t-black !border-black/20"></span>
            <span id="submitBtnText">Create Account</span>
          </button>
        </form>

        <!-- Switch to Login -->
        <p class="mt-6 text-center text-xs text-[#94949e] font-body">
          Already have an account?
          <a href="/login" class="font-bold text-white hover:underline ml-1 transition">
            Sign In &rarr;
          </a>
        </p>

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
    // Register Form submission
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
      registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('username').value;
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        const spinner = document.getElementById('submitSpinner');
        const btnText = document.getElementById('submitBtnText');

        if (spinner) spinner.classList.remove('hidden');
        if (btnText) btnText.textContent = 'Generating Escrow Address...';

        try {
          const res = await fetch('/api/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, email, password })
          });
          const data = await res.json();
          if (data.success) {
            const token = data.data?.session_token || data.session_token;
            if (token) {
              try { localStorage.setItem('solrecall_session_token', token); } catch (e) {}
            }
            showToast('Account successfully created! Redirecting...', 'success');
            setTimeout(() => { window.location.href = '/dashboard'; }, 700);
          } else {
            showToast(data.message || data.error || 'Registration failed.', 'error');
          }
        } catch (err) {
          showToast(err.message || 'Network error.', 'error');
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
