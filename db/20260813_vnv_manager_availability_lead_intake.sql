-- VNV Events: shared manager availability + pre-CRM Lead Intake.
-- MariaDB/MySQL. Run once against the VNV Events database.

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `main_manager_id` INT UNSIGNED NULL AFTER `id_client`,
  ADD COLUMN IF NOT EXISTS `setup_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER `end_time`,
  ADD COLUMN IF NOT EXISTS `manager_assignment_status` VARCHAR(24) NOT NULL DEFAULT 'PENDING' AFTER `setup_minutes`,
  ADD COLUMN IF NOT EXISTS `availability_status` VARCHAR(32) NOT NULL DEFAULT 'NEEDS_RECHECK' AFTER `manager_assignment_status`,
  ADD COLUMN IF NOT EXISTS `availability_checked_at` DATETIME NULL AFTER `availability_status`,
  ADD INDEX IF NOT EXISTS `idx_orders_manager_schedule` (`id_owner`,`main_manager_id`,`event_date`,`start_time`,`end_time`);

CREATE TABLE IF NOT EXISTS `manager_availability` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `manager_id` INT UNSIGNED NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `availability` ENUM('AVAILABLE','UNAVAILABLE') NOT NULL DEFAULT 'UNAVAILABLE',
  `note` VARCHAR(500) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_manager_availability_window` (`id_owner`,`manager_id`,`starts_at`,`ends_at`),
  CONSTRAINT `fk_manager_availability_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_manager_profiles` (
  `id_owner` INT NOT NULL,
  `manager_id` INT UNSIGNED NOT NULL,
  `is_event_manager` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_owner`,`manager_id`),
  CONSTRAINT `fk_event_manager_profile_user` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_intake` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `source` VARCHAR(32) NOT NULL DEFAULT 'manychat',
  `external_id` VARCHAR(191) NULL,
  `channel` VARCHAR(32) NULL,
  `contact_name` VARCHAR(191) NULL,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(64) NULL,
  `service_requested` VARCHAR(191) NULL,
  `guest_count` INT UNSIGNED NULL,
  `venue` VARCHAR(500) NULL,
  `event_date` DATE NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `setup_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `availability_status` VARCHAR(32) NOT NULL DEFAULT 'NEEDS_REVIEW',
  `suggested_manager_id` INT UNSIGNED NULL,
  `availability_checked_at` DATETIME NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'NEW',
  `payload_json` LONGTEXT NULL,
  `converted_order_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lead_intake_external` (`id_owner`,`source`,`external_id`),
  KEY `idx_lead_intake_queue` (`id_owner`,`status`,`created_at`),
  KEY `idx_lead_intake_schedule` (`id_owner`,`event_date`,`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_availability_checks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `context_type` VARCHAR(32) NOT NULL,
  `context_id` BIGINT NULL,
  `manager_id` INT UNSIGNED NULL,
  `event_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `setup_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `status` VARCHAR(32) NOT NULL,
  `reason_code` VARCHAR(64) NULL,
  `details_json` LONGTEXT NULL,
  `checked_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_availability_check_context` (`id_owner`,`context_type`,`context_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_availability_overrides` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `context_type` VARCHAR(32) NOT NULL,
  `context_id` BIGINT NOT NULL,
  `check_id` BIGINT UNSIGNED NULL,
  `authorized_by` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(1000) NOT NULL,
  `conflict_snapshot_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_availability_override_context` (`id_owner`,`context_type`,`context_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_assignment_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL,
  `order_id` INT UNSIGNED NOT NULL,
  `previous_manager_id` INT UNSIGNED NULL,
  `new_manager_id` INT UNSIGNED NULL,
  `action` VARCHAR(40) NOT NULL,
  `note` VARCHAR(1000) NULL,
  `changed_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_manager_assignment_order` (`id_owner`,`order_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing records are not overwritten. Runtime fallback treats info@vnvevents.com
-- as manager until Level 1 performs an explicit, audited assignment.
