-- ==============================================================================
-- Undo Window for Small Transfers - Supabase (PostgreSQL) Schema & Seeds
-- ==============================================================================
-- Paste this script into your Supabase project's SQL Editor and click 'Run'.
-- ==============================================================================

-- Clean up existing tables if re-running
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS deposits CASCADE;
DROP TABLE IF EXISTS pending_transfers CASCADE;
DROP TABLE IF EXISTS user_balances CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ------------------------------------------------------------------------------
-- 1. Users Table
-- ------------------------------------------------------------------------------
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    email VARCHAR(255) UNIQUE,
    password_hash VARCHAR(255),
    google_id VARCHAR(255) UNIQUE,
    wallet_address VARCHAR(44),
    solana_deposit_address VARCHAR(44),
    encrypted_private_key TEXT,
    two_factor_secret VARCHAR(255),
    two_factor_enabled SMALLINT NOT NULL DEFAULT 0,
    is_admin SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_email ON users (email);
CREATE INDEX idx_users_username ON users (username);
CREATE INDEX idx_users_wallet ON users (wallet_address);
CREATE INDEX idx_users_deposit ON users (solana_deposit_address);

-- ------------------------------------------------------------------------------
-- 2. Multi-Token Balances
-- ------------------------------------------------------------------------------
CREATE TABLE user_balances (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(10) NOT NULL,
    available NUMERIC(20, 8) NOT NULL DEFAULT 0.00000000,
    escrow NUMERIC(20, 8) NOT NULL DEFAULT 0.00000000,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, token)
);

CREATE INDEX idx_balances_token ON user_balances (token);

-- ------------------------------------------------------------------------------
-- 3. Pending Transfers (Escrow Hold Queue)
-- ------------------------------------------------------------------------------
CREATE TABLE pending_transfers (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(10) NOT NULL,
    amount NUMERIC(20, 8) NOT NULL,
    destination VARCHAR(44) NOT NULL,
    note TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'cancelled', 'processing', 'completed', 'failed')),
    tx_signature VARCHAR(88),
    cancelled_at TIMESTAMPTZ,
    processed_at TIMESTAMPTZ,
    failure_reason TEXT
);

CREATE INDEX idx_pending_expires ON pending_transfers (status, expires_at);
CREATE INDEX idx_pending_user_status ON pending_transfers (user_id, status);
CREATE INDEX idx_pending_created ON pending_transfers (created_at);

-- ------------------------------------------------------------------------------
-- 4. Deposits Table
-- ------------------------------------------------------------------------------
CREATE TABLE deposits (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(10) NOT NULL,
    amount NUMERIC(20, 8) NOT NULL,
    tx_signature VARCHAR(88),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'failed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMPTZ
);

CREATE INDEX idx_deposits_user ON deposits (user_id);
CREATE INDEX idx_deposits_signature ON deposits (tx_signature);
CREATE INDEX idx_deposits_status ON deposits (status);

-- ------------------------------------------------------------------------------
-- 5. Sessions Table
-- ------------------------------------------------------------------------------
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT,
    last_activity INT,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX idx_sessions_user ON sessions (user_id);
CREATE INDEX idx_sessions_expires ON sessions (expires_at);

-- ------------------------------------------------------------------------------
-- Seed initial demo accounts and balances
-- ------------------------------------------------------------------------------
INSERT INTO users (username, email, password_hash, solana_deposit_address, two_factor_enabled, is_admin)
VALUES 
('demo_user', 'demo@solanaundo.local', '$2y$12$e8wV0hYpQYn7iVwW1v4wYeZf9U4Q8p8hJ3K1kL1mN1oP1qR1sT1uV', '4Nd1mBQtrMJVYVfKf2PJy9NZWMdEcES25HozHP84TX35', 1, 0),
('admin', 'admin@solanaundo.local', '$2y$12$e8wV0hYpQYn7iVwW1v4wYeZf9U4Q8p8hJ3K1kL1mN1oP1qR1sT1uV', '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM', 1, 1);

INSERT INTO user_balances (user_id, token, available, escrow) VALUES
(1, 'SOL', 5.25000000, 0.75000000),
(1, 'USDC', 250.00000000, 50.00000000),
(1, 'USDT', 150.00000000, 0.00000000),
(2, 'SOL', 100.00000000, 0.00000000),
(2, 'USDC', 10000.00000000, 0.00000000),
(2, 'USDT', 5000.00000000, 0.00000000);

INSERT INTO pending_transfers (user_id, token, amount, destination, note, expires_at, status) VALUES
(1, 'SOL', 0.75000000, 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', 'Coffee & lunch transfer (Undoable)', NOW() + INTERVAL '10 minutes', 'pending'),
(1, 'USDC', 50.00000000, '6b4k3Dyjzvzp8eMZWUXbBCjEvwSkkk59S5iCNLY3QrkX', 'Sticker order hold', NOW() + INTERVAL '7 minutes', 'pending');
