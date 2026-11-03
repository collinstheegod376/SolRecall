-- ==============================================================================
-- Undo Window for Small Transfers - Solana Escrow Platform Schema
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS deposits;
DROP TABLE IF EXISTS pending_transfers;
DROP TABLE IF EXISTS user_balances;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------------------
-- 1. Users Table
-- Supports Email/Password, Google OAuth, and External Solana Wallet authentication.
-- Stores custodial keypairs encrypted with AES-256-GCM.
-- ------------------------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NULL,
    email VARCHAR(255) UNIQUE NULL,
    password_hash VARCHAR(255) NULL,
    google_id VARCHAR(255) UNIQUE NULL,
    wallet_address VARCHAR(44) NULL,
    solana_deposit_address VARCHAR(44) NULL,
    encrypted_private_key TEXT NULL,
    two_factor_secret VARCHAR(255) NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email (email),
    INDEX idx_users_username (username),
    INDEX idx_users_wallet (wallet_address),
    INDEX idx_users_deposit (solana_deposit_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Multi-Token Balances
-- Available: Funds ready to send, withdraw, or commit.
-- Escrow: Funds held under the undo window grace period.
-- ------------------------------------------------------------------------------
CREATE TABLE user_balances (
    user_id INT NOT NULL,
    token VARCHAR(10) NOT NULL,
    available DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000,
    escrow DECIMAL(20, 8) NOT NULL DEFAULT 0.00000000,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, token),
    INDEX idx_balances_token (token),
    CONSTRAINT fk_balances_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Pending Transfers (Escrow Hold Queue)
-- Tracks transfers held during the undo grace period.
-- Cron evaluates records where status = 'pending' AND expires_at <= NOW().
-- ------------------------------------------------------------------------------
CREATE TABLE pending_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(10) NOT NULL,
    amount DECIMAL(20, 8) NOT NULL,
    destination VARCHAR(44) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    status ENUM('pending', 'cancelled', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    tx_signature VARCHAR(88) NULL,
    cancelled_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    failure_reason TEXT NULL,
    INDEX idx_pending_expires (status, expires_at),
    INDEX idx_pending_user_status (user_id, status),
    INDEX idx_pending_created (created_at),
    CONSTRAINT fk_pending_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Deposits Table
-- Tracks on-chain deposits into user deposit addresses.
-- ------------------------------------------------------------------------------
CREATE TABLE deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(10) NOT NULL,
    amount DECIMAL(20, 8) NOT NULL,
    tx_signature VARCHAR(88) NULL,
    status ENUM('pending', 'confirmed', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    INDEX idx_deposits_user (user_id),
    INDEX idx_deposits_signature (tx_signature),
    INDEX idx_deposits_status (status),
    CONSTRAINT fk_deposits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Sessions Table
-- Database-backed session store for persistent login and multi-device support.
-- ------------------------------------------------------------------------------
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    data MEDIUMTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
