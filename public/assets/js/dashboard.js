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
      SOL: { available: 0.0000, escrow: 0.0000, price: 184.50 },
      USDC: { available: 0.00, escrow: 0.00, price: 1.00 },
      USDT: { available: 0.00, escrow: 0.00, price: 1.00 }
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

  // Supported Token Logos Map
  const tokenLogos = {
    SOL: { url: '/assets/img/sol.svg', local: '/assets/img/sol.png' },
    USDC: { url: '/assets/img/usdc.svg', local: '/assets/img/usdc.png' },
    USDT: { url: '/assets/img/usdt.svg', local: '/assets/img/usdt.png' }
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
      iconSvg = `<svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>`;
    } else if (type === 'error') {
      iconSvg = `<svg class="w-5 h-5 text-rose-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
    } else if (type === 'warning') {
      iconSvg = `<svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
    } else {
      iconSvg = `<svg class="w-5 h-5 text-purple-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
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

  // Early global handler for 1-tap asset switcher (works even before DOMContentLoaded)
  window.selectTransferToken = function(tok) {
    if (!tok) tok = 'SOL';
    tok = tok.toUpperCase();
    const sel = document.getElementById('transferToken');
    if (sel) {
      sel.value = tok;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };
  window.setSelectedToken = window.selectTransferToken;

  // Copy to Clipboard Helper (Supports Secure Context, Textarea Fallback, and Prompt)
  async function copyToClipboard(text, label = 'Address') {
    if (!text || typeof text !== 'string' || !text.trim()) {
      showToast('No address available to copy', 'warning');
      return false;
    }
    const cleanText = text.trim();

    // 1. Try modern Clipboard API if supported
    if (navigator.clipboard && navigator.clipboard.writeText) {
      try {
        await navigator.clipboard.writeText(cleanText);
        showToast(`${label} copied to clipboard!`, 'success');
        return true;
      } catch (err) {
        // Fall back to DOM execCommand
      }
    }

    // 2. High-reliability textarea fallback
    try {
      const ta = document.createElement('textarea');
      ta.value = cleanText;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '0';
      ta.style.left = '0';
      ta.style.width = '2em';
      ta.style.height = '2em';
      ta.style.padding = '0';
      ta.style.border = 'none';
      ta.style.outline = 'none';
      ta.style.boxShadow = 'none';
      ta.style.background = 'transparent';
      ta.style.zIndex = '999999';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      ta.setSelectionRange(0, cleanText.length);

      const successful = document.execCommand('copy');
      document.body.removeChild(ta);

      if (successful) {
        showToast(`${label} copied to clipboard!`, 'success');
        return true;
      }
    } catch (e) {
      console.warn('DOM copy failed:', e);
    }

    // 3. Fallback prompt
    window.prompt(`Copy ${label}:`, cleanText);
    return true;
  }
  window.copyToClipboard = copyToClipboard;

  // Render / Refresh Deposit Modal & QR Code
  function renderDepositModal() {
    const addr = (AppState.user && AppState.user.solana_deposit_address)
      || document.getElementById('userDepositAddressFull')?.textContent?.trim()
      || document.getElementById('userDepositAddress')?.dataset?.full
      || '';

    if (!addr || addr.length < 32) return;

    // 1. Update text displays
    const fullEl = document.getElementById('userDepositAddressFull');
    if (fullEl) fullEl.textContent = addr;

    const shortEl = document.getElementById('userDepositAddress');
    if (shortEl) {
      shortEl.textContent = truncateAddress(addr, 6, 6);
      shortEl.dataset.full = addr;
    }

    // 2. Client-side QR generation via QRCode library if available
    const qrCanvas = document.getElementById('depositQrCanvas');
    const qrImg = document.getElementById('depositQrCodeImg');

    if (typeof QRCode !== 'undefined' && qrCanvas) {
      try {
        qrCanvas.innerHTML = '';
        qrCanvas.classList.remove('hidden');
        if (qrImg) qrImg.classList.add('hidden');
        new QRCode(qrCanvas, {
          text: addr,
          width: 160,
          height: 160,
          colorDark: '#000000',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
        return;
      } catch (err) {
        console.warn('Canvas QR failed, falling back to image QR:', err);
      }
    }

    // 3. Fallback to image QR (api.qrserver.com with quickchart.io fallback)
    if (qrImg) {
      if (qrCanvas) qrCanvas.classList.add('hidden');
      qrImg.classList.remove('hidden');
      const primaryUrl = `https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=4&data=${encodeURIComponent(addr)}`;
      const fallbackUrl = `https://quickchart.io/qr?size=180&text=${encodeURIComponent(addr)}`;
      qrImg.onerror = function () {
        if (this.src !== fallbackUrl) {
          this.src = fallbackUrl;
        }
      };
      qrImg.src = primaryUrl;
    }
  }
  window.renderDepositModal = renderDepositModal;

  // Dedicated Deposit Address Copy Action
  async function copyDepositAddress() {
    const addr = (AppState.user && AppState.user.solana_deposit_address)
      || document.getElementById('userDepositAddressFull')?.textContent?.trim()
      || document.getElementById('userDepositAddress')?.dataset?.full
      || '';

    if (!addr || addr.length < 32) {
      showToast('Deposit address not loaded yet. Please wait...', 'warning');
      return;
    }

    const copied = await copyToClipboard(addr, 'Deposit address');
    if (copied) {
      const btnText = document.getElementById('copyDepositBtnText');
      const btn = document.getElementById('copyDepositAddressBtn');
      if (btnText && btn) {
        const origText = btnText.textContent;
        btnText.textContent = 'Copied to Clipboard!';
        btn.classList.add('bg-emerald-600');
        btn.classList.remove('bg-purple-600');
        setTimeout(() => {
          btnText.textContent = origText;
          btn.classList.remove('bg-emerald-600');
          btn.classList.add('bg-purple-600');
        }, 2000);
      }
    }
  }
  window.copyDepositAddress = copyDepositAddress;

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
    const sessionToken = localStorage.getItem('solrecall_session_token') ||
      (window.__INITIAL_STATE__ && window.__INITIAL_STATE__.sessionToken) || '';

    const authHeaders = {};
    if (sessionToken && !options.headers?.Authorization && !options.headers?.authorization) {
      authHeaders['Authorization'] = `Bearer ${sessionToken}`;
    }

    const res = await fetch(endpoint, {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...authHeaders,
        ...(options.headers || {})
      },
      ...options
    });

    if (res.status === 401) {
      // Background and non-critical endpoints should throw without kicking user to login
      if (endpoint.includes('/cron') || endpoint.includes('/admin/')) {
        const errData = await res.json().catch(() => ({}));
        throw new Error(errData.error || 'Request unauthorized (401)');
      }
      try { localStorage.removeItem('solrecall_session_token'); } catch (e) {}
      window.location.href = '/login';
      throw new Error('Authentication required. Redirecting to login...');
    }

    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      return data;
    }

    const errorMsg = data.message || data.error || (data.errors ? Object.values(data.errors).flat().join(', ') : `Request failed with status ${res.status}`);
    throw new Error(errorMsg);
  }

  // Render Header & Balances
  function renderHeaderAndBalances() {
    const user = AppState.user;
    const balances = AppState.balances || {};
    if (user) {
      if (user.solana_deposit_address) {
        const depositEl = document.getElementById('userDepositAddress');
        if (depositEl) {
          depositEl.textContent = truncateAddress(user.solana_deposit_address, 6, 6);
          depositEl.dataset.full = user.solana_deposit_address;
        }

        const fullDepositEl = document.getElementById('userDepositAddressFull');
        if (fullDepositEl) fullDepositEl.textContent = user.solana_deposit_address;

        if (typeof renderDepositModal === 'function') {
          renderDepositModal();
        }
      }

      const usernameEl = document.getElementById('headerUsername');
      if (usernameEl) usernameEl.textContent = user.username || user.email || 'Solana User';

      const emailEl = document.getElementById('headerEmail');
      if (emailEl) emailEl.textContent = user.email || '';

      const twoFaBadge = document.getElementById('twoFaHeaderBadge');
      if (twoFaBadge) {
        twoFaBadge.innerHTML = user.two_factor_enabled
          ? `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
               <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> 2FA Secured
             </span>`
          : `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
               <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> 2FA Inactive
             </span>`;
      }

      if (typeof update2FaBadges === 'function') {
        update2FaBadges(user.two_factor_enabled);
      }
    }

    
    const drawerDepositEl = document.getElementById('drawerDepositAddressTruncated');
    if (drawerDepositEl && user && user.solana_deposit_address) {
      drawerDepositEl.textContent = truncateAddress(user.solana_deposit_address, 8, 8);
      drawerDepositEl.dataset.full = user.solana_deposit_address;
    }
    const drawerSolBal = document.getElementById('drawerUserSolBalance');
    if (drawerSolBal && balances && balances.SOL) {
      drawerSolBal.textContent = formatAmount(parseFloat(balances.SOL.available) || 0, 4) + ' SOL';
    }

    // Render Balance Cards
    const solPrice = (balances.SOL && balances.SOL.price) ? balances.SOL.price : 185.0;

    ['SOL', 'USDC', 'USDT'].forEach(tok => {
      const defaultPrice = tok === 'SOL' ? solPrice : 1.0;
      const data = balances[tok] || { available: 0, escrow: 0, price: defaultPrice };
      if (!data.price) data.price = defaultPrice;

      const availEl = document.getElementById(`bal-avail-${tok.toLowerCase()}`);
      const escrowEl = document.getElementById(`bal-escrow-${tok.toLowerCase()}`);
      const fiatEl = document.getElementById(`bal-fiat-${tok.toLowerCase()}`);

      const availNum = parseFloat(data.available) || 0;
      const escrowNum = parseFloat(data.escrow) || 0;

      if (availEl) availEl.textContent = formatAmount(availNum, tok === 'SOL' ? 4 : 2);
      if (escrowEl) escrowEl.textContent = formatAmount(escrowNum, tok === 'SOL' ? 4 : 2);

      const totalTok = availNum + escrowNum;
      const fiatTotal = totalTok * data.price;
      if (fiatEl) fiatEl.textContent = `≈ $${formatAmount(fiatTotal, 2)}`;
    });

    // Update real-time balances inside custom token selector dropdown
    ['SOL', 'USDC', 'USDT'].forEach(tok => {
      const balEl = document.getElementById(`tokenMenuBal-${tok}`);
      if (balEl && balances[tok]) {
        const num = parseFloat(balances[tok].available) || 0;
        balEl.textContent = formatAmount(num, tok === 'SOL' ? 4 : 2);
      }
    });

    // Update available amount hint for currently selected token
    const tokenSelectEl = document.getElementById('transferToken');
    const activeTok = tokenSelectEl ? tokenSelectEl.value : 'SOL';
    const availHintEl = document.getElementById('availableAmountHint');
    if (availHintEl && balances[activeTok]) {
      const num = parseFloat(balances[activeTok].available) || 0;
      availHintEl.textContent = `Available: ${formatAmount(num, activeTok === 'SOL' ? 4 : 2)} ${activeTok}`;
    }

    // Total Escrow Shield Pill with multi-token awareness
    const totalEscrowEl = document.getElementById('totalEscrowShielded');
    const breakdownBadgesEl = document.getElementById('escrowBreakdownBadges');
    const escrowSubtextEl = document.getElementById('escrowProtectedSubtext');

    // Compute escrow from active pending transfers array as well as balance table
    let pendingSol = 0;
    let pendingUsdc = 0;
    let pendingUsdt = 0;
    if (Array.isArray(AppState.pendingTransfers)) {
      AppState.pendingTransfers.forEach(t => {
        if (t.status === 'pending') {
          const amt = parseFloat(t.amount) || 0;
          if (t.token === 'SOL') pendingSol += amt;
          else if (t.token === 'USDC') pendingUsdc += amt;
          else if (t.token === 'USDT') pendingUsdt += amt;
        }
      });
    }

    const solEscrow = Math.max(parseFloat(balances.SOL ? balances.SOL.escrow : 0) || 0, pendingSol);
    const usdcEscrow = Math.max(parseFloat(balances.USDC ? balances.USDC.escrow : 0) || 0, pendingUsdc);
    const usdtEscrow = Math.max(parseFloat(balances.USDT ? balances.USDT.escrow : 0) || 0, pendingUsdt);
    const stablesEscrow = usdcEscrow + usdtEscrow;
    const activeHoldCount = Array.isArray(AppState.pendingTransfers)
      ? AppState.pendingTransfers.filter(t => t.status === 'pending').length
      : 0;

    if (totalEscrowEl) {
      if (solEscrow > 0 && stablesEscrow > 0) {
        totalEscrowEl.innerHTML = `
          <span class="font-mono text-white text-2xl font-black">${formatAmount(solEscrow, 4)}</span>
          <span class="text-xs font-bold text-amber-400 font-sans tracking-normal ml-1">SOL</span>
          <span class="text-slate-400 text-sm font-sans mx-2">+</span>
          <span class="font-mono text-emerald-400 text-2xl font-black">$${formatAmount(stablesEscrow, 2)}</span>
        `;
      } else if (solEscrow > 0) {
        totalEscrowEl.innerHTML = `
          <span class="font-mono text-white text-3xl font-black tracking-tight">${formatAmount(solEscrow, 4)}</span>
          <span class="text-sm font-bold text-amber-400 font-sans tracking-normal ml-1.5">SOL</span>
        `;
      } else if (stablesEscrow > 0) {
        totalEscrowEl.innerHTML = `
          <span class="font-mono text-emerald-400 text-3xl font-black tracking-tight">$${formatAmount(stablesEscrow, 2)}</span>
          <span class="text-xs font-bold text-slate-300 font-sans ml-1.5">${usdcEscrow > 0 && usdtEscrow > 0 ? 'USDC + USDT' : (usdcEscrow > 0 ? 'USDC' : 'USDT')}</span>
        `;
      } else {
        totalEscrowEl.innerHTML = `<span class="font-mono text-slate-400 text-2xl font-black">0.0000 SOL</span>`;
      }
    }

    if (breakdownBadgesEl) {
      const badges = [];
      if (solEscrow > 0) {
        const fiatVal = (solEscrow * solPrice).toFixed(2);
        badges.push(`<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-mono bg-purple-500/15 text-purple-300 border border-purple-500/30">
          <span class="w-1.5 h-1.5 rounded-full bg-purple-400"></span> ${formatAmount(solEscrow, 4)} SOL (≈$${fiatVal})
        </span>`);
      }
      if (usdcEscrow > 0) {
        badges.push(`<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-mono bg-blue-500/15 text-blue-300 border border-blue-500/30">
          <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span> $${formatAmount(usdcEscrow, 2)} USDC
        </span>`);
      }
      if (usdtEscrow > 0) {
        badges.push(`<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-mono bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> $${formatAmount(usdtEscrow, 2)} USDT
        </span>`);
      }
      breakdownBadgesEl.innerHTML = badges.join('');
    }

    if (escrowSubtextEl) {
      if (activeHoldCount > 0) {
        escrowSubtextEl.textContent = `${activeHoldCount} active hold${activeHoldCount > 1 ? 's' : ''} in undo window. Revocable instantly before countdown elapses.`;
      } else {
        escrowSubtextEl.textContent = 'Funds in the undo window can be revoked instantly before the countdown elapses.';
      }
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

  // Render Pending Transfers Section with Dual Desktop Table & Sleek Mobile Cards
  function renderPendingTransfers() {
    const container = document.getElementById('pendingTransfersContainer');
    const emptyState = document.getElementById('pendingTransfersEmpty');
    if (!container) return;

    // Filter out any transfers that are no longer pending or already expired (remaining <= 0)
    const expired = AppState.pendingTransfers.filter(t => t.status !== 'pending' || calculateRemainingSeconds(t) <= 0);
    if (expired.length > 0) {
      AppState.pendingTransfers = AppState.pendingTransfers.filter(t => t.status === 'pending' && calculateRemainingSeconds(t) > 0);
      apiFetch('/api/dashboard/bootstrap').then(bRes => {
        if (bRes && bRes.success && bRes.data) {
          if (bRes.data.balances) AppState.balances = bRes.data.balances;
          if (Array.isArray(bRes.data.pending)) {
            AppState.pendingTransfers = bRes.data.pending.filter(item => item.status === 'pending' && calculateRemainingSeconds(item) > 0);
          }
          if (Array.isArray(bRes.data.history)) {
            AppState.historyTransfers = bRes.data.history;
          }
          renderHeaderAndBalances();
          renderPendingTransfers();
          renderHistoryTable();
        }
      }).catch(() => {});
    }

    if (AppState.pendingTransfers.length === 0) {
      container.innerHTML = '';
      if (emptyState) emptyState.classList.remove('hidden');
      return;
    }

    if (emptyState) emptyState.classList.add('hidden');

    // Build Desktop Table Rows
    const desktopRowsHtml = AppState.pendingTransfers.map(t => {
      const remaining = calculateRemainingSeconds(t);
      const total = t.total_seconds || (AppState.settings.undoWindowMinutes * 60);
      const percent = Math.min(100, Math.max(0, (remaining / total) * 100));
      const isUrgent = remaining <= 60;
      const timeFormatted = formatMMSS(remaining);
      const tLogo = tokenLogos[t.token] || tokenLogos.SOL;

      return `
        <tr data-pending-card="${t.id}" id="pending-row-${t.id}" class="hover:bg-white/[0.02] transition-colors duration-200">
          <td class="py-3.5 px-4">
            <div class="flex items-center gap-2.5">
              <img src="${tLogo.url}" onerror="this.src='${tLogo.local}'" alt="${t.token}" class="w-7 h-7 rounded-full object-contain p-0.5 bg-black/40 border border-white/10 shrink-0">
              <div>
                <div class="font-bold text-white font-mono text-sm">
                  ${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} <span class="text-xs text-slate-400 font-sans font-normal">${t.token}</span>
                </div>
                ${t.note ? `<div class="text-[11px] text-slate-400 truncate max-w-[140px]">“${escapeHtml(t.note)}”</div>` : ''}
              </div>
            </div>
          </td>
          <td class="py-3.5 px-4">
            <div class="flex items-center gap-1.5 font-mono text-xs text-slate-300">
              <span class="bg-slate-900/80 px-2 py-1 rounded border border-white/5 font-semibold text-purple-300" title="${t.destination}">
                ${truncateAddress(t.destination, 4, 4)}
              </span>
              <button type="button" class="touch-target w-8 h-8 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition flex items-center justify-center cursor-pointer" title="Copy Address" onclick="copyToClipboard('${t.destination}', 'Recipient address')">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
              </button>
            </div>
          </td>
          <td class="py-3.5 px-4">
            <div class="space-y-1.5 min-w-[130px]">
              <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-mono font-bold ${isUrgent ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-amber-500/10 text-amber-300 border border-amber-500/30'}">
                <svg class="w-3.5 h-3.5 ${isUrgent ? 'text-rose-400' : 'text-amber-400'}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span data-timer-id="${t.id}">${timeFormatted}</span>
              </div>
              <div class="w-full bg-slate-800/80 rounded-full h-1.5 overflow-hidden border border-white/5">
                <div data-progress-id="${t.id}" class="progress-bar-draining h-full rounded-full ${isUrgent ? 'urgent bg-gradient-to-r from-rose-500 to-amber-500' : 'bg-gradient-to-r from-amber-400 to-amber-500'}" style="width: ${percent}%;"></div>
              </div>
            </div>
          </td>
          <td class="py-3.5 px-4 text-right whitespace-nowrap">
            <div class="flex items-center justify-end gap-2">
              <button type="button"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-purple-500/10 hover:bg-purple-500/25 text-purple-300 hover:text-white border border-purple-500/30 hover:border-purple-500/60 transition shadow-sm cursor-pointer"
                      onclick="openTransferReceiptById(${t.id})">
                <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Receipt</span>
              </button>
              <button type="button"
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-rose-500/15 hover:bg-rose-500/30 text-rose-300 hover:text-white border border-rose-500/40 hover:border-rose-500/70 transition shadow-sm cursor-pointer"
                      onclick="openCancelModal(${t.id})">
                <svg class="w-3.5 h-3.5 text-rose-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>
                <span>Cancel</span>
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    // Build Mobile High-Conviction Cards
    const mobileCardsHtml = AppState.pendingTransfers.map(t => {
      const remaining = calculateRemainingSeconds(t);
      const total = t.total_seconds || (AppState.settings.undoWindowMinutes * 60);
      const percent = Math.min(100, Math.max(0, (remaining / total) * 100));
      const isUrgent = remaining <= 60;
      const timeFormatted = formatMMSS(remaining);
      const tLogo = tokenLogos[t.token] || tokenLogos.SOL;

      return `
        <div data-pending-card="${t.id}" id="pending-card-${t.id}" class="glass-panel glass-panel-hover rounded-2xl p-4 sm:p-5 relative overflow-hidden transition-all duration-300 border-l-4 ${isUrgent ? 'border-l-rose-500' : 'border-l-amber-500'} space-y-3">
          <!-- Top Row: Token & Amount + Countdown Timer -->
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
              <img src="${tLogo.url}" onerror="this.src='${tLogo.local}'" alt="${t.token}" class="w-8 h-8 rounded-full object-contain p-0.5 bg-black/50 border border-white/10 shrink-0">
              <div>
                <div class="text-lg font-bold text-white tracking-tight font-mono">
                  ${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} <span class="text-xs font-semibold text-slate-300 font-sans">${t.token}</span>
                </div>
                ${t.note ? `<p class="text-[11px] text-slate-400 truncate max-w-[180px]">“${escapeHtml(t.note)}”</p>` : ''}
              </div>
            </div>

            <!-- Timer pill -->
            <div class="text-right shrink-0">
              <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-mono font-bold ${isUrgent ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-amber-500/10 text-amber-300 border border-amber-500/30'}">
                <svg class="w-3.5 h-3.5 ${isUrgent ? 'text-rose-400' : 'text-amber-400'}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span data-timer-id="${t.id}">${timeFormatted}</span>
              </div>
              <div class="text-[10px] text-slate-400 mt-0.5">Undo Window Active</div>
            </div>
          </div>

          <!-- Draining Animated Progress Bar -->
          <div class="w-full bg-slate-800/80 rounded-full h-2 overflow-hidden p-0.5 border border-white/5">
            <div data-progress-id="${t.id}"
                 class="progress-bar-draining h-full rounded-full ${isUrgent ? 'urgent bg-gradient-to-r from-rose-500 to-amber-500' : 'bg-gradient-to-r from-amber-400 to-amber-500'}"
                 style="width: ${percent}%;"></div>
          </div>

          <!-- Recipient Row with >= 44px touch target copy button -->
          <div class="flex items-center justify-between text-xs bg-slate-900/60 px-3 py-1 rounded-xl border border-white/5">
            <div class="flex items-center gap-1.5 truncate">
              <span class="text-slate-400">To:</span>
              <span class="font-mono text-purple-300 font-semibold truncate" title="${t.destination}">${truncateAddress(t.destination, 6, 6)}</span>
            </div>
            <button type="button" class="touch-target w-10 h-10 min-w-[40px] min-h-[40px] text-slate-400 hover:text-white rounded-lg hover:bg-white/10 transition flex items-center justify-center cursor-pointer shrink-0" title="Copy Address" onclick="copyToClipboard('${t.destination}', 'Recipient address')">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
            </button>
          </div>

          <!-- Bottom Action Buttons: Comfortable >= 44px Touch Targets -->
          <div class="grid grid-cols-2 gap-2.5 pt-1">
            <button type="button"
                    class="touch-target min-h-[44px] w-full inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold bg-purple-500/10 hover:bg-purple-500/25 text-purple-300 hover:text-white border border-purple-500/30 hover:border-purple-500/60 transition shadow-sm cursor-pointer active:scale-95"
                    onclick="openTransferReceiptById(${t.id})">
              <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
              <span>Receipt</span>
            </button>
            <button type="button"
                    class="touch-target min-h-[44px] w-full inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold bg-rose-500/15 hover:bg-rose-500/30 text-rose-300 hover:text-white border border-rose-500/40 hover:border-rose-500/70 transition shadow-sm cursor-pointer active:scale-95"
                    onclick="openCancelModal(${t.id})">
              <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>
              <span>Cancel Undo</span>
            </button>
          </div>
        </div>
      `;
    }).join('');

    // Render Both Desktop and Mobile
    container.innerHTML = `
      <div class="hidden md:block glass-panel rounded-2xl border border-white/10 overflow-hidden shadow-lg">
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="border-b border-white/10 bg-slate-900/60 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                <th class="py-3 px-4">Asset & Amount</th>
                <th class="py-3 px-4">Recipient</th>
                <th class="py-3 px-4">Escrow Countdown</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              ${desktopRowsHtml}
            </tbody>
          </table>
        </div>
      </div>
      <div class="md:hidden space-y-3">
        ${mobileCardsHtml}
      </div>
    `;
  }

  // Calculate remaining seconds for a transfer with UTC normalization
  function calculateRemainingSeconds(transfer) {
    if (!transfer) return 0;
    if (typeof transfer.seconds_remaining === 'number' && !transfer._expiresMs) {
      transfer._expiresMs = Date.now() + (transfer.seconds_remaining * 1000);
    }
    if (transfer._expiresMs) {
      return Math.max(0, Math.floor((transfer._expiresMs - Date.now()) / 1000));
    }
    if (!transfer.expires_at) return 0;

    let isoStr = String(transfer.expires_at).trim();
    // Normalize date string across all engines (Chrome, Firefox, Safari/iOS)
    isoStr = isoStr.replace(' ', 'T');
    if (isoStr.endsWith('+00')) {
      isoStr = isoStr.slice(0, -3) + 'Z';
    } else if (isoStr.includes('+00:00')) {
      isoStr = isoStr.replace('+00:00', 'Z');
    } else if (!isoStr.endsWith('Z') && !isoStr.includes('+')) {
      isoStr += 'Z';
    }
    const expiresMs = new Date(isoStr).getTime();
    if (isNaN(expiresMs)) return 0;
    return Math.max(0, Math.floor((expiresMs - Date.now()) / 1000));
  }

  // Instantly dissolve expired hold card with smooth fade-out and DOM removal within 350ms
  function dissolveExpiredTransfer(transfer) {
    if (!transfer) return;
    const transferId = transfer.id;
    const elementsToDissolve = document.querySelectorAll(`[data-pending-card="${transferId}"]`);

    if (elementsToDissolve.length > 0) {
      elementsToDissolve.forEach(el => {
        el.classList.add('card-dissolve');
        el.style.transition = 'opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1), transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
        el.style.opacity = '0';
        el.style.transform = 'translate3d(0, -8px, 0) scale(0.96)';
        el.style.pointerEvents = 'none';
      });

      // Remove from DOM within 350ms
      setTimeout(() => {
        elementsToDissolve.forEach(el => {
          if (el && el.parentNode) el.remove();
        });
        const container = document.getElementById('pendingTransfersContainer');
        const emptyState = document.getElementById('pendingTransfersEmpty');
        if (AppState.pendingTransfers.length === 0 && emptyState) {
          if (container) container.innerHTML = '';
          emptyState.classList.remove('hidden');
        }
      }, 350);
    }

    // Update AppState.pendingTransfers to remove the expired transfer immediately
    AppState.pendingTransfers = AppState.pendingTransfers.filter(item => item.id !== transferId);

    // Re-render balances (renderHeaderAndBalances()) so the Escrow Protected count and amounts update immediately
    renderHeaderAndBalances();

    const emptyState = document.getElementById('pendingTransfersEmpty');
    if (AppState.pendingTransfers.length === 0 && emptyState) {
      emptyState.classList.remove('hidden');
    }

    // Update SWR cache in localStorage
    try {
      localStorage.setItem('solrecall_swr_cache_v2', JSON.stringify({
        user: AppState.user,
        balances: AppState.balances,
        pendingTransfers: AppState.pendingTransfers,
        historyTransfers: AppState.historyTransfers,
        settings: AppState.settings,
        timestamp: Date.now()
      }));
    } catch (e) {}

    // Trigger consolidated bootstrap sync to settle state with server
    apiFetch('/api/dashboard/bootstrap').then(bRes => {
      if (bRes && bRes.success && bRes.data) {
        if (bRes.data.balances) AppState.balances = bRes.data.balances;
        if (Array.isArray(bRes.data.pending)) {
          AppState.pendingTransfers = bRes.data.pending.filter(item => item.status === 'pending' && calculateRemainingSeconds(item) > 0);
        }
        if (Array.isArray(bRes.data.history)) {
          AppState.historyTransfers = bRes.data.history;
        }
        renderHeaderAndBalances();
        renderPendingTransfers();
        renderHistoryTable();
      }
    }).catch(e => console.debug('Expired sync notice:', e));
  }
  window.dissolveExpiredTransfer = dissolveExpiredTransfer;

  // Real-time Countdown Ticker (1-second tick)
  function startTicker() {
    if (AppState.tickerInterval) clearInterval(AppState.tickerInterval);

    AppState.tickerInterval = setInterval(() => {
      const expiredList = [];

      AppState.pendingTransfers.forEach(t => {
        const remaining = calculateRemainingSeconds(t);

        // When a transfer's remaining seconds reaches <= 0:
        // Must NOT remain stuck at 00:00! Collect for instant dissolution.
        if (remaining <= 0) {
          expiredList.push(t);
          return;
        }

        const total = t.total_seconds || (AppState.settings.undoWindowMinutes * 60);
        const percent = Math.min(100, Math.max(0, (remaining / total) * 100));
        const isUrgent = remaining <= 60;

        const timerTextEls = document.querySelectorAll(`[data-timer-id="${t.id}"]`);
        const progressBarEls = document.querySelectorAll(`[data-progress-id="${t.id}"]`);

        timerTextEls.forEach(el => {
          el.textContent = formatMMSS(remaining);
        });

        progressBarEls.forEach(el => {
          el.style.width = `${percent}%`;
          if (isUrgent) {
            el.classList.add('urgent');
          } else {
            el.classList.remove('urgent');
          }
        });
      });

      if (expiredList.length > 0) {
        showToast('Undo window expired. Funds releasing on Solana...', 'info', 3000);
        expiredList.forEach(t => dissolveExpiredTransfer(t));
      }
    }, 1000);
  }

  // Handle expired transfer automatic broadcast via server polling
  async function handleExpiredTransfers() {
    showToast('Undo window expired. Broadcaster processing on Solana...', 'info');

    try {
      // Trigger bootstrap settlement on server
      await apiFetch('/api/dashboard/bootstrap');
    } catch (err) {
      console.warn('Settlement broadcast trigger:', err.message);
    }

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
          const fresh = payload.transfers || payload.pending || [];
          AppState.pendingTransfers = fresh.filter(t => t.status === 'pending' && calculateRemainingSeconds(t) > 0);
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

  // Dynamic Transfer Form Feedback & Web3 Token Dropdown Integration
  function setupTransferForm() {
    const form = document.getElementById('newTransferForm');
    if (!form) return;

    const tokenSelect = document.getElementById('transferToken');
    const customLogo = document.getElementById('customTokenSelectedLogo');
    const customSymbol = document.getElementById('customTokenSelectedSymbol');

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

    function selectTransferToken(tok, triggerEvents = true) {
      if (!tok) tok = 'SOL';
      tok = tok.toUpperCase();
      if (!['SOL', 'USDC', 'USDT'].includes(tok)) tok = 'SOL';

      // 1. Sync backing native select
      if (tokenSelect) {
        tokenSelect.value = tok;
      }

      // 2. Sync trigger display logo & symbol
      const tLogo = tokenLogos[tok] || tokenLogos.SOL;
      if (customLogo) {
        customLogo.src = tLogo.url;
        customLogo.onerror = () => { customLogo.src = tLogo.local; };
        customLogo.alt = tok;
        if (tok === 'SOL') {
          customLogo.className = 'w-5 h-5 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-purple-500/30';
        } else if (tok === 'USDC') {
          customLogo.className = 'w-5 h-5 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-blue-500/30';
        } else {
          customLogo.className = 'w-5 h-5 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-emerald-500/30';
        }
      }
      if (customSymbol) {
        customSymbol.textContent = tok;
      }

      // 3. Highlight quick switcher pills (SOL, USDC, USDT)
      ['SOL', 'USDC', 'USDT'].forEach(sym => {
        const pill = document.getElementById(`pillBtn-${sym}`);
        if (pill) {
          if (sym === tok) {
            if (tok === 'SOL') pill.className = 'flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-xl text-xs font-bold font-crypto transition cursor-pointer bg-purple-500/20 text-purple-300 border border-purple-500/40 shadow-sm';
            else if (tok === 'USDC') pill.className = 'flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-xl text-xs font-bold font-crypto transition cursor-pointer bg-blue-500/20 text-blue-300 border border-blue-500/40 shadow-sm';
            else pill.className = 'flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-xl text-xs font-bold font-crypto transition cursor-pointer bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm';
          } else {
            pill.className = 'flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-xl text-xs font-bold font-crypto transition cursor-pointer bg-slate-800 text-slate-400 hover:text-white border border-white/5';
          }
        }
      });

      // 4. Update input step & placeholder
      if (amountInput) {
        amountInput.step = tok === 'SOL' ? 'any' : '0.01';
        amountInput.placeholder = tok === 'SOL' ? '0.0000' : '0.00';
      }

      // 5. Update available hint text for selected token
      const availHint = document.getElementById('availableAmountHint');
      if (availHint) {
        const avail = AppState.balances[tok] ? AppState.balances[tok].available : 0;
        availHint.textContent = `Available: ${formatAmount(avail, tok === 'SOL' ? 4 : 2)} ${tok}`;
      }

      // 6. Recalculate fees & notices
      updateNotice();

      // 7. Dispatch events
      if (triggerEvents && tokenSelect) {
        tokenSelect.dispatchEvent(new Event('change', { bubbles: true }));
        tokenSelect.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
    window.selectTransferToken = selectTransferToken;
    window.setSelectedToken = selectTransferToken;

    function updateNotice() {
      const token = tokenSelect ? tokenSelect.value : 'SOL';
      const amount = parseFloat(amountInput ? amountInput.value : 0) || 0;
      const threshold = AppState.thresholds[token] || (token === 'SOL' ? 2.0 : 100.0);
      const windowMins = getSelectedWindowMinutes();
      const humanWin = formatHumanDuration(windowMins);

      // Fee Calculations: 12% Platform Fee & 0.000005 SOL Network Gas
      const fee12 = amount * 0.12;
      const gasSol = 0.000005;

      const feeFormatted = token === 'SOL' ? fee12.toFixed(4) : fee12.toFixed(2);
      const amountFormatted = token === 'SOL' ? amount.toFixed(4) : amount.toFixed(2);

      let totalDebitedFormatted = '';
      if (token === 'SOL') {
        const totalSol = amount + fee12 + gasSol;
        totalDebitedFormatted = `${totalSol.toFixed(6).replace(/0+$/, '').replace(/\.$/, '')} SOL`;
      } else {
        totalDebitedFormatted = `${(amount + fee12).toFixed(2)} ${token} + 0.000005 SOL`;
      }

      if (noticeBox && noticeText) {
        noticeBox.classList.remove('hidden');

        if (amount > 0) {
          const isEscrow = amount <= threshold;
          noticeBox.className = isEscrow
            ? 'rounded-2xl p-4 bg-gradient-to-br from-amber-500/10 via-slate-900/60 to-purple-600/10 border border-amber-500/30 text-xs shadow-lg'
            : 'rounded-2xl p-4 bg-purple-500/10 border border-purple-500/30 text-xs shadow-lg';

          const protectionBadge = isEscrow
            ? `<div class="flex items-center gap-2 text-amber-300 font-bold mb-2 pb-2 border-b border-amber-500/20">
                <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                <span>🛡️ Protected by ${humanWin} Escrow Hold</span>
               </div>`
            : `<div class="flex items-center gap-2 text-purple-300 font-bold mb-2 pb-2 border-b border-purple-500/20">
                <svg class="w-4 h-4 text-purple-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <span>⚡ Instant On-Chain Broadcast (&gt; ${threshold} ${token})</span>
               </div>`;

          noticeText.innerHTML = `
            ${protectionBadge}
            <div class="space-y-1.5 pt-0.5">
              <div class="flex justify-between items-center text-slate-300">
                <span class="text-slate-400">Platform Fee (12%):</span>
                <span class="font-mono text-amber-300 font-medium">+${feeFormatted} ${token} <span class="text-slate-400 text-[10px]">(deducted from remaining balance)</span></span>
              </div>
              <div class="flex justify-between items-center text-slate-300">
                <span class="text-slate-400">Network Gas:</span>
                <span class="font-mono text-slate-200">0.000005 SOL <span class="text-slate-400 text-[10px]">(Estimated Solana tx fee)</span></span>
              </div>
              <div class="flex justify-between items-center text-slate-300">
                <span class="text-slate-400">Recipient Receives:</span>
                <span class="font-mono font-bold text-emerald-400">${amountFormatted} ${token}</span>
              </div>
              <div class="flex justify-between items-center pt-2 mt-1 border-t border-white/10 text-xs font-bold text-white">
                <span>Total Debited:</span>
                <span class="font-mono text-purple-300">${totalDebitedFormatted}</span>
              </div>
            </div>
          `;
        } else {
          noticeBox.className = 'rounded-xl p-3.5 bg-slate-800/60 border border-white/5 text-slate-400 text-xs flex items-start gap-2.5';
          noticeText.innerHTML = `
            <div class="flex items-start gap-2">
              <span class="text-purple-400 mt-0.5">ℹ️</span>
              <div>
                <strong>Small Transfer Protection:</strong> Transfers up to ${threshold} ${token} are held in a ${humanWin} escrow window. 12% platform fee & 0.000005 SOL Solana network gas fee are calculated live and deducted from your remaining balance, not docked from recipient.
              </div>
            </div>
          `;
        }
      }

      // Update live fee & network status footer
      const feeFooterVal = document.getElementById('transferPlatformFeeFooterVal');
      const gasFooterVal = document.getElementById('transferGasFeeFooterVal');
      if (feeFooterVal) {
        feeFooterVal.textContent = amount > 0 
          ? `+${feeFormatted} ${token}` 
          : `+${(0).toFixed(token === 'SOL' ? 4 : 2)} ${token}`;
      }
      if (gasFooterVal) {
        gasFooterVal.textContent = '0.000005 SOL';
      }

      // Update available hint text for selected token
      const availHint = document.getElementById('availableAmountHint');
      if (availHint) {
        const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
        availHint.textContent = `Available: ${formatAmount(avail, token === 'SOL' ? 4 : 2)} ${token}`;
      }

      // Update token logos
      const tLogo = tokenLogos[token] || tokenLogos.SOL;
      if (customLogo) {
        customLogo.src = tLogo.url;
        customLogo.onerror = () => { customLogo.src = tLogo.local; };
      }
      if (customSymbol) {
        customSymbol.textContent = token;
      }
    }

    // Native select change/input listener
    if (tokenSelect) {
      tokenSelect.addEventListener('change', () => {
        selectTransferToken(tokenSelect.value, false);
      });
      tokenSelect.addEventListener('input', () => {
        selectTransferToken(tokenSelect.value, false);
      });
    }

    // Explicit click listeners on 1-tap asset tabs
    ['SOL', 'USDC', 'USDT'].forEach(tok => {
      const pill = document.getElementById('pillBtn-' + tok);
      if (pill) {
        pill.addEventListener('click', (e) => {
          e.preventDefault();
          selectTransferToken(tok);
        });
      }
    });

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

    // MAX button: Solves for amount + 12% fee + gas <= avail
    if (maxBtn) {
      maxBtn.addEventListener('click', () => {
        const token = tokenSelect ? tokenSelect.value : 'SOL';
        const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
        let maxAmount = 0;
        if (token === 'SOL') {
          maxAmount = Math.max(0, (avail - 0.000005) / 1.12);
          maxAmount = Math.floor(maxAmount * 10000) / 10000;
          if (amountInput) amountInput.value = maxAmount > 0 ? maxAmount.toFixed(4) : '';
        } else {
          maxAmount = Math.max(0, avail / 1.12);
          maxAmount = Math.floor(maxAmount * 100) / 100;
          if (amountInput) amountInput.value = maxAmount > 0 ? maxAmount.toFixed(2) : '';
        }
        updateNotice();
      });
    }

    // Percentage buttons (25%, 50%, 100%)
    percentBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const pct = parseFloat(btn.dataset.percent) || 100;
        const token = tokenSelect ? tokenSelect.value : 'SOL';
        const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
        let baseMax = 0;
        if (token === 'SOL') {
          baseMax = Math.max(0, (avail - 0.000005) / 1.12);
        } else {
          baseMax = Math.max(0, avail / 1.12);
        }
        const calc = baseMax * (pct / 100);
        if (amountInput) {
          if (token === 'SOL') {
            const val = Math.floor(calc * 10000) / 10000;
            amountInput.value = val > 0 ? val.toFixed(4) : '';
          } else {
            const val = Math.floor(calc * 100) / 100;
            amountInput.value = val > 0 ? val.toFixed(2) : '';
          }
          updateNotice();
        }
      });
    });

    // Form Submit
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const token = tokenSelect ? tokenSelect.value : 'SOL';
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

      // Balance & Non-sponsored Gas Validation
      const avail = AppState.balances[token] ? AppState.balances[token].available : 0;
      const fee12 = amount * 0.12;
      const gasSol = 0.000005;

      if (token === 'SOL') {
        const requiredSol = amount + fee12 + gasSol;
        if (avail < requiredSol) {
          showToast(`Insufficient SOL balance. Required: ${requiredSol.toFixed(6)} SOL (Amount: ${amount.toFixed(4)} + 12% fee: ${fee12.toFixed(4)} + Gas: 0.000005 SOL). Available: ${formatAmount(avail, 4)} SOL`, 'error');
          amountInput.focus();
          return;
        }
      } else {
        const requiredToken = amount + fee12;
        const solAvail = AppState.balances.SOL ? AppState.balances.SOL.available : 0;
        if (avail < requiredToken) {
          showToast(`Insufficient ${token} balance. Required: ${requiredToken.toFixed(2)} ${token} (Amount: ${amount.toFixed(2)} + 12% fee: ${fee12.toFixed(2)}). Available: ${formatAmount(avail, 2)} ${token}`, 'error');
          amountInput.focus();
          return;
        }
        if (solAvail < gasSol) {
          showToast(`Insufficient SOL balance for Solana network gas. Required: 0.000005 SOL. Available: ${formatAmount(solAvail, 4)} SOL`, 'error');
          return;
        }
      }

      // Button loading state & Full-fidelity loading modal
      if (submitBtn) submitBtn.disabled = true;
      if (submitBtnSpinner) submitBtnSpinner.classList.remove('hidden');
      if (submitBtnText) submitBtnText.textContent = 'Initiating Escrow...';

      const windowMinutes = getSelectedWindowMinutes();
      openTransferLoadingModal({ token, amount, destination, windowMinutes, note });

      try {
        const res = await apiFetch('/api/transfers/initiate', {
          method: 'POST',
          body: JSON.stringify({ token, amount, destination, note, window_minutes: windowMinutes })
        });

        if (res.success) {
          const payload = res.data || res;
          const transferId = payload.transfer_id || payload.id || Date.now();
          const expiresAt = payload.expires_at || new Date(Date.now() + windowMinutes * 60 * 1000).toISOString();
          const totalSeconds = (windowMinutes * 60);

          // 1. Construct new transfer item immediately from response data
          const newTransfer = {
            id: transferId,
            user_id: AppState.user ? AppState.user.id : null,
            token: token,
            amount: amount,
            destination: destination,
            note: note || '',
            expires_at: expiresAt,
            status: 'pending',
            seconds_remaining: totalSeconds,
            total_seconds: totalSeconds,
            _expiresMs: Date.now() + (totalSeconds * 1000),
            created_at: new Date().toISOString()
          };

          // 2. Optimistically unshift into AppState.pendingTransfers
          AppState.pendingTransfers = AppState.pendingTransfers.filter(t => t.id !== transferId);
          AppState.pendingTransfers.unshift(newTransfer);

          // 3. Optimistically deduct transfer amount + 12% fee (+ gas) from AppState.balances and add amount to escrow
          if (AppState.balances[token]) {
            if (token === 'SOL') {
              AppState.balances.SOL.available = Math.max(0, AppState.balances.SOL.available - (amount + fee12 + gasSol));
              AppState.balances.SOL.escrow = (AppState.balances.SOL.escrow || 0) + amount;
            } else {
              AppState.balances[token].available = Math.max(0, AppState.balances[token].available - (amount + fee12));
              AppState.balances[token].escrow = (AppState.balances[token].escrow || 0) + amount;
              if (AppState.balances.SOL) {
                AppState.balances.SOL.available = Math.max(0, AppState.balances.SOL.available - gasSol);
              }
            }
          }

          // Prepend optimistically to historyTransfers as well
          if (Array.isArray(AppState.historyTransfers)) {
            AppState.historyTransfers = AppState.historyTransfers.filter(t => t.id !== transferId);
            AppState.historyTransfers.unshift({ ...newTransfer });
          }

          // 4. Immediately render in memory!
          renderHeaderAndBalances();
          renderPendingTransfers();
          renderHistoryTable();

          // Reset form inputs & retain selected token
          const prevToken = token;
          form.reset();
          selectTransferToken(prevToken, false);

          // 5. Transition the modal to the Phantom Success Receipt view immediately
          showTransferSuccessReceipt(res, { token, amount, destination, windowMinutes, note });

          // 6. Asynchronous non-blocking background sync
          (async () => {
            try {
              const [bRes, pRes, hRes] = await Promise.all([
                apiFetch('/api/transfers/balances').catch(() => null),
                apiFetch('/api/transfers/pending').catch(() => null),
                apiFetch('/api/transfers/history').catch(() => null)
              ]);
              if (bRes && bRes.success) {
                const p = bRes.data || bRes;
                if (p.balances) AppState.balances = p.balances;
              }
              if (pRes && pRes.success) {
                const p = pRes.data || pRes;
                const fresh = p.transfers || p.pending || [];
                const validFresh = fresh.filter(t => t.status === 'pending' && calculateRemainingSeconds(t) > 0);
                if (!validFresh.some(t => t.id == transferId)) {
                  validFresh.unshift(newTransfer);
                }
                AppState.pendingTransfers = validFresh;
              }
              if (hRes && hRes.success) {
                const p = hRes.data || hRes;
                const freshHistory = p.transfers || p.history || [];
                if (!freshHistory.some(t => t.id == transferId)) {
                  freshHistory.unshift(newTransfer);
                }
                AppState.historyTransfers = freshHistory;
              }
              renderHeaderAndBalances();
              renderPendingTransfers();
              renderHistoryTable();
            } catch (syncErr) {
              console.debug('Background sync notice:', syncErr);
            }
          })();

        } else {
          closeTransferReceiptModal();
          showToast(res.error || 'Failed to initiate transfer', 'error');
        }
      } catch (err) {
        closeTransferReceiptModal();
        showToast(err.message || 'Network error occurred', 'error');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (submitBtnSpinner) submitBtnSpinner.classList.add('hidden');
        if (submitBtnText) submitBtnText.textContent = 'Initiate Transfer';
      }
    });

    // Initialize notice display
    const initTok = tokenSelect ? tokenSelect.value : 'SOL';
    selectTransferToken(initTok, false);
  }

  // Current Receipt Data Cache for Copy & Navigation Actions
  let currentReceiptData = null;

  // Format timelock minutes into badge label (e.g., "10m Undo Window Active")
  function formatBadgeDuration(minutes) {
    const m = parseInt(minutes, 10) || 10;
    if (m >= 525600) {
      const y = Math.round(m / 525600);
      return `${y}y Undo Window Active`;
    }
    if (m >= 43200) {
      const mo = Math.round(m / 43200);
      return `${mo}mo Undo Window Active`;
    }
    if (m >= 10080) {
      const w = Math.round(m / 10080);
      return `${w}w Undo Window Active`;
    }
    if (m >= 1440) {
      const d = Math.round(m / 1440);
      return `${d}d Undo Window Active`;
    }
    if (m >= 60) {
      const h = Math.round(m / 60);
      return `${h}h Undo Window Active`;
    }
    return `${m}m Undo Window Active`;
  }
  window.formatBadgeDuration = formatBadgeDuration;

  // Open Full-Fidelity Loading Screen Modal with smooth fade
  function openTransferLoadingModal(data = {}) {
    const modal = document.getElementById('transferReceiptModal');
    const loadingView = document.getElementById('transferReceiptLoadingView');
    const successView = document.getElementById('transferReceiptSuccessView');
    const closeBtn = document.getElementById('transferReceiptCloseBtn');

    if (loadingView) loadingView.classList.remove('hidden');
    if (successView) successView.classList.add('hidden');
    if (closeBtn) closeBtn.classList.add('hidden');

    const stepEl = document.getElementById('transferReceiptLoadingStep');
    if (stepEl) {
      stepEl.textContent = 'Validating timelock & reserving escrow balance...';
    }

    if (modal) {
      modal.style.transition = 'opacity 0.25s ease';
      modal.style.opacity = '0';
      modal.classList.remove('hidden');
      requestAnimationFrame(() => {
        modal.style.opacity = '1';
      });
    }
  }
  window.openTransferLoadingModal = openTransferLoadingModal;

  // Close Transfer Receipt Modal with smooth CSS fade
  function closeTransferReceiptModal() {
    const modal = document.getElementById('transferReceiptModal');
    if (!modal) return;
    modal.style.transition = 'opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1)';
    modal.style.opacity = '0';
    setTimeout(() => {
      modal.classList.add('hidden');
      modal.style.opacity = '1';
    }, 200);
  }
  window.closeTransferReceiptModal = closeTransferReceiptModal;

  // Show Gorgeous Phantom-Style Success Screen Modal
  function showTransferSuccessReceipt(responseData, requestData = {}) {
    const modal = document.getElementById('transferReceiptModal');
    const loadingView = document.getElementById('transferReceiptLoadingView');
    const successView = document.getElementById('transferReceiptSuccessView');
    const closeBtn = document.getElementById('transferReceiptCloseBtn');

    const payload = (responseData && responseData.data) ? responseData.data : (responseData || {});
    const token = payload.token || requestData.token || 'SOL';
    const amount = parseFloat(payload.amount !== undefined ? payload.amount : (requestData.amount || 0));
    const destination = payload.destination || requestData.destination || '';
    const note = payload.note !== undefined ? payload.note : (requestData.note || '');
    const windowMinutes = parseInt(payload.window_minutes || requestData.windowMinutes || 10, 10);
    const transferId = payload.transfer_id || payload.id;

    // Format Amount in JetBrains Mono
    const formattedAmt = formatAmount(amount, token === 'SOL' ? 4 : 2);
    // Share text amount: e.g. '0.10' for 0.1 SOL
    const shareAmt = (amount < 0.01) ? amount.toFixed(4) : (Math.round(amount * 100) / 100).toFixed(2);
    const shareText = `🛡️ Just sent ${shareAmt} ${token} protected by SolRecall undo window!`;

    const price = (AppState.balances[token] && AppState.balances[token].price)
      ? AppState.balances[token].price
      : (token === 'SOL' ? 185.0 : 1.0);
    const fiatVal = (amount * price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const windowBadgeText = formatBadgeDuration(windowMinutes);

    currentReceiptData = {
      transferId,
      token,
      amount,
      formattedAmt,
      shareAmt,
      destination,
      note,
      windowMinutes,
      windowBadgeText,
      shareText
    };

    // Populate Success Screen Elements
    const amtEl = document.getElementById('receiptAmount');
    if (amtEl) amtEl.textContent = formattedAmt;

    const tokEl = document.getElementById('receiptTokenSymbol');
    if (tokEl) tokEl.textContent = token;

    const fiatEl = document.getElementById('receiptFiatValue');
    if (fiatEl) fiatEl.textContent = `≈ $${fiatVal} USD`;

    const logoEl = document.getElementById('receiptTokenLogo');
    if (logoEl) {
      const tLogo = tokenLogos[token] || tokenLogos.SOL;
      logoEl.src = tLogo.url;
      logoEl.onerror = () => { logoEl.src = tLogo.local; };
    }

    const destEl = document.getElementById('receiptDestinationTruncated');
    if (destEl) {
      destEl.textContent = truncateAddress(destination, 6, 6);
      destEl.title = destination;
    }

    const badgeTextEl = document.getElementById('receiptWindowText');
    if (badgeTextEl) badgeTextEl.textContent = windowBadgeText;

    const noteRow = document.getElementById('receiptNoteRow');
    const noteText = document.getElementById('receiptNoteText');
    if (noteRow && noteText) {
      if (note) {
        noteText.textContent = note;
        noteRow.classList.remove('hidden');
      } else {
        noteRow.classList.add('hidden');
      }
    }

    // Update Status Badge in Receipt
    const status = payload.status || 'pending';
    const statusBadgeEl = document.getElementById('receiptStatusBadge');
    const statusTextEl = document.getElementById('receiptStatusText');
    const statusDotEl = document.getElementById('receiptStatusDot');

    if (statusBadgeEl && statusTextEl) {
      if (status === 'completed') {
        statusTextEl.textContent = 'Completed on Solana';
        statusBadgeEl.className = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30';
        if (statusDotEl) statusDotEl.className = 'w-1.5 h-1.5 rounded-full bg-emerald-400';
      } else if (status === 'cancelled') {
        statusTextEl.textContent = 'Cancelled & Refunded';
        statusBadgeEl.className = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-500/15 text-slate-300 border border-slate-500/30';
        if (statusDotEl) statusDotEl.className = 'w-1.5 h-1.5 rounded-full bg-slate-400';
      } else if (status === 'failed') {
        statusTextEl.textContent = 'Failed';
        statusBadgeEl.className = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/15 text-rose-300 border border-rose-500/30';
        if (statusDotEl) statusDotEl.className = 'w-1.5 h-1.5 rounded-full bg-rose-400';
      } else {
        statusTextEl.textContent = 'Pending Escrow';
        statusBadgeEl.className = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30';
        if (statusDotEl) statusDotEl.className = 'w-1.5 h-1.5 rounded-full bg-amber-400';
      }
    }

    // Update Platform Fee (12%) & Network Gas in Receipt
    const fee12 = amount * 0.12;
    const feeFormatted = token === 'SOL' ? fee12.toFixed(4) : fee12.toFixed(2);
    const feeEl = document.getElementById('receiptFeeValue');
    if (feeEl) feeEl.textContent = `+${feeFormatted} ${token}`;

    const gasEl = document.getElementById('receiptGasValue');
    if (gasEl) gasEl.textContent = '0.000005 SOL';

    // Update Solana Mainnet Explorer Link in Receipt
    const explorerLinkEl = document.getElementById('receiptExplorerLink');
    const explorerLinkTextEl = document.getElementById('receiptExplorerLinkText');
    if (explorerLinkEl) {
      if (payload.tx_signature) {
        explorerLinkEl.href = `https://solscan.io/tx/${payload.tx_signature}`;
        if (explorerLinkTextEl) explorerLinkTextEl.textContent = `Tx: ${truncateAddress(payload.tx_signature, 4, 4)} ↗`;
      } else if (destination) {
        explorerLinkEl.href = `https://solscan.io/account/${destination}`;
        if (explorerLinkTextEl) explorerLinkTextEl.textContent = 'Recipient on Solscan ↗';
      } else {
        explorerLinkEl.href = 'https://solscan.io/';
        if (explorerLinkTextEl) explorerLinkTextEl.textContent = 'View on Solscan ↗';
      }
    }

    // Reset share button text
    const shareBtnText = document.getElementById('receiptCopyShareBtnText');
    if (shareBtnText) shareBtnText.textContent = 'Copy Share Text';

    // Transition views smoothly
    if (loadingView) loadingView.classList.add('hidden');
    if (successView) successView.classList.remove('hidden');
    if (closeBtn) closeBtn.classList.remove('hidden');
    if (modal) modal.classList.remove('hidden');
  }
  window.showTransferSuccessReceipt = showTransferSuccessReceipt;

  // View existing transfer receipt from Active Holds or History
  function openTransferReceiptById(transferId) {
    if (!transferId) return;
    let t = AppState.pendingTransfers.find(x => x.id == transferId);
    if (!t) {
      t = AppState.historyTransfers.find(x => x.id == transferId);
    }
    if (!t) {
      showToast('Transfer record not found', 'warning');
      return;
    }
    const winMins = t.total_seconds ? Math.max(1, Math.round(t.total_seconds / 60)) : (AppState.settings.undoWindowMinutes || 10);
    showTransferSuccessReceipt(t, {
      token: t.token,
      amount: t.amount,
      destination: t.destination,
      windowMinutes: winMins,
      note: t.note || ''
    });
  }
  window.openTransferReceiptById = openTransferReceiptById;

  // Copy Recipient Address from Receipt Modal
  async function copyReceiptDestination() {
    if (currentReceiptData && currentReceiptData.destination) {
      await copyToClipboard(currentReceiptData.destination, 'Recipient address');
    }
  }
  window.copyReceiptDestination = copyReceiptDestination;

  // Copy Share Text ('🛡️ Just sent 0.10 SOL protected by SolRecall undo window!')
  async function copyReceiptShareText() {
    if (!currentReceiptData || !currentReceiptData.shareText) return;
    const copied = await copyToClipboard(currentReceiptData.shareText, 'Share text');
    if (copied) {
      const btnText = document.getElementById('receiptCopyShareBtnText');
      if (btnText) {
        const orig = btnText.textContent;
        btnText.textContent = 'Copied Share Text! 🎉';
        setTimeout(() => {
          btnText.textContent = orig;
        }, 2000);
      }
    }
  }
  window.copyReceiptShareText = copyReceiptShareText;

  // View newly created hold in Active Holds section (Immediate transition, zero delay, glowing amber border pulse)
  function viewReceiptInActiveHolds() {
    closeTransferReceiptModal();
    if (typeof window.switchTab === 'function') {
      window.switchTab('overview', false);
    }

    const triggerHighlight = () => {
      const holdsContainer = document.getElementById('pendingTransfersContainer') || document.getElementById('tab-content-overview');
      if (currentReceiptData && currentReceiptData.transferId) {
        const card = document.getElementById(`pending-card-${currentReceiptData.transferId}`);
        if (card) {
          card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          card.classList.add('solana-border-glow', 'ring-2', 'ring-amber-400');
          setTimeout(() => {
            card.classList.remove('solana-border-glow', 'ring-2', 'ring-amber-400');
          }, 2500);
          return;
        }
      }
      if (holdsContainer) {
        holdsContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    };

    requestAnimationFrame(triggerHighlight);
  }
  window.viewReceiptInActiveHolds = viewReceiptInActiveHolds;

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

  // History Table Rendering & Filter (Supports Pending Escrow, Completed, Cancelled, Failed)
  function renderHistoryTable() {
    const tbody = document.getElementById('historyTableBody');
    const mobileList = document.getElementById('historyMobileList');
    const emptyRow = document.getElementById('historyEmptyRow');
    if (!tbody && !mobileList) return;

    const filter = AppState.currentHistoryFilter;
    const search = AppState.historySearchTerm.toLowerCase();

    // Consolidate history transfers and pending transfers so active escrow items are always visible
    const transferMap = new Map();

    // 1. Pending transfers (active escrow holds)
    if (Array.isArray(AppState.pendingTransfers)) {
      AppState.pendingTransfers.forEach(t => {
        if (t && t.id) {
          transferMap.set(String(t.id), { ...t, status: t.status || 'pending' });
        }
      });
    }

    // 2. History transfers (completed, cancelled, failed, and any synced pending)
    if (Array.isArray(AppState.historyTransfers)) {
      AppState.historyTransfers.forEach(t => {
        if (t && t.id) {
          const existing = transferMap.get(String(t.id));
          if (existing && existing.status === 'pending' && t.status === 'pending') {
            transferMap.set(String(t.id), { ...existing, ...t, status: 'pending' });
          } else {
            transferMap.set(String(t.id), t);
          }
        }
      });
    }

    const allTransfers = Array.from(transferMap.values()).sort((a, b) => {
      const dateA = new Date(a.created_at || 0).getTime();
      const dateB = new Date(b.created_at || 0).getTime();
      return dateB - dateA;
    });

    const filtered = allTransfers.filter(item => {
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
      if (tbody) tbody.innerHTML = '';
      if (mobileList) mobileList.innerHTML = '';
      if (emptyRow) emptyRow.classList.remove('hidden');
      return;
    }

    if (emptyRow) emptyRow.classList.add('hidden');

    // Build Desktop Table HTML
    if (tbody) {
      tbody.innerHTML = filtered.map(t => {
        // Status badge
        let badgeClass = 'badge-completed';
        let statusLabel = 'Completed';
        let icon = `<svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>`;

        if (t.status === 'cancelled') {
          badgeClass = 'badge-cancelled';
          statusLabel = 'Cancelled';
          icon = `<svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>`;
        } else if (t.status === 'failed') {
          badgeClass = 'badge-failed';
          statusLabel = 'Failed';
          icon = `<svg class="w-3.5 h-3.5 text-rose-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
        } else if (t.status === 'pending') {
          badgeClass = 'badge-pending';
          statusLabel = 'Pending Escrow';
          icon = `<svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`;
        } else if (t.status === 'processing') {
          badgeClass = 'badge-processing';
          statusLabel = 'Broadcasting';
          icon = `<span class="spinner-sm inline-block"></span>`;
        }

        const dateStr = t.created_at ? new Date(t.created_at).toLocaleString('en-US', {
          month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        }) : 'Recent';

        let explorerHtml = `<span class="text-slate-500 text-xs">—</span>`;
        if (t.tx_signature) {
          const explorerUrl = `https://solscan.io/tx/${t.tx_signature}`;
          explorerHtml = `
            <a href="${explorerUrl}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs text-purple-400 hover:text-purple-300 font-mono transition" title="View on Solscan">
              <span>${truncateAddress(t.tx_signature, 4, 4)}</span>
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
          `;
        } else if (t.status === 'pending') {
          const explorerUrl = `https://solscan.io/account/${t.destination}`;
          explorerHtml = `
            <a href="${explorerUrl}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs text-amber-400 hover:text-amber-300 font-mono transition" title="View recipient address on Solscan">
              <span>Escrow Hold</span>
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
          `;
        }

        const tLogo = tokenLogos[t.token] || tokenLogos.SOL;

        return `
          <tr class="border-b border-white/5 hover:bg-white/[0.02] transition text-sm">
            <td class="py-3.5 px-4 text-slate-400 text-xs whitespace-nowrap">${dateStr}</td>
            <td class="py-3.5 px-4">
              <div class="flex items-center gap-2 font-semibold text-white font-mono">
                <img src="${tLogo.url}" onerror="this.src='${tLogo.local}'" alt="${t.token}" class="w-5 h-5 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-white/10">
                <span>${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} <span class="text-xs text-slate-400 font-sans font-normal">${t.token}</span></span>
              </div>
            </td>
            <td class="py-3.5 px-4 font-mono text-xs text-slate-300">
              <div class="flex items-center gap-1.5">
                <span>${truncateAddress(t.destination, 4, 4)}</span>
                <button type="button" class="touch-target w-7 h-7 text-slate-400 hover:text-white rounded transition flex items-center justify-center cursor-pointer" onclick="copyToClipboard('${t.destination}', 'Address')">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
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
            <td class="py-3.5 px-4 whitespace-nowrap">
              <div class="flex items-center gap-2.5">
                ${explorerHtml}
                <button type="button"
                        onclick="openTransferReceiptById(${t.id})"
                        class="inline-flex items-center gap-1 text-xs text-purple-400 hover:text-purple-300 font-mono transition cursor-pointer"
                        title="View Full Receipt">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                  <span>Receipt</span>
                </button>
              </div>
            </td>
          </tr>
        `;
      }).join('');
    }

    // Build Mobile High-Conviction Compact Cards HTML
    if (mobileList) {
      mobileList.innerHTML = filtered.map(t => {
        let badgeClass = 'badge-completed';
        let statusLabel = 'Completed';
        let icon = `<svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>`;

        if (t.status === 'cancelled') {
          badgeClass = 'badge-cancelled';
          statusLabel = 'Cancelled';
          icon = `<svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>`;
        } else if (t.status === 'failed') {
          badgeClass = 'badge-failed';
          statusLabel = 'Failed';
          icon = `<svg class="w-3.5 h-3.5 text-rose-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
        } else if (t.status === 'pending') {
          badgeClass = 'badge-pending';
          statusLabel = 'Pending Escrow';
          icon = `<svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`;
        } else if (t.status === 'processing') {
          badgeClass = 'badge-processing';
          statusLabel = 'Broadcasting';
          icon = `<span class="spinner-sm inline-block"></span>`;
        }

        const dateStr = t.created_at ? new Date(t.created_at).toLocaleString('en-US', {
          month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        }) : 'Recent';

        const tLogo = tokenLogos[t.token] || tokenLogos.SOL;

        let explorerBtn = '';
        if (t.tx_signature) {
          explorerBtn = `
            <a href="https://solscan.io/tx/${t.tx_signature}" target="_blank" rel="noopener noreferrer"
               class="touch-target min-h-[44px] w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-slate-800/80 hover:bg-slate-700/80 text-purple-300 hover:text-white border border-white/10 transition cursor-pointer">
              <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              <span>Solscan ↗</span>
            </a>
          `;
        } else if (t.status === 'pending') {
          explorerBtn = `
            <a href="https://solscan.io/account/${t.destination}" target="_blank" rel="noopener noreferrer"
               class="touch-target min-h-[44px] w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 hover:text-white border border-amber-500/30 transition cursor-pointer">
              <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              <span>In Escrow</span>
            </a>
          `;
        } else {
          explorerBtn = `
            <span class="touch-target min-h-[44px] w-full inline-flex items-center justify-center text-xs text-slate-500 bg-slate-900/50 rounded-xl border border-white/5">
              Settled Off-Chain
            </span>
          `;
        }

        return `
          <div class="glass-panel glass-panel-hover rounded-2xl p-4 border border-white/10 space-y-3">
            <!-- Top Row: Token, Amount, Status Badge -->
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2">
                <img src="${tLogo.url}" onerror="this.src='${tLogo.local}'" alt="${t.token}" class="w-7 h-7 rounded-full object-contain shrink-0 p-0.5 bg-black/40 border border-white/10">
                <span class="font-mono text-base font-extrabold text-white">
                  ${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} <span class="text-xs text-slate-400 font-sans font-normal">${t.token}</span>
                </span>
              </div>
              <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${badgeClass}">
                ${icon}
                <span>${statusLabel}</span>
              </span>
            </div>

            <!-- Recipient Row with Copy Button (Touch target >= 44px) -->
            <div class="flex items-center justify-between text-xs bg-slate-900/60 px-3 py-1.5 rounded-xl border border-white/5">
              <div class="flex items-center gap-1.5 truncate">
                <span class="text-slate-400">To:</span>
                <span class="font-mono text-purple-300 font-semibold truncate" title="${t.destination}">${truncateAddress(t.destination, 6, 6)}</span>
              </div>
              <button type="button"
                      class="touch-target w-10 h-10 min-w-[40px] min-h-[40px] text-slate-400 hover:text-white rounded-lg hover:bg-white/10 transition flex items-center justify-center cursor-pointer shrink-0"
                      onclick="copyToClipboard('${t.destination}', 'Recipient Address')"
                      title="Copy address">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
              </button>
            </div>

            <!-- Date & Memo -->
            <div class="flex items-center justify-between text-[11px] text-slate-400 px-1">
              <span>${dateStr}</span>
              ${t.note ? `<span class="text-slate-300 truncate max-w-[150px]">“${escapeHtml(t.note)}”</span>` : ''}
            </div>

            <!-- Bottom Actions: Solscan Explorer & Receipt Buttons (>= 44px Touch Targets) -->
            <div class="grid grid-cols-2 gap-2 pt-1 border-t border-white/5">
              ${explorerBtn}
              <button type="button"
                      onclick="openTransferReceiptById(${t.id})"
                      class="touch-target min-h-[44px] w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-purple-500/10 hover:bg-purple-500/20 text-purple-300 hover:text-white border border-purple-500/30 transition cursor-pointer active:scale-95">
                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Receipt</span>
              </button>
            </div>
          </div>
        `;
      }).join('');
    }
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
    apiFetch('/api/auth/2fa/generate').then(res => {
      const payload = res.data || res;
      const qrImg = document.getElementById('twoFaQrImage');
      const secretText = document.getElementById('twoFaSecretText');
      if (qrImg && payload.qr_code_url) qrImg.src = payload.qr_code_url;
      if (secretText && payload.secret) secretText.textContent = payload.secret;
    }).catch(err => {
      console.warn('2FA generate notice:', err.message);
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
        update2FaBadges(1);
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

  // 2FA Badge & Status Synchronizer
  function update2FaBadges(enabled) {
    const isEnabled = !!Number(enabled);

    const twoFaBadge = document.getElementById('twoFaHeaderBadge');
    if (twoFaBadge) {
      twoFaBadge.innerHTML = isEnabled
        ? `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
             <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> 2FA Secured
           </span>`
        : `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
             <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> 2FA Inactive
           </span>`;
    }

    const settingsBadge = document.getElementById('settingsTwoFaBadge');
    if (settingsBadge) {
      settingsBadge.textContent = isEnabled ? 'Active' : 'Inactive';
      settingsBadge.className = isEnabled
        ? 'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
        : 'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30';
    }

    const deleteBtn = document.getElementById('btnDelete2Fa');
    if (deleteBtn) {
      if (isEnabled) {
        deleteBtn.classList.remove('hidden');
      } else {
        deleteBtn.classList.add('hidden');
      }
    }
  }
  window.update2FaBadges = update2FaBadges;

  // Delete / Reset 2FA Key Modal Handlers
  function openDelete2FaModal() {
    const modal = document.getElementById('delete2FaModal');
    if (!modal) return;
    const pwd = document.getElementById('delete2FaPassword');
    const totp = document.getElementById('delete2FaTotp');
    if (pwd) pwd.value = '';
    if (totp) totp.value = '';
    modal.classList.remove('hidden');
    if (pwd) pwd.focus();
  }
  window.openDelete2FaModal = openDelete2FaModal;

  function closeDelete2FaModal() {
    const modal = document.getElementById('delete2FaModal');
    if (modal) modal.classList.add('hidden');
  }
  window.closeDelete2FaModal = closeDelete2FaModal;

  async function submitDelete2Fa() {
    const pwdInput = document.getElementById('delete2FaPassword');
    const totpInput = document.getElementById('delete2FaTotp');
    const password = pwdInput ? pwdInput.value : '';
    const code = totpInput ? totpInput.value.trim() : '';

    if (!password && !code) {
      showToast('Please enter your account password and/or 6-digit 2FA code to confirm removal.', 'warning');
      return;
    }

    const btn = document.getElementById('confirmDelete2FaBtn');
    const spinner = document.getElementById('delete2FaSpinner');
    if (btn) btn.disabled = true;
    if (spinner) spinner.classList.remove('hidden');

    try {
      const res = await apiFetch('/api/auth/2fa/disable', {
        method: 'POST',
        body: JSON.stringify({ password, code })
      });

      if (res.success) {
        showToast('Two-Factor Authentication key deleted successfully.', 'success');
        if (AppState.user) {
          AppState.user.two_factor_enabled = 0;
        }
        update2FaBadges(0);
        closeDelete2FaModal();
      } else {
        showToast(res.error || res.message || 'Failed to delete 2FA key. Please verify credentials.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Network error disabling 2FA.', 'error');
    } finally {
      if (btn) btn.disabled = false;
      if (spinner) spinner.classList.add('hidden');
    }
  }
  window.submitDelete2Fa = submitDelete2Fa;

  // Export Custodial Private Key Flow
  let currentExportedPrivateKey = '';
  let isPrivateKeyRevealed = false;

  function openExportKeyModal() {
    const modal = document.getElementById('exportKeyModal');
    if (modal) {
      // Always reset to initial state
      currentExportedPrivateKey = '';
      isPrivateKeyRevealed = false;

      const pwdInput = document.getElementById('exportKeyPassword');
      const totpInput = document.getElementById('exportKeyTotp');
      if (pwdInput) pwdInput.value = '';
      if (totpInput) totpInput.value = '';

      const keyDisplay = document.getElementById('rawPrivateKeyDisplay');
      if (keyDisplay) keyDisplay.textContent = 'Loading...';

      const blurBox = document.getElementById('privateKeyBlurBox');
      if (blurBox) {
        blurBox.classList.remove('key-revealed', 'blur-0', 'select-all', 'pointer-events-auto');
        blurBox.classList.add('key-blurred', 'blur-md', 'select-none', 'pointer-events-none');
      }

      const overlay = document.getElementById('privateKeyOverlay');
      if (overlay) overlay.classList.remove('hidden');

      const badge = document.getElementById('keyStatusBadge');
      if (badge) {
        badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span><span>Encrypted in View</span>`;
        badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/20 text-rose-300 border border-rose-500/30 flex items-center gap-1';
      }

      const revealBtn = document.getElementById('revealCopyKeyBtn');
      if (revealBtn) {
        revealBtn.innerHTML = `
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          <span>Reveal Private Key</span>
        `;
        revealBtn.className = 'w-full py-3 px-4 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:to-indigo-500 shadow-lg shadow-purple-600/25 flex items-center justify-center gap-2 transition-all duration-200 cursor-pointer';
      }

      const step1 = document.getElementById('exportKeyStep1');
      const step2 = document.getElementById('exportKeyStep2');
      if (step1) step1.classList.remove('hidden');
      if (step2) step2.classList.add('hidden');
      modal.classList.remove('hidden');

      if (totpInput) {
        setTimeout(() => {
          if (pwdInput && !pwdInput.value) pwdInput.focus();
          else totpInput.focus();
        }, 100);
      }
    }
  }
  window.openExportKeyModal = openExportKeyModal;

  function closeExportKeyModal() {
    const modal = document.getElementById('exportKeyModal');
    if (modal) modal.classList.add('hidden');

    // Securely purge sensitive private key from memory and DOM
    currentExportedPrivateKey = '';
    isPrivateKeyRevealed = false;

    const keyDisplay = document.getElementById('rawPrivateKeyDisplay');
    if (keyDisplay) keyDisplay.textContent = '';

    const blurBox = document.getElementById('privateKeyBlurBox');
    if (blurBox) {
      blurBox.classList.remove('key-revealed', 'blur-0', 'select-all', 'pointer-events-auto');
      blurBox.classList.add('key-blurred', 'blur-md', 'select-none', 'pointer-events-none');
    }

    const overlay = document.getElementById('privateKeyOverlay');
    if (overlay) overlay.classList.remove('hidden');

    const badge = document.getElementById('keyStatusBadge');
    if (badge) {
      badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span><span>Encrypted in View</span>`;
      badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/20 text-rose-300 border border-rose-500/30 flex items-center gap-1';
    }

    const revealBtn = document.getElementById('revealCopyKeyBtn');
    if (revealBtn) {
      revealBtn.innerHTML = `
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>Reveal Private Key</span>
      `;
      revealBtn.className = 'w-full py-3 px-4 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:to-indigo-500 shadow-lg shadow-purple-600/25 flex items-center justify-center gap-2 transition-all duration-200 cursor-pointer';
    }

    const pwdInput = document.getElementById('exportKeyPassword');
    const totpInput = document.getElementById('exportKeyTotp');
    if (pwdInput) pwdInput.value = '';
    if (totpInput) totpInput.value = '';

    const step1 = document.getElementById('exportKeyStep1');
    const step2 = document.getElementById('exportKeyStep2');
    if (step1) step1.classList.remove('hidden');
    if (step2) step2.classList.add('hidden');
  }
  window.closeExportKeyModal = closeExportKeyModal;

  async function verifyAndRevealKey() {
    const pwdInput = document.getElementById('exportKeyPassword');
    const totpInput = document.getElementById('exportKeyTotp');
    const pwd = pwdInput ? pwdInput.value.trim() : '';
    const totp = totpInput ? totpInput.value.trim() : '';

    if (!totp) {
      showToast('Please enter your 6-digit 2FA authenticator code.', 'warning');
      if (totpInput) totpInput.focus();
      return;
    }

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

      const payload = res.data || res;
      const key = payload.privateKeyBase58 || payload.private_key || payload.secret_key || res.privateKeyBase58 || res.private_key;

      if (res.success && key) {
        currentExportedPrivateKey = key;
        isPrivateKeyRevealed = false;

        const step1 = document.getElementById('exportKeyStep1');
        const step2 = document.getElementById('exportKeyStep2');
        const keyDisplay = document.getElementById('rawPrivateKeyDisplay');
        const addressDisplay = document.getElementById('exportKeyDepositAddress');

        if (addressDisplay) {
          addressDisplay.textContent = payload.depositAddress || payload.deposit_address || (AppState.user && AppState.user.solana_deposit_address) || 'Your Custodial Wallet';
        }

        if (keyDisplay) {
          keyDisplay.textContent = key;
        }

        // Ensure key begins blurred
        const blurBox = document.getElementById('privateKeyBlurBox');
        if (blurBox) {
          blurBox.classList.remove('key-revealed', 'blur-0', 'select-all', 'pointer-events-auto');
          blurBox.classList.add('key-blurred', 'blur-md', 'select-none', 'pointer-events-none');
        }

        const overlay = document.getElementById('privateKeyOverlay');
        if (overlay) overlay.classList.remove('hidden');

        const badge = document.getElementById('keyStatusBadge');
        if (badge) {
          badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span><span>Encrypted in View</span>`;
          badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/20 text-rose-300 border border-rose-500/30 flex items-center gap-1';
        }

        const revealBtn = document.getElementById('revealCopyKeyBtn');
        if (revealBtn) {
          revealBtn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Reveal Private Key</span>
          `;
          revealBtn.className = 'w-full py-3 px-4 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-600 hover:from-purple-500 hover:to-indigo-500 shadow-lg shadow-purple-600/25 flex items-center justify-center gap-2 transition-all duration-200 cursor-pointer';
        }

        if (step1) step1.classList.add('hidden');
        if (step2) step2.classList.remove('hidden');

        showToast('Private key decrypted securely. Click Reveal to unblur.', 'success', 4000);
      } else {
        showToast(res.message || res.error || 'Verification failed. Incorrect credentials.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Verification failed.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `Verify & Reveal Key`;
      }
    }
  }
  window.verifyAndRevealKey = verifyAndRevealKey;

  function toggleRevealOrCopyKey() {
    const blurBox = document.getElementById('privateKeyBlurBox');
    const overlay = document.getElementById('privateKeyOverlay');
    const badge = document.getElementById('keyStatusBadge');
    const btn = document.getElementById('revealCopyKeyBtn');

    if (!isPrivateKeyRevealed) {
      // Step A: Reveal action (Unblur box and change button to Copy)
      isPrivateKeyRevealed = true;
      if (blurBox) {
        blurBox.classList.remove('key-blurred', 'blur-md', 'select-none', 'pointer-events-none');
        blurBox.classList.add('key-revealed', 'blur-0', 'select-all', 'pointer-events-auto');
      }
      if (overlay) overlay.classList.add('hidden');
      if (badge) {
        badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span><span>Revealed</span>`;
        badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center gap-1';
      }
      if (btn) {
        btn.innerHTML = `
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
          <span>Copy Private Key</span>
        `;
        btn.className = 'w-full py-3 px-4 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-600 hover:from-emerald-500 hover:to-teal-500 shadow-lg shadow-emerald-600/25 flex items-center justify-center gap-2 transition-all duration-200 cursor-pointer';
      }
      showToast('Private key unblurred. Keep your screen protected!', 'warning', 3500);
    } else {
      // Step B: Copy action
      if (currentExportedPrivateKey) {
        copyToClipboard(currentExportedPrivateKey, 'Private Key');
        if (btn) {
          const originalHtml = btn.innerHTML;
          const originalClass = btn.className;
          btn.innerHTML = `
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <span>Copied to Clipboard!</span>
          `;
          btn.className = 'w-full py-3 px-4 rounded-xl font-bold text-xs text-white bg-emerald-500 shadow-lg shadow-emerald-500/30 flex items-center justify-center gap-2 transition-all duration-200';
          setTimeout(() => {
            if (isPrivateKeyRevealed && btn) {
              btn.innerHTML = originalHtml;
              btn.className = originalClass;
            }
          }, 2000);
        }
      }
    }
  }
  window.toggleRevealOrCopyKey = toggleRevealOrCopyKey;

  // =========================================================================
  // Administrator Operations (Live Platform Hot Wallet, Escrow Ledger, Overrides)
  // =========================================================================

  let adminTransfersCache = [];

  let currentDevWalletData = null;
  let devKeyExportData = null;

  async function loadAdminData(force = false) {
    const isAdmin = (AppState.user && (AppState.user.is_admin === 1 || AppState.user.is_admin === '1' || AppState.user.is_admin === true)) ||
                    (window.__INITIAL_STATE__ && window.__INITIAL_STATE__.user && (window.__INITIAL_STATE__.user.is_admin == 1));
    if (!isAdmin) {
      return;
    }

    try {
      const results = await Promise.allSettled([
        apiFetch('/api/admin/stats'),
        apiFetch('/api/admin/transfers'),
        apiFetch('/api/admin/dev-wallet')
      ]);

      const [statsRes, transfersRes, devWalletRes] = results;

      if (statsRes.status === 'fulfilled' && statsRes.value && statsRes.value.success && statsRes.value.data) {
        renderAdminStats(statsRes.value.data);
      } else if (statsRes.status === 'rejected') {
        console.warn('[Admin] Stats load notice:', statsRes.reason);
      }

      if (transfersRes.status === 'fulfilled' && transfersRes.value && transfersRes.value.success && transfersRes.value.data) {
        adminTransfersCache = transfersRes.value.data.transfers || [];
        renderAdminTransfers(adminTransfersCache);
      } else {
        if (transfersRes.status === 'rejected') {
          console.warn('[Admin] Transfers load notice:', transfersRes.reason);
        }
        renderAdminTransfers([]);
      }

      if (devWalletRes.status === 'fulfilled' && devWalletRes.value && devWalletRes.value.success && devWalletRes.value.data) {
        currentDevWalletData = devWalletRes.value.data;
        renderDevWalletData(devWalletRes.value.data);
      } else if (devWalletRes.status === 'rejected') {
        console.warn('[Admin] Dev wallet load notice:', devWalletRes.reason);
      }

      // Automatically load registered platform users
      try {
        await loadAdminUsers();
      } catch (e) {
        console.warn('[Admin] Failed to load admin users:', e);
      }
    } catch (err) {
      console.warn('[Admin] Failed to load admin operations data:', err);
      renderAdminTransfers([]);
    }
  }
  window.loadAdminData = loadAdminData;

  function renderAdminStats(data) {
    const hw = data.hot_wallet || {};
    const esc = data.active_escrow || {};
    const pf = data.platform || {};

    const hwSolEl = document.getElementById('adminHotWalletSol');
    const hwAddrEl = document.getElementById('adminHotWalletAddrShort');
    const hwLinkEl = document.getElementById('adminHotWalletExplorerLink');

    if (hwSolEl) {
      hwSolEl.innerHTML = `${formatAmount(hw.sol_balance || 0, 4)} <span class="text-xs text-slate-400 font-normal">SOL</span>`;
    }
    if (hwAddrEl) {
      const addr = hw.address || '';
      hwAddrEl.textContent = addr ? `${addr.slice(0, 4)}...${addr.slice(-4)}` : 'N/A';
      hwAddrEl.title = addr;
    }
    if (hwLinkEl && hw.address) {
      hwLinkEl.href = `https://explorer.solana.com/address/${hw.address}`;
    }

    // Active escrow card
    const escEl = document.getElementById('adminActiveEscrow');
    const escBadge = document.getElementById('adminActiveHoldsBadge');

    if (escEl) {
      const sol = esc.SOL || 0;
      const usdc = esc.USDC || 0;
      const usdt = esc.USDT || 0;
      const stables = usdc + usdt;

      if (sol > 0 && stables > 0) {
        escEl.textContent = `${formatAmount(sol, 4)} SOL + $${formatAmount(stables, 2)}`;
      } else if (sol > 0) {
        escEl.textContent = `${formatAmount(sol, 4)} SOL`;
      } else if (stables > 0) {
        escEl.textContent = `$${formatAmount(stables, 2)} Stables`;
      } else {
        escEl.textContent = '0.0000 SOL';
      }
    }

    if (escBadge) {
      const count = esc.total_holds || 0;
      escBadge.textContent = `${count} Hold${count === 1 ? '' : 's'}`;
    }

    // Platform revenue
    const feeEl = document.getElementById('adminFeeRevenue');
    const compCountEl = document.getElementById('adminCompletedCount');
    if (feeEl) {
      feeEl.textContent = `${formatAmount(pf.fee_revenue_sol || 0, 4)} SOL`;
    }
    if (compCountEl) {
      compCountEl.textContent = `From ${pf.completed_transfers || 0} completed transfers`;
    }

    // Total Users
    const usersEl = document.getElementById('adminTotalUsers');
    if (usersEl) {
      usersEl.innerHTML = `${pf.total_users || 0} <span class="text-xs text-slate-400 font-normal">Registered Users</span>`;
    }
  }

  function renderAdminTransfers(transfers) {
    const tbody = document.getElementById('adminTransfersTableBody');
    const emptyState = document.getElementById('adminTransfersEmpty');
    const countLabel = document.getElementById('adminTableCountLabel');

    if (!tbody) return;

    if (!Array.isArray(transfers) || transfers.length === 0) {
      tbody.innerHTML = '';
      if (emptyState) emptyState.classList.remove('hidden');
      if (countLabel) countLabel.textContent = 'Showing 0 transfers';
      return;
    }

    if (emptyState) emptyState.classList.add('hidden');
    if (countLabel) countLabel.textContent = `Showing ${transfers.length} active hold${transfers.length === 1 ? '' : 's'}`;

    tbody.innerHTML = transfers.map(t => {
      const tokenLogo = (tokenLogos[t.token] || tokenLogos.SOL).url;
      const remainingSec = t.seconds_remaining;
      const isUrgent = remainingSec <= 60;
      const shortDest = t.destination ? `${t.destination.slice(0, 4)}...${t.destination.slice(-4)}` : '';

      return `
        <tr class="hover:bg-white/[0.02] transition">
          <td class="py-3 px-3 font-mono font-bold text-purple-400">#TX-${t.id}</td>
          <td class="py-3 px-3">
            <div class="font-medium text-white text-xs">${escapeHtml(t.username || 'User')}</div>
            <div class="text-[10px] text-slate-400 font-mono">${escapeHtml(t.email || '')}</div>
          </td>
          <td class="py-3 px-3">
            <span class="inline-flex items-center gap-1.5 font-bold font-mono text-white text-xs">
              <img src="${tokenLogo}" alt="${t.token}" class="w-4 h-4 rounded-full inline">
              ${formatAmount(t.amount, t.token === 'SOL' ? 4 : 2)} ${t.token}
            </span>
          </td>
          <td class="py-3 px-3 font-mono text-amber-400 text-xs font-semibold">
            +${formatAmount(t.platform_fee, t.token === 'SOL' ? 4 : 2)} ${t.token}
          </td>
          <td class="py-3 px-3 font-mono text-slate-300 text-xs">
            <span title="${t.destination}">${shortDest}</span>
            <button type="button" onclick="copyToClipboard('${t.destination}', 'Destination')" class="text-slate-500 hover:text-white ml-1 inline-flex items-center" title="Copy"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg></button>
          </td>
          <td class="py-3 px-3">
            <span class="inline-flex items-center gap-1 font-mono text-xs font-bold ${isUrgent ? 'text-rose-400' : 'text-amber-300'}">
              <svg class="w-3 h-3 text-amber-400 inline shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${formatMMSS(remainingSec)}
            </span>
          </td>
          <td class="py-3 px-3 text-right">
            <div class="flex items-center justify-end gap-1.5">
              <button type="button"
                      onclick="adminForceBroadcast(${t.id})"
                      class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-purple-600/20 text-purple-300 border border-purple-500/30 hover:bg-purple-600/40 transition cursor-pointer"
                      title="Force immediate broadcast on Solana">
                Release Now
              </button>
              <button type="button"
                      onclick="adminForceCancel(${t.id})"
                      class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-rose-600/20 text-rose-300 border border-rose-500/30 hover:bg-rose-600/40 transition cursor-pointer"
                      title="Revoke and refund user available balance">
                Refund
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  async function adminTriggerCron() {
    const btn = document.getElementById('btnAdminTriggerCron');
    const spinner = document.getElementById('adminCronSpinner');
    const icon = document.getElementById('adminCronIcon');

    if (btn) btn.disabled = true;
    if (spinner) spinner.classList.remove('hidden');
    if (icon) icon.classList.add('hidden');

    try {
      const res = await apiFetch('/api/admin/trigger-cron', { method: 'POST' });
      if (res && res.success) {
        const d = res.data || {};
        showToast(`Broadcaster executed: ${d.processed || 0} processed, ${d.deposits_credited || 0} deposits credited (${d.duration_ms || 0}ms)`, 'success', 4000);
        await loadAdminData(true);
      } else {
        showToast(res.error || 'Failed to trigger broadcaster cycle.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Network error triggering broadcaster.', 'error');
    } finally {
      if (btn) btn.disabled = false;
      if (spinner) spinner.classList.add('hidden');
      if (icon) icon.classList.remove('hidden');
    }
  }
  window.adminTriggerCron = adminTriggerCron;

  async function adminForceCancel(transferId) {
    if (!confirm(`Are you sure you want to FORCE-CANCEL transfer #${transferId}?\n\nThis will instantly refund the user's principal, platform fee, and network gas to their available balance.`)) {
      return;
    }

    try {
      const res = await apiFetch('/api/admin/force-cancel', {
        method: 'POST',
        body: JSON.stringify({ transfer_id: transferId })
      });

      if (res && res.success) {
        showToast(res.data?.message || `Transfer #${transferId} refunded.`, 'success');
        await loadAdminData(true);
      } else {
        showToast(res.error || 'Failed to force-cancel transfer.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Network error.', 'error');
    }
  }
  window.adminForceCancel = adminForceCancel;

  async function adminForceBroadcast(transferId) {
    if (!confirm(`Are you sure you want to FORCE-BROADCAST transfer #${transferId} immediately on Solana Mainnet?\n\nThis will bypass any remaining timelock window and submit the transaction on-chain.`)) {
      return;
    }

    try {
      const res = await apiFetch('/api/admin/force-broadcast', {
        method: 'POST',
        body: JSON.stringify({ transfer_id: transferId })
      });

      if (res && res.success) {
        showToast(`Transfer #${transferId} broadcast! Signature: ${res.data?.tx_signature?.slice(0, 12)}...`, 'success', 5000);
        await loadAdminData(true);
      } else {
        showToast(res.error || 'Failed to broadcast transfer on-chain.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Network error.', 'error');
    }
  }
  window.adminForceBroadcast = adminForceBroadcast;

  async function loadAdminUsers() {
    const tbody = document.getElementById('adminUsersTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="7" class="py-6 text-center text-slate-500"><span class="spinner-sm inline-block mr-2"></span> Loading registered users from Supabase...</td></tr>`;

    try {
      const res = await apiFetch('/api/admin/users');
      if (res && res.success && res.data && Array.isArray(res.data.users)) {
        const users = res.data.users;
        if (users.length === 0) {
          tbody.innerHTML = `<tr><td colspan="7" class="py-6 text-center text-slate-500">No registered users found.</td></tr>`;
          return;
        }

        tbody.innerHTML = users.map(u => {
          const shortDep = u.solana_deposit_address ? `${u.solana_deposit_address.slice(0, 6)}...${u.solana_deposit_address.slice(-6)}` : 'None';
          const isAdmin = (intVal) => intVal === 1 || intVal === '1';

          return `
            <tr class="hover:bg-white/[0.02] transition">
              <td class="py-3 px-3 font-mono text-slate-400">#${u.id}</td>
              <td class="py-3 px-3">
                <div class="font-bold text-white text-xs">${escapeHtml(u.username)}</div>
                <div class="text-[10px] text-slate-400 font-mono">${escapeHtml(u.email)}</div>
              </td>
              <td class="py-3 px-3 font-mono text-purple-300 text-xs">
                <span>${shortDep}</span>
                ${u.solana_deposit_address ? `<button type="button" onclick="copyToClipboard('${u.solana_deposit_address}', 'Deposit Address')" class="text-slate-500 hover:text-white ml-1 inline-flex items-center" title="Copy"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg></button>` : ''}
              </td>
              <td class="py-3 px-3 font-mono font-bold text-white text-xs">${formatAmount(u.sol_avail || 0, 4)} SOL</td>
              <td class="py-3 px-3 font-mono text-blue-300 text-xs">$${formatAmount(u.usdc_avail || 0, 2)}</td>
              <td class="py-3 px-3 font-mono text-emerald-300 text-xs">$${formatAmount(u.usdt_avail || 0, 2)}</td>
              <td class="py-3 px-3">
                <div class="flex items-center gap-1.5 flex-wrap">
                  ${isAdmin(u.is_admin) ? '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">Admin</span>' : '<span class="px-2 py-0.5 rounded text-[10px] font-medium bg-slate-800 text-slate-400">User</span>'}
                  ${u.two_factor_enabled ? '<span class="px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-500/20 text-emerald-400">2FA</span>' : '<span class="px-2 py-0.5 rounded text-[10px] font-medium bg-slate-800 text-slate-500">No 2FA</span>'}
                </div>
              </td>
            </tr>
          `;
        }).join('');
      } else {
        tbody.innerHTML = `<tr><td colspan="7" class="py-6 text-center text-rose-400">Failed to load users: ${res?.error || 'Unknown error'}</td></tr>`;
      }
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="7" class="py-6 text-center text-rose-400">Error: ${e.message}</td></tr>`;
    }
  }
  window.loadAdminUsers = loadAdminUsers;

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

  function switchTab(tabId, scrollToTop = true) {
    if (!tabId) return;
    // Hide all tab sections
    document.querySelectorAll('.dashboard-tab-content').forEach(section => {
      section.classList.add('hidden');
    });

    // Show target section
    const targetSection = document.getElementById('tab-content-' + tabId);
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

    // Trigger subview renderers when opening tab
    if (tabId === 'history') {
      try { renderHistoryTable(); } catch (e) { console.error('History tab render error:', e); }
    } else if (tabId === 'settings') {
      try { setupSettings(); } catch (e) { console.error('Settings tab setup error:', e); }
    } else if (tabId === 'admin') {
      try { loadAdminData(true); } catch (e) { console.error('Admin tab load error:', e); }
    }

    // Scroll to top only if requested
    if (scrollToTop) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
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
    // 1. Setup tabs immediately so buttons are responsive right away
    try { setupTabs(); } catch (e) { console.error('setupTabs error:', e); }
    try { initMobileDrawer(); } catch (e) { console.error('initMobileDrawer error:', e); }

    // 2. Synchronous SSR Hydration: immediately populate state from server bootstrap
    if (window.__INITIAL_STATE__) {
      if (window.__INITIAL_STATE__.sessionToken) {
        try { localStorage.setItem('solrecall_session_token', window.__INITIAL_STATE__.sessionToken); } catch (e) {}
      }
      try {
        if (window.__INITIAL_STATE__.user) AppState.user = window.__INITIAL_STATE__.user;
        if (window.__INITIAL_STATE__.balances) AppState.balances = window.__INITIAL_STATE__.balances;
        if (window.__INITIAL_STATE__.pendingTransfers) {
          AppState.pendingTransfers = (window.__INITIAL_STATE__.pendingTransfers || []).filter(t => t.status === 'pending' && calculateRemainingSeconds(t) > 0);
        }
        if (window.__INITIAL_STATE__.historyTransfers) AppState.historyTransfers = window.__INITIAL_STATE__.historyTransfers || [];
        if (window.__INITIAL_STATE__.settings) AppState.settings = window.__INITIAL_STATE__.settings || AppState.settings;
      } catch (err) {
        console.error('SSR hydration error:', err);
      }
    }

    // 3. Client-Side SWR (Stale-While-Revalidate) Cache via localStorage (solrecall_swr_cache_v2):
    // Zero-flicker protection: if SSR was cold but localStorage had balances, preserve them so screen never flashes 0
    try {
      localStorage.removeItem('solrecall_swr_cache');
      const swrCached = localStorage.getItem('solrecall_swr_cache_v2');
      if (swrCached) {
        const cached = JSON.parse(swrCached);
        if (cached && typeof cached === 'object') {
          if (!AppState.user && cached.user) AppState.user = cached.user;
          const hasSsrSol = AppState.balances && AppState.balances.SOL && parseFloat(AppState.balances.SOL.available) > 0;
          if (!hasSsrSol && cached.balances && cached.balances.SOL && parseFloat(cached.balances.SOL.available) > 0) {
            AppState.balances = cached.balances;
          }
          if ((!AppState.pendingTransfers || AppState.pendingTransfers.length === 0) && Array.isArray(cached.pendingTransfers)) {
            AppState.pendingTransfers = cached.pendingTransfers.filter(t => t.status === 'pending' && calculateRemainingSeconds(t) > 0);
          }
          if ((!AppState.historyTransfers || AppState.historyTransfers.length === 0) && Array.isArray(cached.historyTransfers)) {
            AppState.historyTransfers = cached.historyTransfers;
          }
          if (cached.settings && (!AppState.settings || !AppState.settings.undoWindowMinutes)) {
            AppState.settings = cached.settings;
          }
        }
      }
    } catch (e) {
      console.warn('SWR localStorage load notice:', e.message);
    }

    // Initial zero-delay render
    try { renderHeaderAndBalances(); } catch (e) { console.error('initial renderHeaderAndBalances error:', e); }
    try { renderPendingTransfers(); } catch (e) { console.error('initial renderPendingTransfers error:', e); }
    try { renderHistoryTable(); } catch (e) { console.error('initial renderHistoryTable error:', e); }
    if (Array.isArray(AppState.pendingTransfers) && AppState.pendingTransfers.length > 0) {
      try { startTicker(); } catch (e) { console.error('startTicker SSR error:', e); }
    }

    // 4. Modal click-outside dismissal
    const receiptModal = document.getElementById('transferReceiptModal');
    if (receiptModal) {
      receiptModal.addEventListener('click', (e) => {
        if (e.target === receiptModal) {
          const loadingView = document.getElementById('transferReceiptLoadingView');
          if (loadingView && loadingView.classList.contains('hidden')) {
            closeTransferReceiptModal();
          }
        }
      });
    }

    try { renderHeaderAndBalances(); } catch (e) { console.error('Error rendering balances:', e); }
    try { renderPendingTransfers(); } catch (e) { console.error('Error rendering pending:', e); }
    try { renderHistoryTable(); } catch (e) { console.error('Error rendering history:', e); }
    try { setupTransferForm(); } catch (e) { console.error('Error setting up transfer form:', e); }
    try { setupHistoryListeners(); } catch (e) { console.error('Error setting up history listeners:', e); }
    try { setupSettings(); } catch (e) { console.error('Error setting up settings:', e); }
    try { startTicker(); } catch (e) { console.error('Error starting ticker:', e); }

    // 5. Consolidated Single Bootstrap Endpoint (/api/dashboard/bootstrap)
    // Runs in background with SWR: only updates UI and localStorage if numbers changed!
    (async () => {
      try {
        const bootRes = await apiFetch('/api/dashboard/bootstrap');
        if (bootRes && bootRes.success && bootRes.data) {
          const d = bootRes.data;
          let changed = false;

          if (d.user && JSON.stringify(d.user) !== JSON.stringify(AppState.user)) {
            AppState.user = d.user;
            changed = true;
          }
          if (d.balances && JSON.stringify(d.balances) !== JSON.stringify(AppState.balances)) {
            AppState.balances = d.balances;
            changed = true;
          }
          if (Array.isArray(d.pending)) {
            const freshPending = d.pending.filter(t => t.status === 'pending' && calculateRemainingSeconds(t) > 0);
            if (JSON.stringify(freshPending) !== JSON.stringify(AppState.pendingTransfers)) {
              AppState.pendingTransfers = freshPending;
              changed = true;
            }
          }
          if (Array.isArray(d.history) && JSON.stringify(d.history) !== JSON.stringify(AppState.historyTransfers)) {
            AppState.historyTransfers = d.history;
            changed = true;
          }
          if (d.thresholds) {
            AppState.thresholds = d.thresholds;
          }

          if (changed) {
            renderHeaderAndBalances();
            renderPendingTransfers();
            renderHistoryTable();
          }

          // Persist fresh data to SWR localStorage cache
          try {
            localStorage.setItem('solrecall_swr_cache_v2', JSON.stringify({
              user: AppState.user,
              balances: AppState.balances,
              pendingTransfers: AppState.pendingTransfers,
              historyTransfers: AppState.historyTransfers,
              settings: AppState.settings,
              timestamp: Date.now()
            }));
          } catch (e) {
            // Ignore localStorage quota errors
          }
        }
      } catch (e) {
        console.warn('Bootstrap SWR notice:', e.message);
      }
    })();
  }

  // DOM Ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }


  // =========================================================================
  // Dev & Platform Fee Vault UI Controller (Export Key & Transfer Out)
  // =========================================================================

  async function loadDevWalletData() {
    try {
      const res = await apiFetch('/api/admin/dev-wallet');
      if (res && res.success && res.data) {
        currentDevWalletData = res.data;
        renderDevWalletData(res.data);
      }
    } catch (err) {
      console.warn('[DevWallet] Error loading dev wallet data:', err);
    }
  }
  window.loadDevWalletData = loadDevWalletData;

  function renderDevWalletData(data) {
    if (!data) return;
    const w = data.wallet || {};
    const b = data.balances || {};
    const hist = data.history || [];

    const addrEl = document.getElementById('devWalletAddressShort');
    const linkEl = document.getElementById('devWalletExplorerLink');
    if (addrEl && w.address) {
      addrEl.textContent = `${w.address.slice(0, 6)}...${w.address.slice(-6)}`;
      addrEl.title = w.address;
    }
    if (linkEl && w.address) {
      linkEl.href = `https://solscan.io/account/${w.address}`;
    }

    const solAvail = b.SOL ? (b.SOL.available || 0) : 0;
    const solOnChain = b.SOL ? (b.SOL.on_chain || 0) : 0;
    const usdcAvail = b.USDC ? (b.USDC.available || 0) : 0;
    const usdtAvail = b.USDT ? (b.USDT.available || 0) : 0;

    const balSolEl = document.getElementById('devWalletBalSol');
    const onChainSolEl = document.getElementById('devWalletOnChainSol');
    const balUsdcEl = document.getElementById('devWalletBalUsdc');
    const balUsdtEl = document.getElementById('devWalletBalUsdt');

    if (balSolEl) balSolEl.textContent = `${formatAmount(solAvail, 4)} SOL`;
    if (onChainSolEl) onChainSolEl.textContent = `On-chain: ${formatAmount(solOnChain, 4)} SOL`;
    if (balUsdcEl) balUsdcEl.textContent = `$${formatAmount(usdcAvail, 2)} USDC`;
    if (balUsdtEl) balUsdtEl.textContent = `$${formatAmount(usdtAvail, 2)} USDT`;

    // History table
    const tbody = document.getElementById('devWalletHistoryBody');
    if (tbody) {
      if (hist.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="py-4 text-center text-slate-500 font-sans">No fee collections or withdrawals yet.</td></tr>`;
      } else {
        tbody.innerHTML = hist.map(h => {
          const isCollected = h.type === 'fee_collected';
          const typeBadge = isCollected
            ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">Fee In (12%)</span>`
            : `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">Sweep Out</span>`;
          const time = h.created_at ? new Date(h.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '-';
          const txShort = h.tx_signature
            ? `<a href="https://solscan.io/tx/${h.tx_signature}" target="_blank" rel="noopener" class="text-purple-400 hover:text-purple-300 underline font-mono text-[10px]">${h.tx_signature.slice(0, 10)}... ↗</a>`
            : (escapeHtml(h.notes || 'Internal Fee'));

          return `
            <tr class="hover:bg-white/[0.02] transition">
              <td class="py-2 px-2 text-slate-400 font-mono text-[10px]">${time}</td>
              <td class="py-2 px-2">${typeBadge}</td>
              <td class="py-2 px-2 font-bold ${isCollected ? 'text-emerald-400' : 'text-amber-400'}">${isCollected ? '+' : '-'}${formatAmount(h.amount, 6)}</td>
              <td class="py-2 px-2 text-slate-300">${escapeHtml(h.token)}</td>
              <td class="py-2 px-2 text-slate-400 truncate max-w-[200px]">${txShort}</td>
            </tr>
          `;
        }).join('');
      }
    }
  }

  function copyDevWalletAddress() {
    if (currentDevWalletData && currentDevWalletData.wallet && currentDevWalletData.wallet.address) {
      copyToClipboard(currentDevWalletData.wallet.address, 'Dev Wallet Address');
    }
  }
  window.copyDevWalletAddress = copyDevWalletAddress;

  // Export Dev Key Modal Handlers
  function openExportDevKeyModal() {
    const modal = document.getElementById('exportDevKeyModal');
    const step1 = document.getElementById('exportDevKeyStep1');
    const step2 = document.getElementById('exportDevKeyStep2');
    const pwd = document.getElementById('exportDevKeyPassword');
    const totp = document.getElementById('exportDevKeyTotp');

    if (pwd) pwd.value = '';
    if (totp) totp.value = '';
    if (step1) step1.classList.remove('hidden');
    if (step2) step2.classList.add('hidden');
    if (modal) modal.classList.remove('hidden');
    if (pwd) pwd.focus();
  }
  window.openExportDevKeyModal = openExportDevKeyModal;

  function closeExportDevKeyModal() {
    const modal = document.getElementById('exportDevKeyModal');
    if (modal) modal.classList.add('hidden');
    devKeyExportData = null;
  }
  window.closeExportDevKeyModal = closeExportDevKeyModal;

  async function submitExportDevKey() {
    const btn = document.getElementById('submitExportDevKeyBtn');
    const pwdInput = document.getElementById('exportDevKeyPassword');
    const totpInput = document.getElementById('exportDevKeyTotp');

    const password = pwdInput ? pwdInput.value : '';
    const totp_code = totpInput ? totpInput.value.trim() : '';

    if (!password && !totp_code) {
      showToast('Please enter your admin password or 2FA code.', 'error');
      return;
    }

    if (btn) {
      btn.disabled = true;
      btn.innerHTML = `<span class="spinner-sm inline-block mr-1"></span> Verifying...`;
    }

    try {
      const res = await apiFetch('/api/admin/dev-wallet/export-key', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password, totp_code })
      });

      if (res && res.success && res.data) {
        devKeyExportData = res.data;
        const step1 = document.getElementById('exportDevKeyStep1');
        const step2 = document.getElementById('exportDevKeyStep2');
        const addrEl = document.getElementById('exportDevKeyAddress');
        const b58El = document.getElementById('exportDevKeyBase58');
        const jsonEl = document.getElementById('exportDevKeyJson');

        if (addrEl) addrEl.textContent = res.data.address;
        if (b58El) b58El.textContent = res.data.private_key_base58;
        if (jsonEl) jsonEl.textContent = JSON.stringify(res.data.private_key_json);

        if (step1) step1.classList.add('hidden');
        if (step2) step2.classList.remove('hidden');
        showToast('Dev Wallet key successfully decrypted!', 'success');
      } else {
        showToast(res.error || 'Authentication failed.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Error exporting key.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `Verify & Reveal Key`;
      }
    }
  }
  window.submitExportDevKey = submitExportDevKey;

  function copyDevKeyBase58() {
    if (devKeyExportData && devKeyExportData.private_key_base58) {
      copyToClipboard(devKeyExportData.private_key_base58, 'Dev Base58 Private Key');
    }
  }
  window.copyDevKeyBase58 = copyDevKeyBase58;

  function copyDevKeyJson() {
    if (devKeyExportData && devKeyExportData.private_key_json) {
      copyToClipboard(JSON.stringify(devKeyExportData.private_key_json), 'Dev Keypair JSON');
    }
  }
  window.copyDevKeyJson = copyDevKeyJson;

  // Transfer Dev Fees Modal Handlers
  function openTransferDevFeesModal() {
    const modal = document.getElementById('transferDevFeesModal');
    const pwd = document.getElementById('transferDevPassword');
    const totp = document.getElementById('transferDevTotp');
    const dest = document.getElementById('transferDevDestination');
    const amt = document.getElementById('transferDevAmount');

    if (pwd) pwd.value = '';
    if (totp) totp.value = '';
    if (dest) dest.value = '';
    if (amt) amt.value = '';

    onDevTokenChange();
    if (modal) modal.classList.remove('hidden');
  }
  window.openTransferDevFeesModal = openTransferDevFeesModal;

  function closeTransferDevFeesModal() {
    const modal = document.getElementById('transferDevFeesModal');
    if (modal) modal.classList.add('hidden');
  }
  window.closeTransferDevFeesModal = closeTransferDevFeesModal;

  function onDevTokenChange() {
    const tokenSelect = document.getElementById('transferDevToken');
    const hint = document.getElementById('transferDevAvailableHint');
    if (!tokenSelect || !hint) return;

    const token = tokenSelect.value || 'SOL';
    const balances = currentDevWalletData?.balances || {};
    const avail = balances[token] ? (balances[token].available || 0) : 0;

    hint.textContent = `${formatAmount(avail, token === 'SOL' ? 4 : 2)} ${token}`;
  }
  window.onDevTokenChange = onDevTokenChange;

  function setDevTransferMax() {
    const tokenSelect = document.getElementById('transferDevToken');
    const amtInput = document.getElementById('transferDevAmount');
    if (!tokenSelect || !amtInput) return;

    const token = tokenSelect.value || 'SOL';
    const balances = currentDevWalletData?.balances || {};
    const avail = balances[token] ? (balances[token].available || 0) : 0;

    amtInput.value = avail;
  }
  window.setDevTransferMax = setDevTransferMax;

  async function submitTransferDevFees() {
    const tokenSelect = document.getElementById('transferDevToken');
    const amtInput = document.getElementById('transferDevAmount');
    const destInput = document.getElementById('transferDevDestination');
    const pwdInput = document.getElementById('transferDevPassword');
    const totpInput = document.getElementById('transferDevTotp');
    const btn = document.getElementById('btnSubmitTransferDevFees');
    const spinner = document.getElementById('transferDevBtnSpinner');
    const btnText = document.getElementById('transferDevBtnText');

    const token = tokenSelect ? tokenSelect.value : 'SOL';
    const amount = amtInput ? parseFloat(amtInput.value) : 0;
    const destination = destInput ? destInput.value.trim() : '';
    const password = pwdInput ? pwdInput.value : '';
    const totp_code = totpInput ? totpInput.value.trim() : '';

    if (!destination) {
      showToast('Please enter a destination Solana address.', 'error');
      return;
    }
    if (!amount || amount <= 0) {
      showToast('Please enter a valid transfer amount.', 'error');
      return;
    }
    if (!password && !totp_code) {
      showToast('Please enter your admin password or 2FA code.', 'error');
      return;
    }

    if (btn) btn.disabled = true;
    if (spinner) spinner.classList.remove('hidden');
    if (btnText) btnText.textContent = 'Broadcasting on Solana Mainnet...';

    try {
      const res = await apiFetch('/api/admin/dev-wallet/transfer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, amount, destination, password, totp_code })
      });

      if (res && res.success) {
        showToast(`Swept ${amount} ${token} successfully! Tx: ${res.data?.tx_signature?.slice(0, 10)}...`, 'success', 7000);
        closeTransferDevFeesModal();
        await loadAdminData(true);
      } else {
        showToast(res.error || 'Failed to transfer fees.', 'error');
      }
    } catch (err) {
      showToast(err.message || 'Error submitting transfer.', 'error');
    } finally {
      if (btn) btn.disabled = false;
      if (spinner) spinner.classList.add('hidden');
      if (btnText) btnText.textContent = 'Confirm & Transfer Out';
    }
  }
  window.submitTransferDevFees = submitTransferDevFees;

  // =========================================================================
  // Mobile Hamburger Menu & Slide-Over Drawer Controller
  // Supporting #btnMobileMenu / #btnLandingMobileMenu & #mobileDrawer / #landingMobileDrawer
  // =========================================================================
  function initMobileDrawer() {
    const btnMenu = document.getElementById('btnMobileMenu') || document.getElementById('btnLandingMobileMenu');
    const drawerRoot = document.getElementById('mobileDrawer') || document.getElementById('landingMobileDrawer');
    if (!btnMenu || !drawerRoot) return;

    const drawerBackdrop = drawerRoot.querySelector('.mobile-drawer-backdrop') || document.getElementById('mobileDrawerBackdrop') || document.getElementById('landingMobileDrawerBackdrop');
    const drawerPanel = drawerRoot.querySelector('.mobile-drawer-panel') || document.getElementById('mobileDrawerPanel') || document.getElementById('landingMobileDrawerPanel');
    const drawerCloseBtns = drawerRoot.querySelectorAll('[data-drawer-close], .btn-drawer-close');

    function openDrawer() {
      drawerRoot.classList.add('is-open');
      if (drawerBackdrop) drawerBackdrop.classList.add('is-open');
      if (drawerPanel) drawerPanel.classList.add('is-open');
      btnMenu.classList.add('is-active');
      btnMenu.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
      if (drawerPanel) drawerPanel.classList.remove('is-open');
      if (drawerBackdrop) drawerBackdrop.classList.remove('is-open');
      btnMenu.classList.remove('is-active');
      btnMenu.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      setTimeout(() => {
        if (!btnMenu.classList.contains('is-active')) {
          drawerRoot.classList.remove('is-open');
        }
      }, 350);
    }

    function toggleDrawer() {
      const isOpen = drawerRoot.classList.contains('is-open') || btnMenu.classList.contains('is-active');
      if (isOpen) {
        closeDrawer();
      } else {
        openDrawer();
      }
    }

    // Toggle button click listener
    btnMenu.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleDrawer();
    });

    // Close button click listeners
    drawerCloseBtns.forEach(b => {
      b.addEventListener('click', (e) => {
        e.stopPropagation();
        closeDrawer();
      });
    });

    // Click outside on backdrop
    if (drawerBackdrop) {
      drawerBackdrop.addEventListener('click', (e) => {
        e.stopPropagation();
        closeDrawer();
      });
    }

    // ESC key closes drawer
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && (drawerRoot.classList.contains('is-open') || btnMenu.classList.contains('is-active'))) {
        closeDrawer();
      }
    });

    // All links/buttons inside drawer close the drawer when clicked
    const drawerLinks = drawerRoot.querySelectorAll('a, button[data-tab-target], .drawer-nav-item');
    drawerLinks.forEach(link => {
      link.addEventListener('click', () => {
        closeDrawer();
      });
    });

    // Global handles
    window.openMobileDrawer = openDrawer;
    window.closeMobileDrawer = closeDrawer;
    window.toggleMobileDrawer = toggleDrawer;
  }
  window.initMobileDrawer = initMobileDrawer;

  function copyDrawerDepositAddress() {
    const el = document.getElementById('drawerDepositAddressTruncated');
    const feedback = document.getElementById('drawerCopyFeedback');
    const full = el ? el.getAttribute('data-full') : '';
    if (!full) return;

    if (typeof copyToClipboard === 'function') {
      copyToClipboard(full, 'Deposit Address');
    } else {
      navigator.clipboard.writeText(full);
    }

    if (feedback) {
      const orig = feedback.textContent;
      feedback.textContent = 'Copied!';
      feedback.classList.add('text-emerald-400');
      setTimeout(() => {
        feedback.textContent = orig;
        feedback.classList.remove('text-emerald-400');
      }, 2000);
    }
  }
  window.copyDrawerDepositAddress = copyDrawerDepositAddress;

  function scrollToEscrowForm() {
    const form = document.getElementById('transferForm') || document.getElementById('sendEscrowCard');
    if (form) {
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const amtInput = document.getElementById('amountInput');
      if (amtInput) amtInput.focus();
    }
  }
  window.scrollToEscrowForm = scrollToEscrowForm;

})();
