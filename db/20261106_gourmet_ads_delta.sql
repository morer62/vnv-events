-- VNV Gourmet Ads optimization delta.
-- Idempotent for MariaDB/MySQL and scoped by owner/site at runtime.

ALTER TABLE `store_gourmet_settings`
  ADD COLUMN IF NOT EXISTS `corporate_meal_box_price` DECIMAL(10,2) NULL AFTER `staff_whatsapp`;

-- This is the already-published VNV Events meal-box value supplied in the
-- approved brief. It moves the value out of PHP and into tenant configuration.
UPDATE `store_gourmet_settings`
SET `corporate_meal_box_price` = 12.50
WHERE `id_owner` = 2
  AND `site_key` = 'vnvevents'
  AND `corporate_meal_box_price` IS NULL;

CREATE TABLE IF NOT EXISTS `gourmet_lead_rate_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `site_key` VARCHAR(64) NOT NULL,
  `ip_hash` CHAR(64) NOT NULL,
  `is_allowed` TINYINT(1) NOT NULL DEFAULT 1,
  `reason_code` VARCHAR(32) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gourmet_rate_window` (`id_owner`,`site_key`,`ip_hash`,`created_at`),
  KEY `idx_gourmet_rate_cleanup` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gourmet_conversion_exports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `conversion_action` VARCHAR(80) NOT NULL,
  `order_id` VARCHAR(120) NOT NULL,
  `click_id_type` ENUM('gclid','gbraid','wbraid') NOT NULL,
  `click_id_hash` CHAR(64) NOT NULL,
  `conversion_time` VARCHAR(40) NOT NULL,
  `value` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `export_batch` VARCHAR(80) NOT NULL,
  `payload_json` LONGTEXT NULL,
  `exported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gourmet_conversion_order` (`id_owner`,`conversion_action`,`order_id`),
  KEY `idx_gourmet_conversion_lead` (`id_owner`,`lead_id`),
  KEY `idx_gourmet_conversion_exported` (`exported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
