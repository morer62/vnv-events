-- VNV Gourmet To Go storefront 2.0: presentation, pickup, holiday and attribution data.
-- Schema only: this migration intentionally does not invent or activate prices,
-- delivery fees, holiday dates, policies, bundles or photography.
SET NAMES utf8mb4;

ALTER TABLE store_gourmet_settings
  ADD COLUMN IF NOT EXISTS pickup_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pickup_zip,
  ADD COLUMN IF NOT EXISTS pickup_windows_json LONGTEXT NULL AFTER pickup_enabled,
  ADD COLUMN IF NOT EXISTS holiday_deadlines_json LONGTEXT NULL AFTER blackout_dates_json,
  ADD COLUMN IF NOT EXISTS hero_image_url VARCHAR(900) NULL AFTER holiday_deadlines_json,
  ADD COLUMN IF NOT EXISTS food_arrival_policy TEXT NULL AFTER hero_image_url,
  ADD COLUMN IF NOT EXISTS allergen_policy TEXT NULL AFTER food_arrival_policy,
  ADD COLUMN IF NOT EXISTS cancellation_policy TEXT NULL AFTER allergen_policy;

CREATE TABLE IF NOT EXISTS store_product_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  slot ENUM('HERO','IN_THE_BOX','SCALE_CONTEXT','OG') NOT NULL,
  image_url VARCHAR(900) NOT NULL,
  alt_text VARCHAR(255) NULL,
  focal_x DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  focal_y DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  width_px INT UNSIGNED NULL,
  height_px INT UNSIGNED NULL,
  is_real_photo TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_product_media_slot (id_owner,site_key,id_product,slot),
  KEY idx_store_product_media_product (id_product,status,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS attribution_json LONGTEXT NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS pickup_window VARCHAR(120) NULL AFTER fulfillment_method;

ALTER TABLE store_carts
  ADD COLUMN IF NOT EXISTS attribution_json LONGTEXT NULL AFTER delivery_pricing_snapshot;

SELECT 'VNV Gourmet To Go storefront 2.0 schema ready; owner configuration remains required' AS result;
