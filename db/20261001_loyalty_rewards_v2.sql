-- VNV Rewards 2.0: hourly release, source lifecycle and auditable reconciliation.
-- Idempotent. Run after db/20260927_loyalty_rewards.sql.
SET NAMES utf8mb4;

ALTER TABLE loyalty_settings
  ADD COLUMN IF NOT EXISTS release_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48 AFTER point_value;

UPDATE loyalty_settings
SET release_hours=48
WHERE id_owner=2 AND site_key='vnvevents' AND release_hours<>48;

ALTER TABLE loyalty_settings
  DROP COLUMN IF EXISTS release_days;

ALTER TABLE loyalty_transactions
  ADD COLUMN IF NOT EXISTS source_completed_at DATETIME NULL AFTER earned_at,
  ADD COLUMN IF NOT EXISTS released_at DATETIME NULL AFTER available_at,
  ADD COLUMN IF NOT EXISTS reconciliation_key VARCHAR(191) NULL AFTER metadata_json,
  ADD UNIQUE KEY IF NOT EXISTS uq_loyalty_reconciliation (id_owner,site_key,reconciliation_key),
  ADD INDEX IF NOT EXISTS idx_loyalty_source (id_owner,site_key,source_type,source_id,transaction_type);

ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS reward_completed_at DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS loyalty_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER discount;

ALTER TABLE store_carts
  ADD COLUMN IF NOT EXISTS loyalty_points_redeemed DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER delivery_fee,
  ADD COLUMN IF NOT EXISTS loyalty_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER loyalty_points_redeemed,
  ADD COLUMN IF NOT EXISTS loyalty_reservation_token CHAR(36) NULL AFTER loyalty_discount_amount;

ALTER TABLE store_payments
  ADD COLUMN IF NOT EXISTS loyalty_points_redeemed DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_reservation_token CHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0;

-- Existing pending event rewards retain their historical available_at. New rewards use release_hours.
SELECT 'VNV Rewards 2.0 schema ready' AS result;
