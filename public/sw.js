/**
 * SolRecall - Service Worker
 * Reversible Time-Locked Escrow on Solana
 */

const CACHE_NAME = 'solrecall-v6';

const STATIC_SHELL_ASSETS = [
  '/manifest.json',
  '/favicon.ico',
  '/apple-touch-icon.png',
  '/assets/img/logo.png',
  '/assets/img/logo-transparent.png',
  '/assets/img/logo.svg',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png',
  '/assets/img/apple-touch-icon.png',
  '/assets/img/favicon-32.png',
  '/assets/img/sol.png',
  '/assets/img/usdc.png',
  '/assets/img/usdt.png',
  '/assets/fonts/inter.woff2',
  '/assets/fonts/jetbrains-mono.woff2'
];

// Install Event: Pre-cache static shell assets (NO dynamic PHP pages)
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[SolRecall SW] Caching app shell assets');
      return cache.addAll(STATIC_SHELL_ASSETS).catch((err) => {
        console.warn('[SolRecall SW] Some assets could not be cached immediately:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

// Activate Event: Instantly purge all legacy caches (v1, v2, v3, etc.)
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== CACHE_NAME) {
            console.log('[SolRecall SW] Removing old cache:', cache);
            return caches.delete(cache);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch Event: Intelligent caching strategy
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Bypass non-GET requests or browser-extension schemes
  if (req.method !== 'GET' || !url.protocol.startsWith('http')) {
    return;
  }

  // API calls: Network-first, no aggressive caching
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(req).catch(() => {
        return new Response(
          JSON.stringify({
            success: false,
            error: 'You are currently offline. Please reconnect to perform this SolRecall action.'
          }),
          {
            headers: { 'Content-Type': 'application/json' },
            status: 503
          }
        );
      })
    );
    return;
  }

  // HTML Navigation requests: ALWAYS network first; NEVER cache authenticated PHP pages
  if (req.mode === 'navigate' || req.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      fetch(req).catch(() => {
        return new Response(
          `<!DOCTYPE html>
          <html lang="en" class="dark">
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Offline • SolRecall</title>
            <script src="https://cdn.tailwindcss.com"></script>
          </head>
          <body class="min-h-screen bg-[#07090e] text-slate-100 flex flex-col items-center justify-center p-6 text-center font-sans">
            <div class="max-w-md p-8 rounded-3xl bg-slate-900/90 border border-white/10 shadow-2xl">
              <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 4.243a9 9 0 01-12.728 0m0 0l2.829-2.829m-2.829 2.829L3 21m2.828-15.536a9 9 0 0112.728 0M3 3l18 18"/></svg>
              </div>
              <h1 class="text-xl font-bold text-white mb-2">You are currently offline</h1>
              <p class="text-xs text-slate-400 mb-6">SolRecall requires an active connection to monitor escrow countdowns and communicate with Solana nodes.</p>
              <button onclick="window.location.reload()" class="px-5 py-2.5 rounded-xl font-semibold text-xs bg-purple-600 hover:bg-purple-500 text-white transition">Retry Connection</button>
            </div>
          </body>
          </html>`,
          { headers: { 'Content-Type': 'text/html' } }
        );
      })
    );
    return;
  }

  // Static Assets (CSS, JS, Fonts, Images): Network-first with cache fallback
  // This ensures updated JS/CSS scripts are picked up immediately on deploy!
  event.respondWith(
    fetch(req)
      .then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const clone = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
        }
        return networkResponse;
      })
      .catch(() => {
        return caches.match(req);
      })
  );
});
