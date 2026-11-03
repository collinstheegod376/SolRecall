/**
 * Undo Window Dashboard - Core JavaScript Controller
 * Handles State, Real-time Countdown Ticker, Draining Progress Bars, Modals, Forms & Toasts
 */

(function () {
  'use strict';

  // Application State
  const AppState = {
    user: null,
    balances: {
      SOL: { available: 14.8520, escrow: 0.0000, price: 184.50 },
      USDC: { available: 420.50, escrow: 0.00, price: 1.00 },
      USDT: { available: 150.00, escrow: 0.00, price: 1.00 }
    },
    thresholds: {
      SOL: 2.0,
      USDC: 100.0,
      USDT: 100.0
    },
    settings: {
      undoWindowMinutes: 10
    },
    pendingTransfers: [],
    historyTransfers: [],
    currentHistoryFilter: 'all',
    historySearchTerm: '',
    selectedCancelTransferId: null,
    tickerInterval: null,
    adminStats: {
      hotWalletSol: 450.28,
      hotWalletUsdc: 15420.00,
      totalEscrowUsd: 0.00,
      totalUsers: 142,
      cronLastRun: '32s ago',
      cronStatus: 'Active'
    }
  };

  // Toast Notification System
  function showToast(message, type = 'info', duration = 3500) {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let iconSvg = '';
    if (type === 'success') {
      iconSvg = `<svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
    } else if (type === 'error') {
      iconSvg = `<svg class="w-5 h-5 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>`;
    } else if (type === 'warning') {
      iconSvg = `<svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>`;
    } else {
      iconSvg = `<svg class="w-5 h-5 text-purple-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
    }

    toast.innerHTML = `
      ${iconSvg}
      <div class="flex-1 text-sm font-medium leading-tight">${message}</div>
      <button type="button" class="text-slate-400 hover:text-slate-200 text-xs ml-2" onclick="this.parentElement.remove()">✕</button>
    `;

    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }
  window.showToast = showToast;

  // Copy to Clipboard Helper
  function copyToClipboard(text, label = 'Address') {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => {
        showToast(`${label} copied to clipboard!`, 'success');
      }).catch(() => fallbackCopy(text, label));
    } else {
      fallbackCopy(text, label);
    }
  }
  function fallbackCopy(text, label) {
    const input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    try {
      document.execCommand('copy');
      showToast(`${label} copied to clipboard!`, 'success');
    } catch (e) {
      showToast('Could not copy to clipboard', 'error');
    }
    document.body.removeChild(input);
  }
  window.copyToClipboard = copyToClipboard;

  // Truncate Solana Address
  function truncateAddress(addr, front = 4, back = 4) {
    if (!addr) return '';
    if (addr.length <= front + back + 3) return addr;
    return `${addr.slice(0, front)}...${addr.slice(-back)}`;
  }
  window.truncateAddress = truncateAddress;

  // Format Token Value
  function formatAmount(val, maxDecimals = 4) {
    const num = parseFloat(val) || 0;
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: maxDecimals });
  }

  // Smart multi-unit countdown formatter (seconds to Years, Days, Hours, Minutes, Seconds)
  function formatDuration(seconds) {
    if (seconds <= 0) return '00:00';

    const sec = Math.floor(seconds);
    const minute = 60;
    const hour = 3600;
    const day = 86400;
    const year = 31536000; // 365 days

    if (sec >= year) {
      const years = Math.floor(sec / year);
      const remainingDays = Math.floor((sec % year) / day);
      return remainingDays > 0 ? `${years}y ${remainingDays}d` : `${years}y`;
    }

    if (sec >= day) {
      const days = Math.floor(sec / day);
      const remainingHours = Math.floor((sec % day) / hour);
      return remainingHours > 0 ? `${days}d ${remainingHours}h` : `${days}d`;
    }

    if (sec >= hour) {
      const hours = Math.floor(sec / hour);
      const remainingMins = Math.floor((sec % hour) / minute);
      const remainingSecs = sec % minute;
      return `${hours}h ${remainingMins.toString().padStart(2, '0')}m ${remainingSecs.toString().padStart(2, '0')}s`;
    }

    const mins = Math.floor(sec / minute);
    const secs = sec % minute;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }

  // Format minutes into clean human readable duration
  function formatHumanDuration(minutes) {
    const mins = parseInt(minutes, 10) || 0;
    if (mins >= 525600) {
      const y = Math.round((mins / 525600) * 10) / 10;
      const cleanY = (y === Math.floor(y)) ? Math.floor(y) : y;
      return `${cleanY} year${cleanY !== 1 ? 's' : ''}`;
    }
    if (mins >= 43200) {
      const m = Math.round((mins / 43200) * 10) / 10;
      const cleanM = (m === Math.floor(m)) ? Math.floor(m) : m;
      return `${cleanM} month${cleanM !== 1 ? 's' : ''}`;
    }
    if (mins >= 10080) {
      const w = Math.round((mins / 10080) * 10) / 10;
      const cleanW = (w === Math.floor(w)) ? Math.floor(w) : w;
      return `${cleanW} week${cleanW !== 1 ? 's' : ''}`;
    }
    if (mins >= 1440) {
      const d = Math.round((mins / 1440) * 10) / 10;
      const cleanD = (d === Math.floor(d)) ? Math.floor(d) : d;
      return `${cleanD} day${cleanD !== 1 ? 's' : ''}`;
    }
    if (mins >= 60) {
      const h = Math.round((mins / 60) * 10) / 10;
      const cleanH = (h === Math.floor(h)) ? Math.floor(h) : h;
      return `${cleanH} hour${cleanH !== 1 ? 's' : ''}`;
    }
    return `${mins} minute${mins !== 1 ? 's' : ''}`;
  }

  // Alias for backward compatibility
  function formatMMSS(seconds) {
    return formatDuration(seconds);
  }

  // API Call Helper
  async function apiFetch(endpoint, options = {}) {
    const res = await fetch(endpoint, {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(options.headers || {})
      },
      ...options
    });

    if (res.status === 401) {
      window.location.href = '/login.php';
      throw new Error('Authentication required. Redirecting to login...');
    }

    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      return data;
    }

    const errorMsg = data.message || data.error || (data.errors ? Object.values(data.errors).flat().join(', ') : `Request failed with status ${res.status}`);
    throw new Error(errorMsg);
  }

  // Mock Data & Handlers for Instant Local Testing
  function mockApiHandler(endpoint, options = {}) {
    const method = (options.method || 'GET').toUpperCase();

    // GET /api/auth/me
    if (endpoint.includes('/api/auth/me')) {
      return {
        success: true,
        user: {
          id: 1,
          username: 'sol_trader_alex',
          email: 'alex@undo.sol',
          solana_deposit_address: '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU',
          wallet_address: sessionStorage.getItem('undo_wallet_address') || '4k3Dyjzvzp8eMZWUXbBCjEvwSkkk59S5iCNLY3QrkX6R',
          two_factor_enabled: 1,
          is_admin: 1,
          created_at: '2026-10-15 10:20:00'
        }
      };
    }

    // GET /api/transfers/balances
    if (endpoint.includes('/api/transfers/balances')) {
      return {
        success: true,
        balances: AppState.balances,
        thresholds: AppState.thresholds,
        settings: AppState.settings
      };
    }

    // GET /api/transfers/pending
    if (endpoint.includes('/api/transfers/pending')) {
      return {
        success: true,
        pending: AppState.pendingTransfers
      };
    }

    // GET /api/transfers/history
    if (endpoint.includes('/api/transfers/history')) {
      return {
        success: true,
        history: AppState.historyTransfers
      };
    }

    // POST /api/transfers/initiate
    if (endpoint.includes('/api/transfers/initiate') && method === 'POST') {
      const body = JSON.parse(options.body || '{}');
      const token = body.token || 'SOL';
      const amount = parseFloat(body.amount);
      const destination = body.destination;
      const note = body.note || '';

      const threshold = AppState.thresholds[token] || 2.0;
      const isSmallTransfer = amount <= threshold;
      const durationMins = AppState.settings.undoWindowMinutes || 10;

      if (isSmallTransfer) {
        // Create pending escrow
        const now = new Date();
        const expiresAt = new Date(now.getTime() + durationMins * 60 * 1000);
        const newTransfer = {
          id: Date.now(),
          token,
          amount,
          destination,
          note,
          created_at: now.toISOString(),
          expires_at: expiresAt.toISOString(),
          total_seconds: durationMins * 60,
          status: 'pending'
        };

        // Deduct available, add to escrow
        AppState.balances[token].available = Math.max(0, AppState.balances[token].available - amount);
        AppState.balances[token].escrow = (AppState.balances[token].escrow || 0) + amount;
        AppState.pendingTransfers.unshift(newTransfer);

        return {
          success: true,
          mode: 'escrow',
          message: `Transfer held in escrow for ${durationMins} minutes. You can cancel anytime.`,
          transfer: newTransfer
        };
      } else {
        // Immediate broadcast
        const now = new Date();
        const mockSignature = '5y6VqP' + Math.random().toString(36).substring(2, 12) + 'SolBroadCast' + Date.now().toString(36);
        AppState.balances[token].available = Math.max(0, AppState.balances[token].available - amount);

        const completedRecord = {
          id: Date.now(),
          token,
          amount,
          destination,
          note,
          created_at: now.toISOString(),
          expires_at: now.toISOString(),
          status: 'completed',
          tx_signature: mockSignature,
          processed_at: now.toISOString()
        };
        AppState.historyTransfers.unshift(completedRecord);

        return {
          success: true,
          mode: 'immediate',
          message: 'Large transfer broadcast immediately on Solana network!',
          tx_signature: mockSignature,
          transfer: completedRecord
        };
      }
    }

    // POST /api/transfers/cancel
    if (endpoint.includes('/api/transfers/cancel') && method === 'POST') {
      const body = JSON.parse(options.body || '{}');
      const transferId = body.transfer_id;

      const idx = AppState.pendingTransfers.findIndex(t => t.id == transferId);
      if (idx !== -1) {
        const transfer = AppState.pendingTransfers[idx];
        AppState.pendingTransfers.splice(idx, 1);

        // Refund balances
        if (AppState.balances[transfer.token]) {
          AppState.balances[transfer.token].escrow = Math.max(0, AppState.balances[transfer.token].escrow - transfer.amount);
          AppState.balances[transfer.token].available += transfer.amount;
        }

        // Add to history
        AppState.historyTransfers.unshift({
          ...transfer,
          status: 'cancelled',
          cancelled_at: new Date().toISOString()
        });

        return {
          success: true,
          message: `Transfer #${transferId} cancelled. Funds refunded to available balance.`
        };
      }
      return { success: false, error: 'Pending transfer not found or already expired' };
    }

    // POST /api/settings
    if (endpoint.includes('/api/settings') && method === 'POST') {
      const body = JSON.parse(options.body || '{}');
      if (body.undoWindowMinutes) {
        AppState.settings.undoWindowMinutes = parseInt(body.undoWindowMinutes, 10);
      }
      if (body.thresholds) {
        AppState.thresholds = { ...AppState.thresholds, ...body.thresholds };
      }
      return { success: true, message: 'Settings updated successfully' };
    }

    // GET /api/auth/2fa/generate
    if (endpoint.includes('/api/auth/2fa/generate')) {
      const secret = 'JBSWY3DPEHPK3PXP';
      return {
        success: true,
        secret: secret,
        qr_code_url: `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=otpauth://totp/UndoWindow:alex@undo.sol?secret=${secret}&issuer=UndoWindow`
      };
    }

    // POST /api/auth/2fa/enable
    if (endpoint.includes('/api/auth/2fa/enable')) {
      return { success: true, message: '2FA authentication successfully activated!' };
    }

    // POST /api/auth/export-key
    if (endpoint.includes('/api/auth/export-key')) {
      return {
        success: true,
        private_key: '5MKnzJ...[ENCRYPTED_CUSTODIAL_KEYPAIR_MOCK_BASE58]...3qT78mYwQz9x',
        deposit_address: AppState.user ? AppState.user.solana_deposit_address : '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU'
      };
    }

    return { success: true };
  }

  // Seed sample transactions if empty
  function seedSampleData() {
    if (AppState.pendingTransfers.length === 0) {
      const now = Date.now();
      AppState.pendingTransfers = [
        {
          id: 101,
          token: 'SOL',
          amount: 0.75,
          destination: '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
          note: 'Freelance UI audit payment',
          created_at: new Date(now - 3.5 * 60 * 1000).toISOString(),
          expires_at: new Date(now + 6.5 * 60 * 1000).toISOString(),
          total_seconds: 10 * 60,
          status: 'pending'
        },
        {
          id: 102,
          token: 'USDC',
          amount: 50.00,
          destination: 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
          note: 'Subscription renewal',
          created_at: new Date(now - 7 * 60 * 1000).toISOString(),
          expires_at: new Date(now + 3 * 60 * 1000).toISOString(),
          total_seconds: 10 * 60,
          status: 'pending'
        }
      ];

      // Reflect initial escrow in balances
      AppState.balances.SOL.escrow = 0.75;
      AppState.balances.USDC.escrow = 50.00;
    }

    if (AppState.historyTransfers.length === 0) {
      const now = Date.now();
      AppState.historyTransfers = [
        {
          id: 98,
          token: 'SOL',
          amount: 1.20,
          destination: 'HN7cABqLq46Es1jh92dQQisAq662SmxELLLsHHe4YWrH',
          note: 'Domain registration refund test',
          created_at: new Date(now - 25 * 60 * 1000).toISOString(),
          expires_at: new Date(now - 15 * 60 * 1000).toISOString(),
          status: 'completed',
          tx_signature: '4hG8eB73uNkC3p7jQw1xYz9mRtVkL2nPsQ8dFw6aJmB3kC5tXy8pQ1rT4wE7yU9i',
          processed_at: new Date(now - 15 * 60 * 1000).toISOString()
        },
        {
          id: 97,
          token: 'USDT',
          amount: 35.00,
          destination: 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
          note: 'Sent to wrong address (saved by undo!)',
          created_at: new Date(now - 45 * 60 * 1000).toISOString(),
          expires_at: new Date(now - 35 * 60 * 1000).toISOString(),
          status: 'cancelled',
          cancelled_at: new Date(now - 42 * 60 * 1000).toISOString()
        },
        {
          id: 96,
          token: 'SOL',
          amount: 5.50,
          destination: '6b4k3Dyjzvzp8eMZWUXbBCjEvwSkkk59S5iCNLY3QrkX',
          note: 'Large OTC transfer (Direct broadcast)',
          created_at: new Date(now - 3 * 3600 * 1000).toISOString(),
          expires_at: new Date(now - 3 * 3600 * 1000).toISOString(),
          status: 'completed',
          tx_signature: '3kPxR8vL2nM7sQ4wE1yU9iJ6aTmB5kC8tXy2pQ7rT3wE6yU1iH4gG9eB5uNkC2p8',
          processed_at: new Date(now - 3 * 3600 * 1000).toISOString()
        }
      ];
    }
  }

  // Render Header & Balances
  function renderHeaderAndBalances() {
    const user = AppState.user;
    if (user) {
      const depositEl = document.getElementById('userDepositAddress');
      if (depositEl) depositEl.textContent = truncateAddress(user.solana_deposit_address, 6, 6);

      const fullDepositEl = document.getElementById('userDepositAddressFull');
      if (fullDepositEl) fullDepositEl.textContent = user.solana_deposit_address;

      const usernameEl = document.getElementById('headerUsername');
      if (usernameEl) usernameEl.textContent = user.username || user.email || 'Solana User';

      const emailEl = document.getElementById('headerEmail');
      if (emailEl) emailEl.textContent = user.email || '';

      const twoFaBadge = document.getElementById('twoFaHeaderBadge');
      if (twoFaBadge) {
        twoFaBadge.innerHTML = user.two_factor_enabled
          ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
               <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> 2FA Secured
             </span>`
          : `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
               <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> 2FA Inactive
             </span>`;
      }
    }

    // Render Balance Cards
    const balances = AppState.balances;
    let totalEscrowUsd = 0;

    ['SOL', 'USDC', 'USDT'].forEach(tok => {
      const data = balances[tok] || { available: 0, escrow: 0, price: 1 };
      const availEl = document.getElementById(`bal-avail-${tok.toLowerCase()}`);
      const escrowEl = document.getElementById(`bal-escrow-${tok.toLowerCase()}`);
      const fiatEl = document.getElementById(`bal-fiat-${tok.toLowerCase()}`);

      if (availEl) availEl.textContent = formatAmount(data.available, tok === 'SOL' ? 4 : 2);
      if (escrowEl) escrowEl.textContent = formatAmount(data.escrow, tok === 'SOL' ? 4 : 2);

      const totalTok = (data.available || 0) + (data.escrow || 0);
      const fiatTotal = totalTok * (data.price || 1);
      if (fiatEl) fiatEl.textContent = `≈ $${formatAmount(fiatTotal, 2)}`;

      totalEscrowUsd += (data.escrow || 0) * (data.price || 1);
    });

    // Total Escrow Shield Pill
    const totalEscrowEl = document.getElementById('totalEscrowShielded');
    if (totalEscrowEl) {
      totalEscrowEl.textContent = `$${formatAmount(totalEscrowUsd, 2)}`;
    }

    // Pending Count Badge in Header
    const pendingCountBadge = document.getElementById('pendingTransfersCount');
    if (pendingCountBadge) {
      const count = AppState.pendingTransfers.length;
      pendingCountBadge.textContent = count;
      pendingCountBadge.className = count > 0
        ? 'inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-amber-300 bg-amber-500/20 border border-amber-500/40 rounded-full'
        : 'hidden';
    }
  }

  // Render Pending Transfers Section
  function renderPendingTransfers() {
    const container = document.getElementById('pendingTransfersContainer');
    const emptyState = document.getElementById('pendingTransfersEmpty');
    if (!container) return;

    if (AppState.pendingTransfers.length === 0) {
      container.innerHTML = '';
      if (emptyState) emptyState.classList.remove('hidden');
      return;
    }

    if (emptyState) emptyState.classList.add('hidden');

    const tokenLogos = {
      SOL: { url: 'https://cryptologos.cc/logos/solana-sol-logo.png', local: '/assets/img/sol.png' },
      USDC: { url: 'https://cryptologos.cc/logos/usd-coin-usdc-logo.png', local: '/assets/img/usdc.png' },
      USDT: { url: 'https://cryptologos.cc/logos/tether-usdt-logo.png', local: '/assets/img/usdt.png' }
    };

    const html = AppState.pendingTransfers.map(t => {
      const remaining = calculateRemainingSeconds(t);
      const total = t.total_seconds || (AppState.settings.undoWindowMinutes * 60);
      const percent = Math.min(100, Math.max(0, (remaining / total) * 100));
      const isUrgent = remaining <= 60;
      const timeFormatted = formatMMSS(remaining);
      const tLogo = tokenLogos[t.token] || tokenLogos.SOL;

      return `
        <div id="pending-card-${t.id}" class="glass-panel glass-panel-hover rounded-2xl p-5 relative overflow-hidden transition-all duration-300 border-l-4 ${isUrgent ? 'border-l-rose-500' : 'border-l-amber-500'}">
          <!-- Top Row: Token & Amount + Countdown Timer -->
          <div class="flex items-start justify-between gap-4 mb-3">
            <div class="flex items-center gap-3">
              <div class="relative shrink-0">
                <img src="${tLogo.url}" onerror="this.src='${tLogo.local}'" alt="${t.token}" class="w-8 h-8 rounded-full object-contain p-0.5 bg-black/50 border border-white/10">
              </div>
              <div>
                <div class="text-xl font-bold text-white tracking-tight flex items-baseline gap-1.5">
                  ${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} <span class="text-xs font-semibold text-slate-300">${t.token}</span>
                </div>
                ${t.note ? `<p class="text-xs text-slate-400 truncate max-w-xs">${escapeHtml(t.note)}</p>` : ''}
              </div>
            </div>

            <!-- Timer pill (clean, without blinking/loading circles) -->
            <div class="text-right">
              <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-mono font-bold ${isUrgent ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-amber-500/10 text-amber-300 border border-amber-500/30'}">
                <svg class="w-3.5 h-3.5 ${isUrgent ? 'text-rose-400' : 'text-amber-400'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span id="timer-text-${t.id}">${timeFormatted}</span>
              </div>
              <div class="text-[11px] text-slate-400 mt-1">Undo Window Active</div>
            </div>
          </div>

          <!-- Draining Animated Progress Bar -->
          <div class="w-full bg-slate-800/80 rounded-full h-2 mb-4 overflow-hidden p-0.5 border border-white/5">
            <div id="progress-bar-${t.id}"
                 class="progress-bar-draining h-full rounded-full ${isUrgent ? 'urgent bg-gradient-to-r from-rose-500 to-amber-500' : 'bg-gradient-to-r from-amber-400 to-amber-500'}"
                 style="width: ${percent}%;"></div>
          </div>

          <!-- Bottom Row: Recipient & Cancel Button -->
          <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-white/5">
            <div class="flex items-center gap-2 text-xs text-slate-300">
              <span class="text-slate-400">To:</span>
              <span class="font-mono bg-slate-900/80 px-2 py-1 rounded border border-white/5" title="${t.destination}">
                ${truncateAddress(t.destination, 6, 6)}
              </span>
              <button type="button" class="text-slate-400 hover:text-white p-1 rounded hover:bg-white/5 transition" title="Copy Address" onclick="copyToClipboard('${t.destination}', 'Recipient address')">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
              </button>
            </div>

            <!-- Prominent Cancel Transfer Button -->
            <button type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-rose-500/10 hover:bg-rose-500/25 text-rose-300 hover:text-white border border-rose-500/30 hover:border-rose-500/60 transition shadow-sm"
                    onclick="openCancelModal(${t.id})">
              <svg class="w-3.5 h-3.5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
              Cancel Transfer (Undo)
            </button>
          </div>
        </div>
      `;
    }).join('');

    container.innerHTML = html;
  }

  // Calculate remaining seconds for a transfer with UTC normalization
  function calculateRemainingSeconds(transfer) {
    if (typeof transfer.seconds_remaining === 'number' && !transfer._expiresMs) {
      transfer._expiresMs = Date.now() + (transfer.seconds_remaining * 1000);
    }
    if (transfer._expiresMs) {
      return Math.max(0, Math.floor((transfer._expiresMs - Date.now()) / 1000));
    }
    if (!transfer.expires_at) return 0;

    let isoStr = String(transfer.expires_at).trim();
    if (!isoStr.endsWith('Z') && isoStr.indexOf('+') === -1) {
      isoStr = isoStr.replace(' ', 'T') + 'Z';
    }
    const expiresMs = new Date(isoStr).getTime();
    if (isNaN(expiresMs)) return 0;
    return Math.max(0, Math.floor((expiresMs - Date.now()) / 1000));
  }

  // Real-time Countdown Ticker (1-second tick)
  function startTicker() {
    if (AppState.tickerInterval) clearInterval(AppState.tickerInterval);

    AppState.tickerInterval = setInterval(() => {
      let expiredFound = false;

      AppState.pendingTransfers.forEach(t => {
        const remaining = calculateRemainingSeconds(t);
        const total = t.total_seconds || (AppState.settings.undoWindowMinutes * 60);
        const percent = Math.min(100, Math.max(0, (remaining / total) * 100));
        const isUrgent = remaining <= 60;

        const timerTextEl = document.getElementById(`timer-text-${t.id}`);
        const progressBarEl = document.getElementById(`progress-bar-${t.id}`);

        if (timerTextEl) {
          timerTextEl.textContent = formatMMSS(remaining);
        }

        if (progressBarEl) {
          progressBarEl.style.width = `${percent}%`;
          if (isUrgent) {
            progressBarEl.classList.add('urgent');
          } else {
            progressBarEl.classList.remove('urgent');
          }
        }

        if (remaining <= 0 && !t._markedExpired) {
          t._markedExpired = true;
          expiredFound = true;
        }
      });

      if (expiredFound) {
        handleExpiredTransfers();
      }
    }, 1000);
  }

  // Handle expired transfer automatic broadcast via server polling
  async function handleExpiredTransfers() {
    showToast('Undo window expired. Broadcaster processing on Solana...', 'info');

    setTimeout(async () => {
      try {
        const [balRes, pendRes, histRes] = await Promise.all([
          apiFetch('/api/transfers/balances'),
          apiFetch('/api/transfers/pending'),
          apiFetch('/api/transfers/history')
        ]);

        if (balRes && balRes.success) {
          const payload = balRes.data || balRes;
          if (payload.balances) AppState.balances = payload.balances;
        }

        if (pendRes && pendRes.success) {
          const payload = pendRes.data || pendRes;
          AppState.pendingTransfers = payload.transfers || payload.pending || [];
        }

        if (histRes && histRes.success) {
          const payload = histRes.data || histRes;
          AppState.historyTransfers = payload.transfers || payload.history || [];
        }

        renderHeaderAndBalances();
        renderPendingTransfers();
        renderHistoryTable();
      } catch (err) {
        console.warn('Broadcaster sync notice:', err.message);
      }
    }, 2500);
  }

  // Dynamic Transfer Form Feedback
  function setupTransferForm() {
    const form = document.getElementById('newTransferForm');
    if (!form) return;

    const tokenSelect = document.getElementById('transferToken');
    const amountInput = document.getElementById('transferAmount');
    const destInput = document.getElementById('transferDestination');
    const presetSelect = document.getElementById('transferWindowPreset');
    const customBox = document.getElementById('customDurationBox');
    const customVal = document.getElementById('customDurationValue');
    const customUnit = document.getElementById('customDurationUnit');
    const noticeBox = document.getElementById('transferNoticeBox');
    const noticeText = document.getElementById('transferNoticeText');
    const maxBtn = document.getElementById('maxAmountBtn');
    const percentBtns = document.querySelectorAll('.percent-btn');

    function getSelectedWindowMinutes() {
      if (!presetSelect) return AppState.settings.undoWindowMinutes || 10;
      if (presetSelect.value === 'custom') {
        const val = Math.max(1, parseInt(customVal ? customVal.value : 1, 10) || 1);
        const unit = customUnit ? customUnit.value : 'minutes';
        const mults = {
          minutes: 1,
          hours: 60,
          days: 1440,
          weeks: 10080,
          months: 43200,
          years: 525600
        };
        return val * (mults[unit] || 1);
      }
      return parseInt(presetSelect.value, 10) || (AppState.settings.undoWindowMinutes || 10);
    }

    function updateNotice() {
      const token = tokenSelect ? tokenSelect.value : 'SOL';
      const amount = parseFloat(amountInput ? amountInput.value : 0) || 0;
      const threshold = AppState.thresholds[token] || 2.0;
      const windowMins = getSelectedWindowMinutes();
      const humanWin = formatHumanDuration(windowMins);

      if (noticeBox && noticeText) {
        noticeBox.classList.remove('hidden');
        if (amount > 0 && amount <= threshold) {
          noticeBox.className = 'rounded-xl p-3 bg-amber-500/10 border border-amber-500/30 text-amber-200 text-xs flex items-start gap-2.5';
          noticeText.innerHTML = `<strong>🛡️ Protected by ${humanWin} Escrow Hold:</strong> Transfer is held in escrow. You can review or cancel anytime before the timer runs out.`;
        } else if (amount > threshold) {
          noticeBox.className = 'rounded-xl p-3 bg-purple-500/10 border border-purple-500/30 text-purple-200 text-xs flex items-start gap-2.5';
          noticeText.innerHTML = `<strong>⚡ Instant Broadcast:</strong> Transfer amount (${amount} ${token}) exceeds the small transfer threshold of ${threshold} ${token}. Will be broadcast immediately on Solana.`;
        } else {
          noticeBox.className = 'rounded-xl p-3 bg-slate-800/60 border border-white/5 text-slate-400 text-xs flex items-start gap-2.5';
          noticeText.innerHTML = `<strong>ℹ️ Small Transfer Protection:</strong> Transfers up to ${threshold} ${token} are held in a ${humanWin} escrow window before broadcasting.`;
        }
      }

      // Update available hint text
      const availHint = document.getElementById('availableAmountHint');
      if (availHint) {
        const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
        availHint.textContent = `Available: ${formatAmount(avail, token === 'SOL' ? 4 : 2)} ${token}`;
      }

      // Update form token logo
      const formTokenLogo = document.getElementById('formTokenLogo');
      if (formTokenLogo) {
        const logoMap = {
          SOL: { url: 'https://cryptologos.cc/logos/solana-sol-logo.png', local: '/assets/img/sol.png' },
          USDC: { url: 'https://cryptologos.cc/logos/usd-coin-usdc-logo.png', local: '/assets/img/usdc.png' },
          USDT: { url: 'https://cryptologos.cc/logos/tether-usdt-logo.png', local: '/assets/img/usdt.png' }
        };
        const info = logoMap[token] || logoMap.SOL;
        formTokenLogo.src = info.url;
        formTokenLogo.onerror = () => { formTokenLogo.src = info.local; };
      }
    }

    if (tokenSelect) tokenSelect.addEventListener('change', updateNotice);
    if (amountInput) amountInput.addEventListener('input', updateNotice);
    if (presetSelect) {
      presetSelect.addEventListener('change', () => {
        if (presetSelect.value === 'custom') {
          if (customBox) customBox.classList.remove('hidden');
        } else {
          if (customBox) customBox.classList.add('hidden');
        }
        updateNotice();
      });
    }
    if (customVal) customVal.addEventListener('input', updateNotice);
    if (customUnit) customUnit.addEventListener('change', updateNotice);

    // MAX button
    if (maxBtn) {
      maxBtn.addEventListener('click', () => {
        const token = tokenSelect ? tokenSelect.value : 'SOL';
        const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
        if (amountInput) {
          amountInput.value = avail > 0 ? avail : '';
          updateNotice();
        }
      });
    }

    // Percentage buttons (25%, 50%, 75%, 100%)
    percentBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const pct = parseFloat(btn.dataset.percent) || 100;
        const token = tokenSelect ? tokenSelect.value : 'SOL';
        const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
        const calc = (avail * (pct / 100));
        if (amountInput) {
          amountInput.value = token === 'SOL' ? calc.toFixed(4) : calc.toFixed(2);
          updateNotice();
        }
      });
    });

    // Form Submit
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const token = tokenSelect.value;
      const amount = parseFloat(amountInput.value);
      const destination = destInput.value.trim();
      const note = (document.getElementById('transferNote')?.value || '').trim();
      const submitBtn = document.getElementById('submitTransferBtn');
      const submitBtnText = document.getElementById('submitTransferBtnText');
      const submitBtnSpinner = document.getElementById('submitTransferBtnSpinner');

      // Validation
      if (!destination) {
        showToast('Please enter a destination Solana address', 'warning');
        destInput.focus();
        return;
      }
      if (destination.length < 32 || destination.length > 44) {
        showToast('Solana address must be between 32 and 44 characters', 'error');
        destInput.focus();
        return;
      }
      if (isNaN(amount) || amount <= 0) {
        showToast('Please enter a valid amount greater than 0', 'warning');
        amountInput.focus();
        return;
      }
      const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
      if (amount > avail) {
        showToast(`Insufficient ${token} balance (Available: ${formatAmount(avail)} ${token})`, 'error');
        return;
      }

      // Button loading state
      if (submitBtn) submitBtn.disabled = true;
      if (submitBtnSpinner) submitBtnSpinner.classList.remove('hidden');
      if (submitBtnText) submitBtnText.textContent = 'Processing Transfer...';

      const windowMinutes = getSelectedWindowMinutes();

      try {
        const res = await apiFetch('/api/transfers/initiate', {
          method: 'POST',
          body: JSON.stringify({ token, amount, destination, note, window_minutes: windowMinutes })
        });

        if (res.success) {
          showToast(res.message || 'Transfer submitted!', 'success');
          // Reset form
          form.reset();
          updateNotice();
          // Reload real server state
          try {
            const [bRes, pRes, hRes] = await Promise.all([
              apiFetch('/api/transfers/balances'),
              apiFetch('/api/transfers/pending'),
              apiFetch('/api/transfers/history')
            ]);
            if (bRes && bRes.success) {
              const p = bRes.data || bRes;
              if (p.balances) AppState.balances = p.balances;
            }
            if (pRes && pRes.success) {
              const p = pRes.data || pRes;
              AppState.pendingTransfers = p.transfers || p.pending || [];
            }
            if (hRes && hRes.success) {
              const p = hRes.data || hRes;
              AppState.historyTransfers = p.transfers || p.history || [];
            }
          } catch (e) {
            console.warn('Sync notice:', e.message);
          }
          renderHeaderAndBalances();
          renderPendingTransfers();
          renderHistoryTable();
        } else {
          showToast(res.error || 'Failed to initiate transfer', 'error');
        }
      } catch (err) {
        showToast(err.message || 'Network error occurred', 'error');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (submitBtnSpinner) submitBtnSpinner.classList.add('hidden');
        if (submitBtnText) submitBtnText.textContent = 'Initiate Transfer';
      }
    });

    updateNotice();
  }

  // Cancel Transfer Modal Handlers
  function openCancelModal(transferId) {
    const transfer = AppState.pendingTransfers.find(t => t.id == transferId);
    if (!transfer) return;

    AppState.selectedCancelTransferId = transferId;
    const modal = document.getElementById('cancelModal');
    const detailsEl = document.getElementById('cancelModalDetails');

    if (detailsEl) {
      detailsEl.innerHTML = `
        <div class="bg-slate-900/90 rounded-xl p-4 border border-white/5 space-y-2 text-left mb-4">
          <div class="flex justify-between text-xs">
            <span class="text-slate-400">Token & Amount:</span>
            <span class="font-bold text-white">${formatAmount(transfer.amount)} ${transfer.token}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-400">Recipient:</span>
            <span class="font-mono text-slate-300">${truncateAddress(transfer.destination, 6, 6)}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-400">Escrow Hold Remaining:</span>
            <span class="text-amber-400 font-mono font-bold">${formatMMSS(calculateRemainingSeconds(transfer))}</span>
          </div>
        </div>
      `;
    }

    if (modal) modal.classList.remove('hidden');
  }
  window.openCancelModal = openCancelModal;

  function closeCancelModal() {
    AppState.selectedCancelTransferId = null;
    const modal = document.getElementById('cancelModal');
    if (modal) modal.classList.add('hidden');
  }
  window.closeCancelModal = closeCancelModal;

  async function confirmCancelTransfer() {
    const transferId = AppState.selectedCancelTransferId;
    if (!transferId) return;

    const confirmBtn = document.getElementById('confirmCancelBtn');
    if (confirmBtn) {
      confirmBtn.disabled = true;
      confirmBtn.innerHTML = `<span class="spinner-sm inline-block mr-1"></span> Cancelling...`;
    }

    try {
      const res = await apiFetch('/api/transfers/cancel', {
        method: 'POST',
        body: JSON.stringify({ transfer_id: transferId })
      });

      if (res.success) {
        showToast(res.message || 'Transfer cancelled! Funds returned to available balance.', 'success');
        closeCancelModal();
        try {
          const [bRes, pRes, hRes] = await Promise.all([
            apiFetch('/api/transfers/balances'),
            apiFetch('/api/transfers/pending'),
            apiFetch('/api/transfers/history')
          ]);
          if (bRes && bRes.success) {
            const p = bRes.data || bRes;
            if (p.balances) AppState.balances = p.balances;
          }
          if (pRes && pRes.success) {
            const p = pRes.data || pRes;
            AppState.pendingTransfers = p.transfers || p.pending || [];
          }
          if (hRes && hRes.success) {
            const p = hRes.data || hRes;
            AppState.historyTransfers = p.transfers || p.history || [];
          }
        } catch (e) {
          console.warn('Sync notice:', e.message);
        }
        renderHeaderAndBalances();
        renderPendingTransfers();
        renderHistoryTable();
      } else {
        showToast(res.error || 'Failed to cancel transfer', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Cancellation failed', 'error');
    } finally {
      if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = `Yes, Cancel Transfer`;
      }
    }
  }
  window.confirmCancelTransfer = confirmCancelTransfer;

  // History Table Rendering & Filter
  function renderHistoryTable() {
    const tbody = document.getElementById('historyTableBody');
    const emptyRow = document.getElementById('historyEmptyRow');
    if (!tbody) return;

    const filter = AppState.currentHistoryFilter;
    const search = AppState.historySearchTerm.toLowerCase();

    const filtered = AppState.historyTransfers.filter(item => {
      // Filter status
      if (filter !== 'all' && item.status !== filter) return false;
      // Search
      if (search) {
        const matchDest = (item.destination || '').toLowerCase().includes(search);
        const matchSig = (item.tx_signature || '').toLowerCase().includes(search);
        const matchNote = (item.note || '').toLowerCase().includes(search);
        const matchTok = (item.token || '').toLowerCase().includes(search);
        if (!matchDest && !matchSig && !matchNote && !matchTok) return false;
      }
      return true;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = '';
      if (emptyRow) emptyRow.classList.remove('hidden');
      return;
    }

    if (emptyRow) emptyRow.classList.add('hidden');

    tbody.innerHTML = filtered.map(t => {
      // Status badge
      let badgeClass = 'badge-completed';
      let statusLabel = 'Completed';
      let icon = `<svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;

      if (t.status === 'cancelled') {
        badgeClass = 'badge-cancelled';
        statusLabel = 'Cancelled';
        icon = `<svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>`;
      } else if (t.status === 'failed') {
        badgeClass = 'badge-failed';
        statusLabel = 'Failed';
        icon = `<svg class="w-3.5 h-3.5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>`;
      } else if (t.status === 'pending') {
        badgeClass = 'badge-pending';
        statusLabel = 'Pending Escrow';
        icon = `<svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
      } else if (t.status === 'processing') {
        badgeClass = 'badge-processing';
        statusLabel = 'Broadcasting';
        icon = `<span class="spinner-sm inline-block"></span>`;
      }

      const dateStr = t.created_at ? new Date(t.created_at).toLocaleString('en-US', {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
      }) : 'Recent';

      // Explorer link
      let explorerHtml = `<span class="text-slate-500 text-xs">—</span>`;
      if (t.tx_signature) {
        const explorerUrl = `https://solscan.io/tx/${t.tx_signature}?cluster=devnet`;
        explorerHtml = `
          <a href="${explorerUrl}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs text-purple-400 hover:text-purple-300 font-mono transition" title="View on Solscan">
            <span>${truncateAddress(t.tx_signature, 4, 4)}</span>
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
          </a>
        `;
      }

      const tLogo = tokenLogos[t.token] || tokenLogos.SOL;

      return `
        <tr class="border-b border-white/5 hover:bg-white/[0.02] transition text-sm">
          <td class="py-3.5 px-4 text-slate-400 text-xs whitespace-nowrap">${dateStr}</td>
          <td class="py-3.5 px-4">
            <div class="flex items-center gap-1.5 font-semibold text-white">
              <img src="${tLogo.url}" onerror="this.src='${tLogo.local}'" alt="${t.token}" class="w-4 h-4 rounded-full object-contain shrink-0">
              <span>${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} <span class="text-xs text-slate-400 font-normal">${t.token}</span></span>
            </div>
          </td>
          <td class="py-3.5 px-4 font-mono text-xs text-slate-300">
            <div class="flex items-center gap-1.5">
              <span>${truncateAddress(t.destination, 4, 4)}</span>
              <button type="button" class="text-slate-400 hover:text-white p-0.5 rounded transition" onclick="copyToClipboard('${t.destination}', 'Address')">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
              </button>
            </div>
          </td>
          <td class="py-3.5 px-4 text-slate-400 text-xs truncate max-w-[140px]">${t.note ? escapeHtml(t.note) : '<span class="text-slate-600">—</span>'}</td>
          <td class="py-3.5 px-4">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${badgeClass}">
              ${icon}
              ${statusLabel}
            </span>
          </td>
          <td class="py-3.5 px-4 whitespace-nowrap">${explorerHtml}</td>
        </tr>
      `;
    }).join('');
  }

  // Setup History Filter & Search Listeners
  function setupHistoryListeners() {
    const filterBtns = document.querySelectorAll('.history-filter-btn');
    filterBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        filterBtns.forEach(b => {
          b.classList.remove('bg-purple-600', 'text-white');
          b.classList.add('bg-slate-800/80', 'text-slate-400');
        });
        btn.classList.add('bg-purple-600', 'text-white');
        btn.classList.remove('bg-slate-800/80', 'text-slate-400');

        AppState.currentHistoryFilter = btn.dataset.filter || 'all';
        renderHistoryTable();
      });
    });

    const searchInput = document.getElementById('historySearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        AppState.historySearchTerm = e.target.value.trim();
        renderHistoryTable();
      });
    }
  }

  // Settings: Undo Window & Thresholds
  function setupSettings() {
    const windowSelect = document.getElementById('settingsUndoWindow');
    const solThresh = document.getElementById('settingsThreshSol');
    const usdcThresh = document.getElementById('settingsThreshUsdc');
    const usdtThresh = document.getElementById('settingsThreshUsdt');
    const saveSettingsBtn = document.getElementById('saveSettingsBtn');

    if (windowSelect) windowSelect.value = AppState.settings.undoWindowMinutes;
    if (solThresh) solThresh.value = AppState.thresholds.SOL;
    if (usdcThresh) usdcThresh.value = AppState.thresholds.USDC;
    if (usdtThresh) usdtThresh.value = AppState.thresholds.USDT;

    if (saveSettingsBtn) {
      saveSettingsBtn.addEventListener('click', async () => {
        saveSettingsBtn.disabled = true;
        saveSettingsBtn.innerHTML = `<span class="spinner-sm inline-block mr-1"></span> Saving...`;

        const newWindow = parseInt(windowSelect?.value || 10, 10);
        const newThresh = {
          SOL: parseFloat(solThresh?.value || 2.0),
          USDC: parseFloat(usdcThresh?.value || 100.0),
          USDT: parseFloat(usdtThresh?.value || 100.0)
        };

        try {
          const res = await apiFetch('/api/settings', {
            method: 'POST',
            body: JSON.stringify({ undoWindowMinutes: newWindow, thresholds: newThresh })
          });

          if (res.success) {
            AppState.settings.undoWindowMinutes = newWindow;
            AppState.thresholds = newThresh;
            showToast('Preferences updated successfully!', 'success');
            setupTransferForm(); // refresh notice with new thresholds
          } else {
            showToast(res.error || 'Failed to save settings', 'error');
          }
        } catch (err) {
          showToast(err.message, 'error');
        } finally {
          saveSettingsBtn.disabled = false;
          saveSettingsBtn.innerHTML = `Save Preferences`;
        }
      });
    }
  }

  // 2FA Setup Flow
  function open2FaModal() {
    const modal = document.getElementById('twoFaModal');
    if (!modal) return;
    modal.classList.remove('hidden');

    // Fetch new secret & QR
    apiFetch('/api/auth/2fa/generate').then(data => {
      const qrImg = document.getElementById('twoFaQrImage');
      const secretText = document.getElementById('twoFaSecretText');
      if (qrImg && data.qr_code_url) qrImg.src = data.qr_code_url;
      if (secretText && data.secret) secretText.textContent = data.secret;
    });
  }
  window.open2FaModal = open2FaModal;

  function close2FaModal() {
    const modal = document.getElementById('twoFaModal');
    if (modal) modal.classList.add('hidden');
  }
  window.close2FaModal = close2FaModal;

  async function verifyAndEnable2Fa() {
    const codeInput = document.getElementById('twoFaVerifyCode');
    const code = codeInput ? codeInput.value.trim() : '';

    if (!code || code.length < 6) {
      showToast('Please enter the 6-digit verification code from your authenticator app', 'warning');
      return;
    }

    const btn = document.getElementById('confirm2FaBtn');
    if (btn) btn.disabled = true;

    try {
      const res = await apiFetch('/api/auth/2fa/enable', {
        method: 'POST',
        body: JSON.stringify({ code })
      });

      if (res.success) {
        showToast('Two-factor authentication successfully enabled!', 'success');
        if (AppState.user) AppState.user.two_factor_enabled = 1;
        renderHeaderAndBalances();
        close2FaModal();
      } else {
        showToast(res.error || 'Invalid TOTP code. Please try again.', 'error');
      }
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }
  window.verifyAndEnable2Fa = verifyAndEnable2Fa;

  // Export Custodial Private Key Flow
  function openExportKeyModal() {
    const modal = document.getElementById('exportKeyModal');
    if (modal) {
      const step1 = document.getElementById('exportKeyStep1');
      const step2 = document.getElementById('exportKeyStep2');
      if (step1) step1.classList.remove('hidden');
      if (step2) step2.classList.add('hidden');
      modal.classList.remove('hidden');
    }
  }
  window.openExportKeyModal = openExportKeyModal;

  function closeExportKeyModal() {
    const modal = document.getElementById('exportKeyModal');
    if (modal) modal.classList.add('hidden');
  }
  window.closeExportKeyModal = closeExportKeyModal;

  async function verifyAndRevealKey() {
    const pwdInput = document.getElementById('exportKeyPassword');
    const totpInput = document.getElementById('exportKeyTotp');
    const pwd = pwdInput ? pwdInput.value : '';
    const totp = totpInput ? totpInput.value : '';

    const btn = document.getElementById('submitExportKeyVerifyBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = `<span class="spinner-sm inline-block mr-1"></span> Verifying...`;
    }

    try {
      const res = await apiFetch('/api/auth/export-key', {
        method: 'POST',
        body: JSON.stringify({ password: pwd, code: totp })
      });

      const key = (res.data && res.data.private_key) || res.private_key;
      if (res.success && key) {
        const step1 = document.getElementById('exportKeyStep1');
        const step2 = document.getElementById('exportKeyStep2');
        const keyDisplay = document.getElementById('rawPrivateKeyDisplay');

        if (step1) step1.classList.add('hidden');
        if (step2) step2.classList.remove('hidden');
        if (keyDisplay) keyDisplay.textContent = key;

        showToast('Private key decrypted. Keep it strictly confidential!', 'warning', 5000);
      } else {
        showToast(res.message || res.error || 'Verification failed. Incorrect credentials.', 'error');
      }
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `Verify & Reveal Key`;
      }
    }
  }
  window.verifyAndRevealKey = verifyAndRevealKey;

  // Tab Switching (Overview, History, Settings, Admin)
  function setupTabs() {
    const tabButtons = document.querySelectorAll('[data-tab-target]');
    tabButtons.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = btn.dataset.tabTarget;
        switchTab(targetId);
      });
    });
  }

  function switchTab(tabId) {
    // Hide all tab sections
    document.querySelectorAll('.dashboard-tab-content').forEach(section => {
      section.classList.add('hidden');
    });

    // Show target section
    const targetSection = document.getElementById(`tab-content-${tabId}`);
    if (targetSection) targetSection.classList.remove('hidden');

    // Update navigation active styles
    document.querySelectorAll('[data-tab-target]').forEach(btn => {
      if (btn.dataset.tabTarget === tabId) {
        btn.classList.add('nav-tab-active');
        btn.classList.remove('nav-tab-inactive');
      } else {
        btn.classList.remove('nav-tab-active');
        btn.classList.add('nav-tab-inactive');
      }
    });

    // Mobile nav bar active states
    document.querySelectorAll('[data-mobile-tab]').forEach(btn => {
      if (btn.dataset.mobileTab === tabId) {
        btn.classList.add('text-purple-400', 'bg-white/5');
        btn.classList.remove('text-slate-400');
      } else {
        btn.classList.remove('text-purple-400', 'bg-white/5');
        btn.classList.add('text-slate-400');
      }
    });

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.switchTab = switchTab;

  // HTML escaping helper
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g,
      tag => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
      }[tag] || tag)
    );
  }

  // App Initialization
  async function init() {
    seedSampleData();

    // Load user & balances from API
    try {
      const authRes = await apiFetch('/api/auth/me');
      if (authRes && authRes.success) {
        const payload = authRes.data || authRes;
        AppState.user = payload.user || payload;
      }

      const balRes = await apiFetch('/api/transfers/balances');
      if (balRes && balRes.success) {
        const payload = balRes.data || balRes;
        if (payload.balances) AppState.balances = payload.balances;
        if (payload.thresholds) AppState.thresholds = payload.thresholds;
        if (payload.settings) AppState.settings = payload.settings;
      }

      const pendRes = await apiFetch('/api/transfers/pending');
      if (pendRes && pendRes.success) {
        const payload = pendRes.data || pendRes;
        const list = payload.transfers || payload.pending || [];
        if (Array.isArray(list)) {
          AppState.pendingTransfers = list;
        }
      }

      const histRes = await apiFetch('/api/transfers/history');
      if (histRes && histRes.success) {
        const payload = histRes.data || histRes;
        const list = payload.transfers || payload.history || [];
        if (Array.isArray(list)) {
          AppState.historyTransfers = list;
        }
      }
    } catch (e) {
      console.warn('Initial data load notice:', e.message);
    }

    renderHeaderAndBalances();
    renderPendingTransfers();
    renderHistoryTable();
    setupTransferForm();
    setupHistoryListeners();
    setupSettings();
    setupTabs();
    startTicker();
  }

  // DOM Ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
