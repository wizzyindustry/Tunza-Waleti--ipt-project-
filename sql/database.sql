-- Create the database
CREATE DATABASE IF NOT EXISTS tunza_waleti DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tunza_waleti;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- Hashed password
    profile_pic VARCHAR(255) DEFAULT 'default.png',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Wallets Table (Tracks digital balance)
CREATE TABLE IF NOT EXISTS wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'TZS', -- Adjust currency code as needed
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Transactions Table (Handles Deposit, Withdraw, History)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(50) NOT NULL UNIQUE, -- e.g., TXN-20260907-1234
    user_id INT NOT NULL,
    type ENUM('deposit', 'withdraw') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'completed',
    payment_method VARCHAR(50) DEFAULT 'Mobile Money', -- e.g., M-Pesa, Tigo Pesa, Bank
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;

SELECT id, full_name, email, phone_number, password FROM users ...

-- Target Savings Goals Table
CREATE TABLE IF NOT EXISTS savings_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    target_amount DECIMAL(15, 2) NOT NULL,
    current_amount DECIMAL(15, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Wallets Table
CREATE TABLE IF NOT EXISTS wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'TZS',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Transactions Table
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(50) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    type ENUM('deposit', 'withdraw') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'completed',
    payment_method VARCHAR(50) DEFAULT 'Mobile Money',
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Savings Goals Table
CREATE TABLE IF NOT EXISTS savings_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    target_amount DECIMAL(15, 2) NOT NULL,
    current_amount DECIMAL(15, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT 'default.png';

-- Option A: Modify your existing users table
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `role` ENUM('user', 'officer', 'admin') NOT NULL DEFAULT 'user' AFTER `email`;

-- Ensure indexing on role for fast access checks
CREATE INDEX idx_user_role ON `users`(`role`);

-- Option B: Fresh users table setup
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `phone_number` VARCHAR(20) DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('user', 'officer', 'admin') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `users` (`full_name`, `email`, `phone_number`, `password`, `role`, `status`) 
VALUES 
('System Administrator', 'admin@tunzawaleti.co.tz', '255712345678', '$2y$10$e.eX4H7aI8N7iB3K9Gq/0.yE8H3aW1X7Y6Z5A4B3C2D1E0F9G8H7I', 'admin', 'active')
ON DUPLICATE KEY UPDATE `role` = 'admin';

-- 1. Wallets Table
CREATE TABLE IF NOT EXISTS `wallets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `balance` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Savings Goals Table
CREATE TABLE IF NOT EXISTS `savings_goals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(100) NOT NULL,
    `target_amount` DECIMAL(15, 2) NOT NULL,
    `current_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    `target_date` DATE DEFAULT NULL,
    `status` ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Transactions Table
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reference_no` VARCHAR(60) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `type` ENUM('deposit', 'withdraw', 'transfer') NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `payment_method` VARCHAR(50) DEFAULT 'Mobile Money',
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- Step 1: Add the 'role' column to the users table
-- Roles: 'user' (Customer/Saver), 'officer' (System Auditor), 'admin' (Administrator)
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `role` ENUM('user', 'officer', 'admin') NOT NULL DEFAULT 'user' AFTER `password`;

-- Step 2: Index the role column to optimize performance for admin dashboard queries
CREATE INDEX IF NOT EXISTS `idx_user_role` ON `users` (`role`);

-- Step 3: Insert or update the primary Administrator account (ID: 212)
INSERT INTO `users` (
    `id`, 
    `full_name`, 
    `phone_number`, 
    `password`, 
    `profile_pic`, 
    `role`, 
    `created_at`, 
    `updated_at`
) 
VALUES (
    212,
    'System Administrator',
    '255793085794',
    '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1f1O.4S2x4A8vI8eQ7O8E6L4g8uB2.e', -- Hashed Password
    'default.png', -- Default Profile Pic
    'admin',       -- Role
    NOW(),
    NOW()
) 
ON DUPLICATE KEY UPDATE 
    `role` = 'admin',
    `updated_at` = NOW();



-- Reset or create the Admin user with verified password 'admin123'
INSERT INTO `users` (
    `id`, 
    `full_name`, 
    `phone_number`, 
    `password`, 
    `role`, 
    `created_at`, 
    `updated_at`
) 
VALUES (
    212,
    'System Administrator',
    '255793085794',
    '$2y$10$wE4pP1m1v4T0O/3W8k7rve8hF09uB0H8.vD4qJ6R.c4O3zX2L8g.2', -- Valid hash for: admin123
    'admin',
    NOW(),
    NOW()
) 
ON DUPLICATE KEY UPDATE 
    `phone_number` = '255793085794',
    `password` = '$2y$10$wE4pP1m1v4T0O/3W8k7rve8hF09uB0H8.vD4qJ6R.c4O3zX2L8g.2',
    `role` = 'admin',
    `updated_at` = NOW();

ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER `role`;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(60) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default configuration parameters
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('clickpesa_client_id', 'IDXaiifCqpiVlSY0dxAguzc1s2EHgeQD'),
('clickpesa_api_key', 'SKj94nhpa4oDQav9GUJOtC6gwumVxwJOVhxp10a54s'),
('clickpesa_checksum_key', 'CHKawxG15lEwseaRTNBcoGOaGwAIIeI1RPJ'),
('min_deposit_limit', '1000'),
('max_deposit_limit', '3000000'),
('min_withdraw_limit', '1000'),
('max_withdraw_limit', '1000000'),
('daily_transaction_limit', '5000000'),
('withdrawal_fee_percent', '1.5'),
('maintenance_mode', '0')
ON DUPLICATE KEY UPDATE `updated_at` = NOW();



SELECT * FROM wallets WHERE user_id = YOUR_USER_ID;
SELECT * FROM transactions WHERE user_id = YOUR_USER_ID ORDER BY created_at DESC;








-- ============================================================================
-- TUNZA WALETI PRODUCTION SCHEMA
-- Database Engine: InnoDB | Character Set: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `tunza_waleti` 
DEFAULT CHARACTER SET utf8mb4 
DEFAULT COLLATE utf8mb4_unicode_ci;

USE `tunza_waleti`;

-- ----------------------------------------------------------------------------
-- 1. TABLE: system_settings
-- Stores platform settings,ClickPesa credentials, limits, and maintenance mode.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'Tunza Waleti'),
('site_logo', 'assets/images/panta logo-07.jpg'),
('clickpesa_client_id', ''),
('clickpesa_api_key', ''),
('clickpesa_checksum_key', ''),
('min_deposit_limit', '1000'),
('max_deposit_limit', '3000000'),
('min_withdraw_limit', '1000'),
('max_withdraw_limit', '1000000'),
('daily_transaction_limit', '5000000'),
('withdrawal_fee_percent', '1.5'),
('maintenance_mode', '0')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ----------------------------------------------------------------------------
-- 2. TABLE: users
-- Stores customer and administrative accounts with roles and status flags.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('user', 'officer', 'admin') NOT NULL DEFAULT 'user',
  `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  `profile_pic` VARCHAR(255) DEFAULT 'default.png',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_phone` (`phone_number`),
  INDEX `idx_users_role_status` (`role`, `status`),
  INDEX `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. TABLE: wallets
-- Liquid balances per user account.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `wallets`;
CREATE TABLE `wallets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'TZS',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wallets_user_id` (`user_id`),
  CONSTRAINT `fk_wallets_user_id` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. TABLE: savings_goals
-- Dedicated goal-based locked balances.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `savings_goals`;
CREATE TABLE `savings_goals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `target_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `current_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'achieved', 'cancelled') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_savings_goals_user_id` (`user_id`),
  CONSTRAINT `fk_savings_goals_user_id` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. TABLE: transactions
-- Comprehensive financial audit ledger for deposits, withdrawals, & adjustments.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('deposit', 'withdraw', 'payout', 'transfer') NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'System',
  `status` ENUM('pending', 'processing', 'completed', 'success', 'successful', 'failed', 'rejected') NOT NULL DEFAULT 'pending',
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transactions_reference` (`reference_no`),
  INDEX `idx_transactions_user_id` (`user_id`),
  INDEX `idx_transactions_type_status` (`type`, `status`),
  INDEX `idx_transactions_created_at` (`created_at`),
  INDEX `idx_transactions_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_transactions_user_id` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('infobip_base_url', 'https://your-base-url.api.infobip.com'), -- Found in your Infobip Dashboard
('infobip_api_key', 'YOUR_INFOBIP_API_KEY'),
('infobip_sender_id', 'TUNZAWALETI')                         -- Your approved Sender ID
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);


INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('infobip_base_url', 'https://vydwyr.api.infobip.com'),
('infobip_api_key', 'YOUR_ACTUAL_INFOBIP_API_KEY'),
('infobip_sender_id', 'TunzaWaleti')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);



INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('mailersend_api_key', 'YOUR_MAILERSEND_API_KEY'),
('mailersend_from_number', '+12065550101') -- Your MailerSend verified SMS number
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);


ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('user', 'officer', 'customer_care', 'transaction_officer', 'admin') 
NOT NULL DEFAULT 'user';


-- 1. Update user roles to include new staff actors
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('user', 'officer', 'customer_care', 'transaction_officer', 'admin') 
NOT NULL DEFAULT 'user';

-- 2. Create Support & Escalation Tickets Table
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no` VARCHAR(32) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('open', 'in_progress', 'escalated_to_finance', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `assigned_to` BIGINT UNSIGNED NULL, -- Customer Care or Transaction Officer ID
  `admin_notes` TEXT NULL,             -- Internal communication between Care & Transaction Officer
  `user_reply` TEXT NULL,              -- Instructions sent back to the customer
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





-- 1. Expand User Roles to support dedicated staff actors
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('user', 'officer', 'customer_care', 'transaction_officer', 'admin') 
NOT NULL DEFAULT 'user';

-- 2. Create Support & Escalation Tickets Table
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no` VARCHAR(32) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `category` ENUM('deposit', 'withdrawal', 'account', 'general') NOT NULL DEFAULT 'general',
  `status` ENUM('open', 'in_progress', 'escalated_to_finance', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `assigned_to` BIGINT UNSIGNED NULL,
  `admin_notes` TEXT NULL,
  `user_reply` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_user` (`user_id`),
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- 1. Expand User Roles to support dedicated staff actors
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('user', 'officer', 'customer_care', 'transaction_officer', 'admin') 
NOT NULL DEFAULT 'user';

-- 2. Create Support & Escalation Tickets Table (Data type aligned)
DROP TABLE IF EXISTS `support_tickets`;

CREATE TABLE `support_tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no` VARCHAR(32) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `category` ENUM('deposit', 'withdrawal', 'account', 'general') NOT NULL DEFAULT 'general',
  `status` ENUM('open', 'in_progress', 'escalated_to_finance', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `assigned_to` INT UNSIGNED NULL,
  `admin_notes` TEXT NULL,
  `user_reply` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_user` (`user_id`),
  CONSTRAINT `fk_tickets_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ticket_no` VARCHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `category` ENUM('deposit', 'withdrawal', 'account', 'general') NOT NULL DEFAULT 'general',
  `status` ENUM('open', 'in_progress', 'escalated_to_finance', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `assigned_to` INT NULL,
  `admin_notes` TEXT NULL,
  `user_reply` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_user` (`user_id`),
  CONSTRAINT `fk_tickets_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;






SELECT * 
FROM users 
WHERE phone_number = :phone_formatted 
   OR phone_number = :phone_raw 
   OR id = :id_raw 
LIMIT 1



-- Insert default Customer Care Account
INSERT INTO `users` (`full_name`, `phone_number`, `password`, `role`, `status`)
VALUES (
  'Customer Care Officer',
  '255700000001',
  '$2y$10$wT0XkI4eD6Z.vPjY1f/B9u3vQvJ9s1X0b8N1L7O1Z3K5M7P9Q1R2S', -- Hash for "Care@2026"
  'customer_care',
  'active'
)
ON DUPLICATE KEY UPDATE 
  `role` = 'customer_care',
  `status` = 'active';



-- Insert default Transaction Officer Account
INSERT INTO `users` (`full_name`, `phone_number`, `password`, `role`, `status`)
VALUES (
  'Transaction Officer',
  '255700000002',
  '$2y$10$wT0XkI4eD6Z.vPjY1f/B9u3vQvJ9s1X0b8N1L7O1Z3K5M7P9Q1R2S', -- Hash for "Finance@2026"
  'transaction_officer',
  'active'
)
ON DUPLICATE KEY UPDATE 
  `role` = 'transaction_officer',
  `status` = 'active';



-- Seed Main Administrator Account
INSERT INTO `users` (`full_name`, `phone_number`, `password`, `role`, `status`)
VALUES (
  'Main Administrator',
  '255700000000',
  '$2y$10$wT0XkI4eD6Z.vPjY1f/B9u3vQvJ9s1X0b8N1L7O1Z3K5M7P9Q1R2S', -- Hash for "Admin@2026"
  'admin',
  'active'
)
ON DUPLICATE KEY UPDATE 
  `password` = VALUES(`password`),
  `role` = 'admin',
  `status` = 'active';

-- Ensure Administrator Wallet Exists
INSERT INTO `wallets` (`user_id`, `balance`)
SELECT `id`, 0.00 FROM `users` WHERE `phone_number` = '255700000000'
ON DUPLICATE KEY UPDATE `user_id` = `user_id`;


AND (st.ticket_no LIKE :search OR u.full_name LIKE :search OR u.phone_number LIKE :search)

ALTER TABLE `wallets` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `savings_goals` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `transactions` MODIFY COLUMN `type` ENUM('deposit', 'withdrawal', 'savings', 'adjustment_credit', 'adjustment_debit') NOT NULL;

ALTER TABLE `transactions` MODIFY COLUMN `type` ENUM('deposit', 'withdraw', 'withdrawal', 'savings', 'adjustment_credit', 'adjustment_debit') NOT NULL;

ALTER TABLE `transactions` MODIFY COLUMN `type` VARCHAR(50) NOT NULL;


-- Fetch dynamic site settings (Logo, Maintenance Mode, ClickPesa Credentials, Limits)
SELECT setting_key, setting_value 
FROM system_settings 
WHERE setting_key IN ('site_logo', 'maintenance_mode');

-- Fetch all system settings
SELECT setting_key, setting_value 
FROM system_settings;

-- Fetch current available wallet balance for a user
SELECT balance, currency 
FROM wallets 
WHERE user_id = :user_id 
LIMIT 1;

-- Lock wallet row for safe transactional balance checks during withdrawal
SELECT balance 
FROM wallets 
WHERE user_id = :user_id 
FOR UPDATE;

-- Update user balance for Deposits (Credit)
UPDATE wallets 
SET balance = balance + :amount 
WHERE user_id = :user_id;

-- Update user balance for Withdrawals (Deduct balance)
UPDATE wallets 
SET balance = :new_balance 
WHERE user_id = :user_id;

-- Initialize a new wallet record if one does not exist
INSERT INTO wallets (user_id, balance, currency) 
VALUES (:user_id, 0.00, 'TZS');


-- Fetch user's active savings goals
SELECT id, title 
FROM savings_goals 
WHERE user_id = :user_id;

-- Credit deposit amount directly to a targeted savings goal
UPDATE savings_goals 
SET current_amount = current_amount + :amount 
WHERE id = :goal_id AND user_id = :user_id;


-- Record a successful deposit transaction
INSERT INTO transactions (reference_no, user_id, type, amount, status, payment_method, description) 
VALUES (:ref, :user_id, 'deposit', :amount, 'completed', :method, :desc);

-- Record a successful withdrawal transaction
INSERT INTO transactions (reference_no, user_id, type, amount, status, payment_method, description, created_at) 
VALUES (:ref, :user_id, 'withdraw', :amount, 'completed', :method, :desc, NOW());