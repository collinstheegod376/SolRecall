<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
ensure_session_started();

// If already fully authenticated, redirect directly to dashboard
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

// User requested cancellation or return to login
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    unset(
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_token'],
        $_SESSION['pending_2fa_expires']
    );
    header('Location: /login');
    exit;
}

// Enforce mandatory pending 2FA login session check
if (empty($_SESSION['pending_2fa_user_id'])) {
    header('Location: /login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark overflow-x-hidden">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Two-Factor Verification • SolRecall</title>
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/img/icon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/apple-touch-icon.png">
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#000000">
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
  <style>
    .otp-input:focus {
      border-color: #ffffff;
      box-shadow: 0 0 0 1px #ffffff;
      outline: none;
    }
  </style>
</head>
<body class="min-h-screen bg-[#000000] text-zinc-100 flex flex-col justify-between selection:bg-zinc-800 selection:text-white font-body">

  <!-- Main Container -->
  <div class="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-6">

      <!-- Brand Header -->
      <div class="text-center">
        <a href="/" class="inline-flex items-center justify-center gap-3 group">
          <img src="/assets/img/logo.png" alt="SolRecall Logo" class="w-14 h-14 rounded-2xl border border-[#222228] shadow-2xl group-hover:scale-105 transition-transform duration-200 object-cover">
        </a>
      </div>

      <!-- Auth Card -->
      <div class="glass-panel rounded-3xl p-8 border border-[#222228] shadow-2xl relative bg-[#141418]/85 backdrop-blur-xl">

        <!-- Shield Icon Header -->
        <div class="text-center space-y-3 mb-6">
          <div class="mx-auto w-16 h-16 rounded-2xl bg-[#08080a] border border-[#222228] flex items-center justify-center text-white shadow-xl">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          </div>
          <h1 class="text-2xl font-extrabold tracking-tight text-white font-display">
            Two-Factor Verification
          </h1>
          <p class="text-xs text-[#94949e] leading-relaxed max-w-sm mx-auto font-body">
            Enter the 6-digit authentication code from Google Authenticator, Authy, or your TOTP app to access your account.
          </p>
        </div>

        <!-- Inline Error Banner -->
        <div id="errorBanner" class="hidden mb-5 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2 font-body">
          <svg class="w-4 h-4 shrink-0 text-rose-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span id="errorMessage" class="flex-1 font-medium">Invalid or expired authentication code</span>
        </div>

        <!-- 2FA Verification Form -->
        <form id="twoFactorForm" class="space-y-6" onsubmit="event.preventDefault(); handleVerify();">

          <!-- 6-Digit OTP Segmented Input Boxes -->
          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-[#94949e] text-center mb-3 font-nav">
              Authentication Code
            </label>
            <div class="flex justify-between items-center gap-2 sm:gap-2.5 max-w-xs mx-auto" id="otpBoxContainer">
              <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" data-index="0" class="otp-input w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-crypto font-bold rounded-xl bg-[#08080a] border border-[#222228] text-white transition selection:bg-transparent" autofocus>
              <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-index="1" class="otp-input w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-crypto font-bold rounded-xl bg-[#08080a] border border-[#222228] text-white transition selection:bg-transparent">
              <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-index="2" class="otp-input w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-crypto font-bold rounded-xl bg-[#08080a] border border-[#222228] text-white transition selection:bg-transparent">
              <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-index="3" class="otp-input w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-crypto font-bold rounded-xl bg-[#08080a] border border-[#222228] text-white transition selection:bg-transparent">
              <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-index="4" class="otp-input w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-crypto font-bold rounded-xl bg-[#08080a] border border-[#222228] text-white transition selection:bg-transparent">
              <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-index="5" class="otp-input w-11 h-13 sm:w-12 sm:h-14 text-center text-xl sm:text-2xl font-crypto font-bold rounded-xl bg-[#08080a] border border-[#222228] text-white transition selection:bg-transparent">
            </div>
            <!-- Hidden Single Input For Fallback / Keystroke Sync -->
            <input type="hidden" id="fullOtpCode" name="code" value="">
          </div>

          <!-- Submit Button -->
          <button type="submit"
                  id="verifyBtn"
                  class="w-full py-3 px-4 rounded-xl font-bold text-black bg-white hover:bg-zinc-200 border border-white transition-all duration-200 flex items-center justify-center gap-2 text-sm font-cta cursor-pointer shadow-lg shadow-white/5 active:scale-95">
            <span id="verifySpinner" class="hidden spinner-sm !border-t-black !border-black/20"></span>
            <span id="verifyBtnText">Verify & Access Dashboard</span>
          </button>

          <!-- Return to Sign In Option -->
          <div class="text-center pt-2">
            <a href="/two-factor-verify?action=cancel"
               class="inline-flex items-center gap-1.5 text-xs text-[#94949e] hover:text-white transition group font-body">
              <svg class="w-3.5 h-3.5 text-[#71717a] group-hover:text-white transition-transform group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
              </svg>
              <span>Return to Sign In</span>
            </a>
          </div>

        </form>

      </div>

      <!-- Security Notice -->
      <p class="text-center text-[11px] text-[#71717a] font-nav tracking-wider">
        RFC 6238 TOTP AUTHENTICATION &bull; CODES EXPIRE EVERY 30 SECONDS
      </p>

    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container"></div>

  <!-- JavaScript Interaction Logic -->
  <script>
    const inputs = Array.from(document.querySelectorAll('.otp-input'));
    const fullOtpInput = document.getElementById('fullOtpCode');
    const verifyBtn = document.getElementById('verifyBtn');
    const verifySpinner = document.getElementById('verifySpinner');
    const verifyBtnText = document.getElementById('verifyBtnText');
    const errorBanner = document.getElementById('errorBanner');
    const errorMessage = document.getElementById('errorMessage');

    // Auto-focus first input
    if (inputs[0]) {
      inputs[0].focus();
    }

    function getCombinedCode() {
      return inputs.map(i => i.value).join('');
    }

    function syncFullOtp() {
      const code = getCombinedCode();
      if (fullOtpInput) fullOtpInput.value = code;
      return code;
    }

    // Set up numeric filtering and auto-advance
    inputs.forEach((input, idx) => {
      // Handle keydown for Backspace navigation
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace') {
          if (!input.value && idx > 0) {
            inputs[idx - 1].focus();
            inputs[idx - 1].value = '';
            syncFullOtp();
            e.preventDefault();
          } else {
            input.value = '';
            syncFullOtp();
          }
        } else if (e.key === 'ArrowLeft' && idx > 0) {
          inputs[idx - 1].focus();
        } else if (e.key === 'ArrowRight' && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
        }
      });

      // Filter non-numeric characters and advance
      input.addEventListener('input', (e) => {
        const val = input.value.replace(/[^0-9]/g, '');
        input.value = val ? val[val.length - 1] : '';
        syncFullOtp();

        if (input.value && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
        }

        // Auto-submit when all 6 digits entered
        if (idx === inputs.length - 1 && getCombinedCode().length === 6) {
          handleVerify();
        }
      });

      // Handle paste of full 6-digit code
      input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        const digits = pasted.replace(/[^0-9]/g, '').slice(0, 6);
        if (!digits) return;

        digits.split('').forEach((char, i) => {
          if (inputs[i]) {
            inputs[i].value = char;
          }
        });

        syncFullOtp();
        const nextIdx = Math.min(digits.length, inputs.length - 1);
        inputs[nextIdx].focus();

        if (digits.length === 6) {
          handleVerify();
        }
      });
    });

    async function handleVerify() {
      const code = syncFullOtp();
      hideError();

      if (code.length !== 6) {
        showError('Please enter all 6 digits of your authentication code.');
        inputs.find(i => !i.value)?.focus();
        return;
      }

      setLoading(true);

      try {
        const res = await fetch('/api/auth/2fa/verify-login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ code })
        });

        const data = await res.json();

        if (data.success) {
          const token = data.data?.session_token || data.session_token;
          if (token) {
            try { localStorage.setItem('solrecall_session_token', token); } catch (e) {}
          }
          showToast('Verification successful! Redirecting to dashboard...', 'success');
          const destination = data.redirect || (data.data && data.data.redirect) || '/dashboard';
          setTimeout(() => {
            window.location.href = destination;
          }, 300);
        } else {
          const err = data.error || data.message || 'Invalid or expired authentication code';
          showError(err);
          showToast(err, 'error');
          // Clear inputs and refocus first box
          inputs.forEach(i => i.value = '');
          syncFullOtp();
          if (inputs[0]) inputs[0].focus();
        }
      } catch (err) {
        const msg = err.message || 'Network error verifying authentication code. Please try again.';
        showError(msg);
        showToast(msg, 'error');
      } finally {
        setLoading(false);
      }
    }

    function setLoading(isLoading) {
      if (verifyBtn) verifyBtn.disabled = isLoading;
      if (verifySpinner) {
        if (isLoading) verifySpinner.classList.remove('hidden');
        else verifySpinner.classList.add('hidden');
      }
      if (verifyBtnText) {
        verifyBtnText.textContent = isLoading ? 'Verifying...' : 'Verify & Access Dashboard';
      }
    }

    function showError(msg) {
      if (errorMessage) errorMessage.textContent = msg;
      if (errorBanner) errorBanner.classList.remove('hidden');
    }

    function hideError() {
      if (errorBanner) errorBanner.classList.add('hidden');
    }

    function showToast(msg, type = 'info') {
      const c = document.getElementById('toast-container');
      if (!c) return;
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
