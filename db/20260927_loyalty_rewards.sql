-- VNV Loyalty Rewards. Idempotent, tenant/site scoped, ledger based.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  reward_percent DECIMAL(7,4) NOT NULL DEFAULT 10.0000,
  point_value DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  release_days SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  reward_basis ENUM('ELIGIBLE_NET','SUBTOTAL_NET') NOT NULL DEFAULT 'ELIGIBLE_NET',
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loyalty_settings_scope (id_owner,site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO loyalty_settings (id_owner,site_key,reward_percent,point_value,release_days,status)
VALUES (2,'vnvevents',10.0000,1.0000,5,'ACTIVE')
ON DUPLICATE KEY UPDATE id=id;

CREATE TABLE IF NOT EXISTS loyalty_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  transaction_type ENUM('EARN','REDEEM','ADJUST','REVERSE','EXPIRE') NOT NULL,
  status ENUM('PENDING','AVAILABLE','REDEEMED','CANCELLED','REVERSED','RESERVED') NOT NULL,
  points DECIMAL(14,4) NOT NULL,
  monetary_value DECIMAL(14,2) NOT NULL,
  point_value_snapshot DECIMAL(10,4) NOT NULL,
  reward_percent_snapshot DECIMAL(7,4) NULL,
  eligible_amount DECIMAL(14,2) NULL,
  source_type VARCHAR(40) NULL,
  source_id BIGINT NULL,
  source_payment_id BIGINT NULL,
  applied_source_type VARCHAR(40) NULL,
  applied_source_id BIGINT NULL,
  parent_transaction_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  metadata_json LONGTEXT NULL,
  earned_at DATETIME NULL,
  available_at DATETIME NULL,
  redeemed_at DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loyalty_earn_source (id_owner,site_key,transaction_type,source_type,source_id),
  KEY idx_loyalty_balance (id_owner,site_key,id_user,status,transaction_type),
  KEY idx_loyalty_release (status,available_at),
  CONSTRAINT fk_loyalty_parent FOREIGN KEY (parent_transaction_id) REFERENCES loyalty_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_redemption_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_token CHAR(36) NOT NULL,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NOT NULL,
  target_type VARCHAR(40) NOT NULL,
  target_id BIGINT NOT NULL,
  points DECIMAL(14,4) NOT NULL,
  monetary_value DECIMAL(14,2) NOT NULL,
  status ENUM('HELD','COMMITTED','RELEASED','EXPIRED') NOT NULL DEFAULT 'HELD',
  expires_at DATETIME NOT NULL,
  committed_transaction_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loyalty_reservation_token (reservation_token),
  KEY idx_loyalty_reservation_balance (id_owner,site_key,id_user,status,expires_at),
  CONSTRAINT fk_loyalty_reservation_transaction FOREIGN KEY (committed_transaction_id) REFERENCES loyalty_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders_payments
  ADD COLUMN IF NOT EXISTS loyalty_points_redeemed DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_reservation_token CHAR(36) NULL;

ALTER TABLE store_payments
  ADD COLUMN IF NOT EXISTS loyalty_points_redeemed DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_reservation_token CHAR(36) NULL;
