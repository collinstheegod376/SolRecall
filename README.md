# 🛡️ Undo Window for Small Transfers (Solana)

> A non-custodial-feeling custodial escrow safety layer for Solana transfers (SOL, USDC, USDT) that gives users a **5–15 minute grace window** to cancel unintended transfers before permanent on-chain broadcast.

![Solana](https://img.shields.io/badge/Blockchain-Solana%20Devnet-9945FF?style=for-the-badge&logo=solana)
![PHP](https://img.shields.io/badge/PHP-8.2%2B%20%2F%208.3-777BB4?style=for-the-badge&logo=php)
![TailwindCSS](https://img.shields.io/badge/UI-Tailwind%20Dark%20Mode-06B6D4?style=for-the-badge&logo=tailwindcss)
![Vercel](https://img.shields.io/badge/Deploy-Vercel-000000?style=for-the-badge&logo=vercel)
![Supabase](https://img.shields.io/badge/Database-Supabase%20Postgres-3ECF8E?style=for-the-badge&logo=supabase)

---

## ⚡ Key Features

- **Undo Grace Window**: Outgoing transfers below configured thresholds (e.g. $\le 2$ SOL / $\le 100$ USDC) enter an active escrow hold for 5–15 minutes with a live countdown timer and draining progress bar.
- **Instant 1-Click Cancellation**: Users can cancel pending transfers within the window, returning 100% of escrowed funds back to their available balance.
- **Immediate Large Transfers**: Transfers exceeding the small-transfer threshold bypass the delay and broadcast directly to the Solana network.
- **Multi-Token Support**: Full native support for **SOL**, **USDC**, and **USDT** (with automated Associated Token Account [ATA] derivation).
- **Custodial Key Management**:
  - Fresh Ed25519 Solana keypairs generated upon user registration.
  - Private keys encrypted with **AES-256-GCM** using platform master secrets.
  - Secure key export workflow enforcing mandatory 2FA.
- **Web3 Authentication**:
  - Sign-In With Solana (SIWS) via Phantom, Solflare, or Backpack.
  - Standard Email / Username + Password with bcrypt hashing.
  - Google OAuth support.
- **RFC 6238 TOTP 2FA**: Complete 2FA protection for withdrawals, security settings, and private key exports.
- **Serverless & Cloud-Ready**: Fully configured for **Vercel** serverless functions and **Supabase** (PostgreSQL).

---

## 🏗️ Architecture

```
├── api/
│   ├── index.php                 # Vercel serverless front controller
│   └── cron.php                  # Vercel Cron worker for automated broadcasts
├── app/
│   ├── bootstrap.php             # Standalone PSR-4 autoloader & helper functions
│   ├── Controllers/
│   │   ├── AuthController.php    # Auth, sessions, SIWS & 2FA handlers
│   │   └── TransferController.php# Escrow initiation, cancel & ledger endpoints
│   ├── Core/
│   │   ├── Database.php          # PDO singleton (PostgreSQL / MySQL / SQLite)
│   │   ├── Router.php            # Zero-dependency HTTP router
│   │   ├── Request.php & Response.php
│   │   └── Model.php & Controller.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── Require2FAMiddleware.php
│   ├── Models/
│   │   ├── User.php, UserBalance.php, PendingTransfer.php, Deposit.php, Session.php
│   │   └── Base58.php            # Pure-PHP Base58 binary encoder/decoder
│   └── Services/
│       ├── KeyManagementService.php # Ed25519 keypair generation & AES-256-GCM
│       ├── SolanaRpcService.php     # Solana JSON-RPC 2.0 & Wire Tx builder
│       ├── TransferService.php      # Atomic escrow ledger & balance locking
│       └── TwoFactorService.php     # RFC 6238 TOTP engine
├── config/
│   ├── config.php                # Token thresholds, Solana cluster & app config
│   └── database.php              # Multi-driver PDO config (Supabase / MySQL)
├── cron/
│   ├── process_pending.php       # CLI broadcaster daemon
│   └── deposit_watcher.php       # On-chain deposit detector
├── database/
│   ├── schema.sql                # MySQL schema
│   ├── schema_supabase.sql       # Supabase (PostgreSQL) schema & seeds
│   └── seed.sql                  # MySQL seeds
├── public/
│   ├── dashboard.php             # Dark-mode Web3 dashboard
│   ├── login.php & register.php  # Authentication views
│   ├── index.php                 # Local front controller
│   └── assets/                   # CSS, JS, and crypto logos
├── vercel.json                   # Vercel deployment manifest with 1m cron
└── composer.json
```

---

## 🚀 Quick Start (Local Development)

### 1. Requirements
- PHP 8.1+ (with `pdo`, `openssl`, `sodium`, `curl`, `mbstring`)

### 2. Run Local Development Server
```bash
php -S localhost:8000 -t public
```

### 3. Open in Browser
- **Dashboard Demo Mode**: [http://localhost:8000/dashboard.php?demo=1](http://localhost:8000/dashboard.php?demo=1)
- **Sign In**: [http://localhost:8000/login.php](http://localhost:8000/login.php)
- **Default Demo Account**: `demo@solanaundo.local` / `password123`

### 4. Run Automated Background Broadcaster
```bash
php cron/process_pending.php
```

---

## ☁️ Deploy to Supabase & Vercel

### Step 1: Set up Supabase
1. Create a project at [database.new](https://database.new).
2. Open the **SQL Editor**, paste the contents of [`database/schema_supabase.sql`](database/schema_supabase.sql), and click **Run**.
3. Copy your pooled connection string from **Project Settings $\to$ Database** (Port `6543`).

### Step 2: Deploy on Vercel
1. Import this repository in your Vercel Dashboard.
2. In **Settings $\to$ Environment Variables**, configure:
   - `DATABASE_URL`: `postgres://postgres.[REF]:[PASS]@aws-0-[REGION].pooler.supabase.com:6543/postgres?sslmode=require`
   - `APP_ENV`: `production`
   - `SOLANA_RPC_URL`: `https://api.devnet.solana.com`
   - `APP_ENCRYPTION_KEY`: A random 32-character encryption key
   - `CRON_SECRET`: A secret string for Vercel Cron
3. Click **Deploy**. Vercel will automatically build the PHP serverless functions and schedule `/api/cron` to execute every minute.

---

## 🧪 Testing

Run the full end-to-end integration and cryptographic test suites:

```bash
# Full Integration (Ledger, Escrow, Cancellation, RPC, ATAs, APIs)
php tests/test_integration.php

# Cryptography, Keys, AES-256-GCM & 2FA
php tests/test_auth_and_2fa.php

# Live Solana Devnet Cluster Connectivity
php tests/test_live_devnet.php
```

---

## 🔒 Security
- Private keys are stored encrypted via **AES-256-GCM** with random 96-bit IVs and 128-bit authentication tags.
- Sensitive operations (private key export, withdrawals) enforce mandatory **RFC 6238 TOTP 2FA** and write to an immutable audit log.
- Balance mutations utilize ACID transactions with row-level locks to prevent race conditions during cancellations or simultaneous cron triggers.

---

## 📄 License
MIT License.
