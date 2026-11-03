-- ==============================================================================
-- Undo Window for Small Transfers - Database Seed Data
-- ==============================================================================

-- Clear existing data
DELETE FROM sessions;
DELETE FROM deposits;
DELETE FROM pending_transfers;
DELETE FROM user_balances;
DELETE FROM users;

-- Reset auto-increment
ALTER TABLE users AUTO_INCREMENT = 1;
ALTER TABLE pending_transfers AUTO_INCREMENT = 1;
ALTER TABLE deposits AUTO_INCREMENT = 1;

-- ------------------------------------------------------------------------------
-- 1. Insert Demo Users
-- password for both is: password123 (bcrypt cost 10)
-- ------------------------------------------------------------------------------
INSERT INTO users (
    id,
    username,
    email,
    password_hash,
    wallet_address,
    solana_deposit_address,
    encrypted_private_key,
    two_factor_secret,
    two_factor_enabled,
    is_admin,
    created_at
) VALUES 
(
    1,
    'demo_user',
    'demo@solanaundo.local',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- 'password'
    '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU',
    '4Nd1mBQtrMJVYVfKf2PJy9NZWMdEcES25HozHP84TX35',
    NULL,
    NULL,
    0,
    0,
    NOW() - INTERVAL 10 DAY
),
(
    2,
    'admin',
    'admin@solanaundo.local',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- 'password'
    'DYw8jCTfwHNRJhhmFcbXvVDTqWMEVFBX6ZKUmG5CNSKK',
    '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
    NULL,
    NULL,
    0,
    1,
    NOW() - INTERVAL 30 DAY
);

-- ------------------------------------------------------------------------------
-- 2. Insert User Balances
-- Demo user has available + escrow balances for testing UI countdowns
-- ------------------------------------------------------------------------------
INSERT INTO user_balances (user_id, token, available, escrow) VALUES
-- Demo User Balances
(1, 'SOL',  5.25000000, 0.50000000),
(1, 'USDC', 250.00000000, 25.00000000),
(1, 'USDT', 150.00000000, 0.00000000),

-- Admin Balances
(2, 'SOL',  50.00000000, 0.00000000),
(2, 'USDC', 2500.00000000, 0.00000000),
(2, 'USDT', 1500.00000000, 0.00000000);

-- ------------------------------------------------------------------------------
-- 3. Insert Pending Transfers (Escrow Holds)
-- ------------------------------------------------------------------------------
INSERT INTO pending_transfers (
    id,
    user_id,
    token,
    amount,
    destination,
    note,
    created_at,
    expires_at,
    status,
    tx_signature,
    cancelled_at,
    processed_at,
    failure_reason
) VALUES 
-- Active hold 1: SOL transfer expiring in ~8 minutes (available to cancel)
(
    1,
    1,
    'SOL',
    0.50000000,
    '83astBRguLMdt2h5U1Tpdq5LM9KuKHLuGTu2uCpWbhQA',
    'Payment for dev server testing',
    NOW() - INTERVAL 2 MINUTE,
    NOW() + INTERVAL 8 MINUTE,
    'pending',
    NULL,
    NULL,
    NULL,
    NULL
),
-- Active hold 2: USDC transfer expiring in ~6 minutes (available to cancel)
(
    2,
    1,
    'USDC',
    25.00000000,
    '9vMJfxuKx3TVqYCd2t88kRkJ8iJjH6xZ8N4rZpZtXyZ1',
    'Freelance micro-bounty reward',
    NOW() - INTERVAL 4 MINUTE,
    NOW() + INTERVAL 6 MINUTE,
    'pending',
    NULL,
    NULL,
    NULL,
    NULL
),
-- Past completed transfer
(
    3,
    1,
    'SOL',
    0.20000000,
    '6b5LwQoZ67Xp1gP6PzL1J1mF6dG4R8kZqGvN6rT8bV1X',
    'Domain renewal payment',
    NOW() - INTERVAL 2 HOUR,
    NOW() - INTERVAL 1 HOUR 50 MINUTE,
    'completed',
    '5VERv8NMvzbJMEkV8xnrLkEaWRtSz9CosKDYjCJjBRnbJLgp8uirBgmQpjKhoR4tjF3ZpRzrFmBV6UjKdiSZkQUc',
    NULL,
    NOW() - INTERVAL 1 HOUR 50 MINUTE,
    NULL
),
-- Past cancelled transfer
(
    4,
    1,
    'USDT',
    15.00000000,
    '2m6Qc4B9v8Y1uL7mZ5nR9kP3wF8xT6bV2jG1H7dK9sN4',
    'Wrong destination address - cancelled by user',
    NOW() - INTERVAL 5 HOUR,
    NOW() - INTERVAL 4 HOUR 50 MINUTE,
    'cancelled',
    NULL,
    NOW() - INTERVAL 4 HOUR 55 MINUTE,
    NULL,
    NULL
);

-- ------------------------------------------------------------------------------
-- 4. Insert Initial Deposit History
-- ------------------------------------------------------------------------------
INSERT INTO deposits (
    id,
    user_id,
    token,
    amount,
    tx_signature,
    status,
    created_at,
    confirmed_at
) VALUES 
(
    1,
    1,
    'SOL',
    5.75000000,
    '3wL5qR9kP3wF8xT6bV2jG1H7dK9sN4v8Y1uL7mZ5nR2m6Qc4B9v8Y1uL7mZ5nR9kP3wF8xT6bV2jG1H7dK9sN4aa',
    'confirmed',
    NOW() - INTERVAL 9 DAY,
    NOW() - INTERVAL 9 DAY
),
(
    2,
    1,
    'USDC',
    275.00000000,
    '4xM6rS1kQ4xF9yU7cW3kH2I8eL0tO5w9Z2vM8nB6oS3n7Rd5Ca0w9Z2vM8nB6oS3n7Rd5Ca0w9Z2vM8nB6oS3n7bb',
    'confirmed',
    NOW() - INTERVAL 9 DAY,
    NOW() - INTERVAL 9 DAY
),
(
    3,
    1,
    'USDT',
    150.00000000,
    '5yN7sT2lR5yG0zV8dX4lI3J9fM1uP6x0a3wN9oC7pT4o8Se6Db1x0a3wN9oC7pT4o8Se6Db1x0a3wN9oC7pT4o8cc',
    'confirmed',
    NOW() - INTERVAL 8 DAY,
    NOW() - INTERVAL 8 DAY
);
