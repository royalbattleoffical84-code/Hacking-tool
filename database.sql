-- ORVIX PAY v2 — MySQL schema (OPTIONAL).
-- You do NOT need to import this: the site creates / upgrades all tables by itself on the first page load.
-- Import it only if you prefer to create tables manually (phpMyAdmin -> Import / SQL tab).

CREATE TABLE IF NOT EXISTS `merchants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL UNIQUE,
  `mobile` VARCHAR(20) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(10) NOT NULL DEFAULT 'merchant',
  `status` VARCHAR(10) NOT NULL DEFAULT 'active',
  `api_key` VARCHAR(80) NOT NULL UNIQUE,
  `famapi_connection_id` VARCHAR(120) DEFAULT NULL,
  `connected_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `business_name` VARCHAR(120) DEFAULT NULL,
  `logo_url` VARCHAR(500) DEFAULT NULL,
  `webhook_url` VARCHAR(500) DEFAULT NULL,
  `theme_pay` VARCHAR(30) NOT NULL DEFAULT 'classic',
  `theme_panel` VARCHAR(30) NOT NULL DEFAULT 'classic',
  `wallet` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `plan_until` DATETIME DEFAULT NULL,
  `provider` VARCHAR(20) NOT NULL DEFAULT 'fampay',
  `ref_code` VARCHAR(12) DEFAULT NULL,
  `referred_by` INT DEFAULT NULL,
  `plan_id` INT DEFAULT NULL,
  `auto_renew` TINYINT NOT NULL DEFAULT 0,
  KEY `idx_ref` (`ref_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` VARCHAR(40) NOT NULL UNIQUE,
  `merchant_id` INT NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `merchant_ref` VARCHAR(100) DEFAULT NULL,
  `customer_mobile` VARCHAR(20) DEFAULT NULL,
  `redirect_url` VARCHAR(2000) DEFAULT NULL,
  `famapi_order_id` VARCHAR(80) NOT NULL,
  `upi_id` VARCHAR(120) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `last_checked` DATETIME DEFAULT NULL,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'payment',
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `manual` TINYINT NOT NULL DEFAULT 0,
  `webhook_sent` TINYINT NOT NULL DEFAULT 0,
  `settled` TINYINT NOT NULL DEFAULT 0,
  KEY `idx_m` (`merchant_id`), KEY `idx_s` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (`k` VARCHAR(60) NOT NULL PRIMARY KEY, `v` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wallet_txns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `merchant_id` INT NOT NULL, `type` VARCHAR(20) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL, `balance_after` DECIMAL(12,2) NOT NULL, `note` VARCHAR(255) DEFAULT NULL,
  `ref` VARCHAR(60) DEFAULT NULL, `created_at` DATETIME NOT NULL, KEY `idx_wm` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(60) NOT NULL, `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `days` INT NOT NULL DEFAULT 30, `fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 0, `monthly_orders` INT NOT NULL DEFAULT 0,
  `perks` VARCHAR(255) DEFAULT NULL, `active` TINYINT NOT NULL DEFAULT 1, `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `merchant_id` INT NOT NULL, `plan_id` INT DEFAULT NULL, `plan_name` VARCHAR(60) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0, `days` INT NOT NULL, `started_at` DATETIME NOT NULL, `ends_at` DATETIME NOT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'wallet'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- To make yourself admin after registering on the site:
-- UPDATE `merchants` SET `role` = 'admin' WHERE `email` = 'your@email.com';

-- v3 tables (ads/popups, Mystery Box, pay links, activity logs)
CREATE TABLE IF NOT EXISTS `popups` (`id` INT AUTO_INCREMENT PRIMARY KEY, `title` VARCHAR(120) NOT NULL, `body` TEXT, `image_url` VARCHAR(500) DEFAULT NULL, `btn_text` VARCHAR(40) DEFAULT NULL, `btn_url` VARCHAR(500) DEFAULT NULL, `starts_at` DATETIME DEFAULT NULL, `ends_at` DATETIME DEFAULT NULL, `audience` VARCHAR(10) NOT NULL DEFAULT 'all', `frequency` VARCHAR(10) NOT NULL DEFAULT 'daily', `active` TINYINT NOT NULL DEFAULT 1, `created_at` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `mystery_opens` (`id` INT AUTO_INCREMENT PRIMARY KEY, `merchant_id` INT NOT NULL, `day` VARCHAR(10) NOT NULL, `slot` INT NOT NULL, `label` VARCHAR(120) DEFAULT NULL, `rtype` VARCHAR(10) DEFAULT NULL, `rvalue` DECIMAL(12,2) NOT NULL DEFAULT 0, `opened_at` DATETIME NOT NULL, UNIQUE KEY `uq_mo` (`merchant_id`,`day`,`slot`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `paylinks` (`id` INT AUTO_INCREMENT PRIMARY KEY, `merchant_id` INT NOT NULL, `code` VARCHAR(16) NOT NULL UNIQUE, `type` VARCHAR(10) NOT NULL DEFAULT 'fixed', `title` VARCHAR(120) NOT NULL, `amount` DECIMAL(12,2) DEFAULT NULL, `min_amount` DECIMAL(12,2) NOT NULL DEFAULT 1, `active` TINYINT NOT NULL DEFAULT 1, `uses` INT NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `activity_logs` (`id` INT AUTO_INCREMENT PRIMARY KEY, `merchant_id` INT NOT NULL DEFAULT 0, `action` VARCHAR(60) NOT NULL, `detail` VARCHAR(255) DEFAULT NULL, `ip` VARCHAR(45) DEFAULT NULL, `ua` VARCHAR(160) DEFAULT NULL, `created_at` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
