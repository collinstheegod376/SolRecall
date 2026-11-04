# 🛡️ SolRecall

### The First Open-Source, Time-Locked Reversible Escrow Layer on Solana

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=for-the-badge)](https://opensource.org/licenses/MIT)
[![Solana Mainnet](https://img.shields.io/badge/Solana-Mainnet-14F195?style=for-the-badge&logo=solana&logoColor=black)](https://solana.com)
[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![Supabase PostgreSQL](https://img.shields.io/badge/Supabase-PostgreSQL-3ECF8E?style=for-the-badge&logo=supabase&logoColor=white)](https://supabase.com)
[![PWA Enabled](https://img.shields.io/badge/PWA-Enabled-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)](#-core-features)

[**GitHub Repository**](https://github.com/collinstheegod376/SolRecall) • [**Architecture**](#%EF%B8%8F-architecture--lifecycle-flow) • [**Installation**](#-step-by-step-setup--installation-guide) • [**Contributing**](#-contributing-guidelines) • [**License**](#-license)

---

## 💡 Value Proposition

> **SolRecall is the first open-source, time-locked reversible escrow layer on Solana that allows users to cancel and refund transfers before they settle on-chain.**

Blockchain transactions are notoriously immutable—one mistyped character, malicious clipboard hijack, or hasty transfer sends funds into the void with zero recourse. Traditional custodial bridges and complex multisigs introduce friction, high fees, or counterparty vulnerability.

**SolRecall** solves this fundamental safety issue by introducing an autonomous, time-locked escrow grace buffer:
1. When a user sends funds below configured small-transfer thresholds (e.g. $\le 2$ SOL, $\le 100$ USDC/USDT), the transaction enters an active **Undo Window**.
2. During this window, the sender has complete autonomy to click **Undo Transfer** to cancel the transfer and instantly refund 100% of escrowed funds back to their available balance.
3. If the window elapses without cancellation, SolRecall's automated, resilient broadcaster signs and broadcasts the wire transaction directly to the **Solana Mainnet** cluster for permanent on-chain settlement.

---

## ⚡ Core Features

- ⏳ **Undo Timelocks**: Configurable grace windows (from 1 minute to customizable durations) with live visual progress bars, real-time second-by-second countdowns, and 1-click cancellation.
- 🪙 **Multi-Token Support (SOL / USDC / USDT)**: Native SOL alongside SPL tokens (USDC, USDT) with automated Associated Token Account (ATA) derivation, rent-exempt balance validation, and instruction packing.
- 🧾 **Phantom-Style Shareable Receipts**: Rich, shareable cryptographic transaction receipts providing transparent audit records, transfer status badges, and one-click Solana Explorer verification.
- 🔐 **2-Step TOTP 2FA**: RFC 6238 compliant Time-Based One-Time Password authentication securing sensitive balance operations, account settings, and key exports.
- ⚡ **Pure Supabase Pooler Compatibility**: Full PDO compatibility with Supabase's PostgreSQL transaction pooler (port `6543`), utilizing `PDO::ATTR_EMULATE_PREPARES` and atomic connection failover to ensure non-blocking, concurrent transactions.
- 🔑 **Self-Custodial Private Key Export**: Users retain sovereign custody of their funds. Ed25519 keypairs are generated client-accessible, encrypted at rest via **AES-256-GCM** with 96-bit random IVs and 128-bit authentication tags, and exportable at any time following 2FA verification.
- 🤖 **Resilient Auto-Broadcast Daemon**: Unified background watcher (`cron/deposit_watcher.php`) featuring automatic crash recovery, live signature verification, slot validation, and error self-healing.
- 📱 **PWA Installable**: Modern Progressive Web App manifest and responsive dark-mode interface designed for desktop, tablet, and mobile Web3 devices.

---

## 🏗️ Architecture & Lifecycle Flow

SolRecall bridges Web3 speed with an essential safety grace period through an atomic state machine:

```mermaid
flowchart TD
    A["User / External Sender"] -->|"Deposit SOL, USDC, or USDT"| B["Custodial Deposit Address"]
    B -->|"deposit_watcher.php detects Tx"| C["Available Balance Credited"]
    C -->|"Initiate Outgoing Transfer (<= Threshold)"| D["Timelocked Undo Hold"]
    
    D -->|"Option A: User clicks 'Undo Transfer' (Within grace window)"| E["Cancelled and Refunded"]
    E -->|"Instant 100% balance return"| C

    D -->|"Option B: Timelock Countdown Expires (Grace window elapsed)"| F["Settlement Daemon (deposit_watcher.php)"]
    F -->|"Signs wire transaction with Ed25519 key"| G["Broadcast to Solana Mainnet"]
    G -->|"Confirmed on-chain"| H["Finalized Recipient Settlement"]

    subgraph EscrowLayer ["SolRecall Safety Layer"]
        D
        E
        F
    end

    subgraph OnChain ["Solana Blockchain"]
        B
        G
        H
    end
```

### Transfer State Machine

| State | Status in Database | Description |
|:------|:-------------------|:------------|
| **Held in Escrow** | `pending` | Transfer created; funds moved from `available` to `escrow` balance. Countdown timer active. |
| **Cancelled / Refunded** | `cancelled` | Sender clicked **Undo**. Funds immediately unlocked and restored to `available` balance. |
| **Broadcasting** | `processing` | Undo window expired. Broadcast daemon acquired row-level lock and is signing wire transaction. |
| **Completed** | `completed` | Transaction broadcasted and confirmed on Solana Mainnet. `tx_signature` recorded. |
| **Failed** | `failed` | Network or RPC failure during broadcast. Escrow refunded to user; `failure_reason` logged. |

---

## 📁 Repository Structure

```
SolRecall/
├── api/
│   ├── index.php                 # Vercel serverless HTTP front controller
│   └── cron.php                  # Vercel Cron worker for automated broadcasts
├── app/
│   ├── bootstrap.php             # Standalone PSR-4 autoloader & environment loader
│   ├── Controllers/
│   │   ├── AuthController.php    # Session, SIWS, Web3 & 2FA controller
│   │   └── TransferController.php# Escrow initiation, cancellation, & receipt APIs
│   ├── Core/
│   │   ├── Database.php          # Resilient PDO singleton (Supabase Postgres & MySQL)
│   │   ├── Router.php            # Zero-dependency HTTP router
│   │   ├── Request.php & Response.php
│   │   └── Model.php & Controller.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── Require2FAMiddleware.php
│   ├── Models/
│   │   ├── User.php, UserBalance.php, PendingTransfer.php, Deposit.php, Session.php
│   │   └── Base58.php            # Pure-PHP Base58 binary encoder & decoder
│   └── Services/
│       ├── KeyManagementService.php # Ed25519 key generation & AES-256-GCM encryption
│       ├── SolanaRpcService.php     # Solana JSON-RPC 2.0 client & Wire Tx serializer
│       ├── TransferService.php      # Atomic balance ledger & escrow timelock engine
│       └── TwoFactorService.php     # RFC 6238 TOTP verification & QR generation
├── bin/
│   ├── check_deposits.php        # CLI balance & lamport inspector
│   ├── generate_keys.php         # Standalone encryption key generator
│   └── test_supabase.php         # Supabase connection & schema verification utility
├── config/
│   ├── config.php                # Token metadata, thresholds, and cluster settings
│   └── database.php              # Supabase Transaction Pooler (Port 6543) config
├── cron/
│   ├── deposit_watcher.php       # Unified daemon: deposit detector & broadcast worker
│   └── process_pending.php       # Standalone expired transfer processor
├── database/
│   ├── schema_supabase.sql       # PostgreSQL schema & default seeds for Supabase
│   ├── schema.sql                # MySQL compatibility schema
│   └── seed.sql                  # Initial test accounts and seed balances
├── public/
│   ├── dashboard.php             # Web3 dark-mode dashboard with live escrow monitors
│   ├── login.php & register.php  # User authentication views
│   ├── index.php                 # Local front controller
│   └── assets/                   # Responsive UI styling, scripts, and token assets
├── tests/                        # Full test suite (Integration, Crypto, RPC, Live DB)
├── .env.example                  # Environment configuration template
├── LICENSE                       # MIT License
├── vercel.json                   # Vercel deployment & scheduled cron configuration
└── composer.json
```

---

## 🚀 Step-by-Step Setup & Installation Guide

### 1. System Requirements

Ensure your host environment meets the following prerequisites:
- **PHP 8.3** (or PHP 8.2+) with the following extensions enabled:
  - `pdo_pgsql` (PostgreSQL driver for Supabase)
  - `openssl` (AES-256-GCM cryptography)
  - `sodium` (Ed25519 Solana keypairs & signatures)
  - `curl` (Solana JSON-RPC 2.0 transport)
  - `mbstring` (Multibyte string operations)
- **Git**
- A **Supabase PostgreSQL** project (or local PostgreSQL instance)

Verify your PHP version and extensions:
```bash
php -v
php -m | grep -E "pdo_pgsql|openssl|sodium|curl|mbstring"
```

---

### 2. Clone the Repository

Clone the SolRecall open-source repository from GitHub:

```bash
git clone https://github.com/collinstheegod376/SolRecall.git
cd SolRecall
```

---

### 3. Environment Setup

Create your local `.env` configuration file from the template:

```bash
cp .env.example .env
```

Configure `.env` with your Supabase PostgreSQL pooler credentials and Solana RPC settings:

```env
# Application Settings
APP_NAME="SolRecall"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_ENCRYPTION_KEY=your_32_byte_hex_or_base64_master_encryption_key

# Supabase Transaction Pooler (Port 6543)
DATABASE_URL="postgres://postgres.[YOUR_PROJECT_REF]:[YOUR_PASSWORD]@aws-0-[REGION].pooler.supabase.com:6543/postgres?sslmode=require"
DB_CONNECTION=pgsql
DB_HOST=aws-0-[REGION].pooler.supabase.com
DB_PORT=6543
DB_DATABASE=postgres
DB_USERNAME=postgres.[YOUR_PROJECT_REF]
DB_PASSWORD=[YOUR_PASSWORD]
DB_SSLMODE=require

# Solana Mainnet RPC
SOLANA_CLUSTER=mainnet-beta
SOLANA_RPC_URL=https://api.mainnet-beta.solana.com
SOLANA_COMMITMENT=confirmed

# SPL Token Mint Addresses (Solana Mainnet official mints)
SOLANA_TOKEN_MINT_USDC=EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v
SOLANA_TOKEN_MINT_USDT=Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB

# Undo Window Thresholds
DEFAULT_UNDO_WINDOW_MINUTES=10
MIN_UNDO_WINDOW_MINUTES=1
THRESHOLD_SOL=2.0
THRESHOLD_USDC=100.0
THRESHOLD_USDT=100.0
```

> [!TIP]
> You can generate a random 32-byte encryption key using the bundled helper:
> ```bash
> php bin/generate_keys.php
> ```

---

### 4. Database Migrations

Initialize your Supabase database schema:

1. Open your **Supabase Dashboard** $\to$ **SQL Editor**.
2. Paste the contents of [`database/schema_supabase.sql`](database/schema_supabase.sql) and execute the query.
3. Verify connection and table structures from the command line:

```bash
php bin/test_supabase.php
```

Expected output:
```
[SUCCESS] Supabase connection established via pooler!
[SUCCESS] All tables verified: users, user_balances, pending_transfers, deposits, sessions, audit_logs.
```

---

### 5. Start the Local Web Server

Launch PHP's built-in web server:

```bash
php -S localhost:8000 -t public
```

Open your browser to:
- **Dashboard**: [http://localhost:8000/dashboard.php](http://localhost:8000/dashboard.php)
- **Sign In**: [http://localhost:8000/login.php](http://localhost:8000/login.php)
- **Register**: [http://localhost:8000/register.php](http://localhost:8000/register.php)
- **Demo Mode**: [http://localhost:8000/dashboard.php?demo=1](http://localhost:8000/dashboard.php?demo=1)

---

### 6. Run the Unified Deposit & Broadcast Watcher Daemon

SolRecall includes an automated background worker in `cron/deposit_watcher.php`. It handles two operations in a single resilient loop:
1. **Deposit Detection**: Scans users' custodial deposit addresses on Solana Mainnet for incoming SOL, USDC, and USDT, atomically crediting available balances.
2. **Expired Timelock Settlement**: Identifies pending transfers whose undo windows have elapsed, builds and signs Ed25519 wire transactions, and broadcasts them on-chain.

#### Single One-Off Execution:
```bash
php cron/deposit_watcher.php
```

#### Continuous Polling Daemon Mode:
Run as a background worker with continuous polling (e.g., every 10 seconds):
```bash
php cron/deposit_watcher.php --poll --interval=10
```

#### Local Sandbox Simulation Mode:
Test deposit detection and auto-broadcasting locally without spending Mainnet SOL:
```bash
php cron/deposit_watcher.php --poll --interval=5 --simulate
```

---

## 🧪 Automated Test Suite

SolRecall includes a complete automated test suite covering ledger integrity, cryptographic signatures, and network connectivity:

```bash
# 1. Full Integration Test (Ledger, Escrow Locks, Undo Cancellation, RPC)
php tests/test_integration.php

# 2. Cryptographic Security & 2FA Engine (Ed25519, AES-256-GCM, TOTP)
php tests/test_auth_and_2fa.php

# 3. Live Solana Mainnet Cluster Connectivity
php tests/test_live_mainnet.php

# 4. Supabase Live Pooler Database Tests
php tests/test_supabase_live.php
```

---

## 🤝 Contributing Guidelines

We welcome contributions from developers, security researchers, and Web3 enthusiasts! To ensure a smooth collaboration process, please follow these guidelines:

### How to Contribute

1. **Fork the Repository**:
   Fork [SolRecall on GitHub](https://github.com/collinstheegod376/SolRecall) to your personal account.

2. **Create a Feature Branch**:
   Use descriptive branch naming conventions:
   ```bash
   git checkout -b feat/add-phantom-deep-links
   # or
   git checkout -b fix/token-account-rent-check
   ```

3. **Adhere to Code Standards**:
   - Write clean, modern **PHP 8.3** code.
   - Enforce strict typing with `declare(strict_types=1);` in all PHP files.
   - Follow **PSR-12** code style guidelines.
   - Keep controllers slim and encapsulate domain logic in `app/Services/`.

4. **Verify Tests**:
   Ensure all automated integration and cryptographic tests pass before submitting changes:
   ```bash
   php tests/test_integration.php
   php tests/test_auth_and_2fa.php
   ```

5. **Submit a Pull Request**:
   - Push your branch to your fork on GitHub and open a Pull Request against `main`.
   - Provide a clear summary of changes, motivation, and any testing procedures performed.

### 🛡️ Security Vulnerability Reporting

If you discover a security vulnerability or cryptographic flaw within SolRecall:
- **Do not** disclose it publicly via GitHub issues.
- Please report vulnerabilities privately to the maintainers via [GitHub Security Advisories](https://github.com/collinstheegod376/SolRecall/security/advisories) or directly to the repository maintainer.
- Critical issues will be patched promptly with responsible disclosure credit.

---

## 📄 License

This project is open-source software licensed under the **[MIT License](LICENSE)**.

```
Copyright (c) 2026 SolRecall Contributors (https://github.com/collinstheegod376/SolRecall)
```
