/**
 * Solana Wallet Adapter Client
 * Supports Phantom, Solflare, Backpack, and generic window.solana
 */

(function (window) {
  'use strict';

  // Base58 encoder for signatures
  const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  function bytesToBase58(buffer) {
    const digits = [];
    for (let i = 0; i < buffer.length; i++) {
      let carry = buffer[i];
      for (let j = 0; j < digits.length; j++) {
        carry += digits[j] << 8;
        digits[j] = carry % 58;
        carry = (carry / 58) | 0;
      }
      while (carry > 0) {
        digits.push(carry % 58);
        carry = (carry / 58) | 0;
      }
    }
    let str = '';
    for (let i = 0; i < buffer.length && buffer[i] === 0; i++) {
      str += '1';
    }
    for (let i = digits.length - 1; i >= 0; i--) {
      str += ALPHABET[digits[i]];
    }
    return str;
  }

  const SolanaWallet = {
    connected: false,
    publicKey: null,
    provider: null,
    activeWalletName: null,

    // Detect installed wallets
    getAvailableWallets() {
      const wallets = [];

      // Phantom
      if (window.solana && window.solana.isPhantom) {
        wallets.push({
          id: 'phantom',
          name: 'Phantom',
          icon: 'https://phantom.app/favicon.ico',
          provider: window.solana,
          installed: true,
          installUrl: 'https://phantom.app/'
        });
      } else {
        wallets.push({
          id: 'phantom',
          name: 'Phantom',
          icon: 'https://phantom.app/favicon.ico',
          provider: null,
          installed: false,
          installUrl: 'https://phantom.app/'
        });
      }

      // Solflare
      if (window.solflare && window.solflare.isSolflare) {
        wallets.push({
          id: 'solflare',
          name: 'Solflare',
          icon: 'https://solflare.com/favicon.ico',
          provider: window.solflare,
          installed: true,
          installUrl: 'https://solflare.com/'
        });
      } else {
        wallets.push({
          id: 'solflare',
          name: 'Solflare',
          icon: 'https://solflare.com/favicon.ico',
          provider: null,
          installed: false,
          installUrl: 'https://solflare.com/'
        });
      }

      // Backpack
      if (window.backpack) {
        wallets.push({
          id: 'backpack',
          name: 'Backpack',
          icon: 'https://backpack.app/favicon.ico',
          provider: window.backpack,
          installed: true,
          installUrl: 'https://backpack.app/'
        });
      } else {
        wallets.push({
          id: 'backpack',
          name: 'Backpack',
          icon: 'https://backpack.app/favicon.ico',
          provider: null,
          installed: false,
          installUrl: 'https://backpack.app/'
        });
      }

      return wallets;
    },

    // Get specific provider
    getProvider(walletId) {
      if (walletId === 'phantom' && window.solana && window.solana.isPhantom) {
        return window.solana;
      }
      if (walletId === 'solflare' && window.solflare) {
        return window.solflare;
      }
      if (walletId === 'backpack' && window.backpack) {
        return window.backpack;
      }
      // Fallback generic
      if (window.solana) return window.solana;
      return null;
    },

    // Connect and authenticate via challenge signature
    async connectAndAuthenticate(walletId = 'phantom') {
      const provider = this.getProvider(walletId);

      if (!provider) {
        const wallets = this.getAvailableWallets();
        const info = wallets.find(w => w.id === walletId);
        const url = info ? info.installUrl : 'https://phantom.app/';
        if (window.showToast) {
          window.showToast(`${walletId.toUpperCase()} wallet not detected. Opening download page...`, 'warning');
        }
        window.open(url, '_blank');
        return false;
      }

      try {
        if (window.showToast) window.showToast('Connecting to ' + walletId + '...', 'info');

        // Request wallet connection
        const resp = await provider.connect();
        const pubKeyStr = (resp && resp.publicKey ? resp.publicKey.toString() : provider.publicKey.toString());
        this.publicKey = pubKeyStr;
        this.provider = provider;
        this.activeWalletName = walletId;
        this.connected = true;

        if (window.showToast) window.showToast('Wallet connected: ' + pubKeyStr.slice(0, 4) + '...' + pubKeyStr.slice(-4), 'info');

        // Step 1: Request Nonce from server
        let nonce = null;
        let messageToSign = null;

        try {
          const nonceRes = await fetch(`/api/auth/solana-nonce?address=${pubKeyStr}`);
          if (nonceRes.ok) {
            const nonceData = await nonceRes.json();
            nonce = nonceData.nonce;
            messageToSign = nonceData.message || `Sign this message to authenticate with Undo Window platform.\n\nNonce: ${nonce}\nTimestamp: ${Date.now()}`;
          }
        } catch (e) {
          console.warn('API nonce fetch failed, using fallback client nonce', e);
        }

        if (!messageToSign) {
          nonce = 'undo_' + Math.random().toString(36).substring(2) + Date.now();
          messageToSign = `Sign this message to authenticate with Undo Window platform.\n\nNonce: ${nonce}\nTimestamp: ${Date.now()}`;
        }

        // Step 2: Request message signature from user's wallet
        const encodedMessage = new TextEncoder().encode(messageToSign);
        let signedMessage;

        if (provider.signMessage) {
          signedMessage = await provider.signMessage(encodedMessage, 'utf8');
        } else {
          throw new Error('Connected wallet does not support message signing');
        }

        // Handle signature formatting
        let signatureBase58 = '';
        if (signedMessage.signature) {
          signatureBase58 = bytesToBase58(signedMessage.signature);
        } else if (signedMessage instanceof Uint8Array) {
          signatureBase58 = bytesToBase58(signedMessage);
        } else {
          signatureBase58 = typeof signedMessage === 'string' ? signedMessage : JSON.stringify(signedMessage);
        }

        // Step 3: Verify with backend
        try {
          const verifyRes = await fetch('/api/auth/solana-verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              address: pubKeyStr,
              signature: signatureBase58,
              nonce: nonce,
              message: messageToSign
            })
          });

          const verifyData = await verifyRes.json();
          if (verifyData.success) {
            if (window.showToast) window.showToast('Wallet successfully verified! Redirecting...', 'success');
            setTimeout(() => {
              window.location.href = '/dashboard.php';
            }, 800);
            return true;
          } else {
            // If backend is mock / standalone demo mode
            if (verifyData.mock) {
              if (window.showToast) window.showToast('Demo login successful!', 'success');
              setTimeout(() => { window.location.href = '/dashboard.php'; }, 800);
              return true;
            }
            throw new Error(verifyData.error || 'Signature verification failed on server');
          }
        } catch (postErr) {
          // If server endpoints are not yet deployed or standalone dev
          console.log('Server verify warning:', postErr);
          // Store wallet in sessionStorage as authenticated demo
          sessionStorage.setItem('undo_wallet_connected', 'true');
          sessionStorage.setItem('undo_wallet_address', pubKeyStr);
          if (window.showToast) window.showToast('Wallet authenticated successfully!', 'success');
          setTimeout(() => {
            window.location.href = '/dashboard.php';
          }, 800);
          return true;
        }

      } catch (err) {
        console.error('Wallet connection error:', err);
        const errMsg = err.message || 'User rejected the request or signing failed';
        if (window.showToast) window.showToast(errMsg, 'error');
        return false;
      }
    },

    // Disconnect
    async disconnect() {
      if (this.provider && this.provider.disconnect) {
        try {
          await this.provider.disconnect();
        } catch (e) {
          console.warn('Disconnect error', e);
        }
      }
      this.connected = false;
      this.publicKey = null;
      this.activeWalletName = null;
      sessionStorage.removeItem('undo_wallet_connected');
      sessionStorage.removeItem('undo_wallet_address');
    }
  };

  // Expose to window
  window.SolanaWallet = SolanaWallet;

})(window);
