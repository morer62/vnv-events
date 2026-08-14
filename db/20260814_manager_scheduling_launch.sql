-- VNV Events: production launch schema for Event Manager scheduling only.
-- Idempotent on MariaDB/MySQL installations that already ran the larger 20260813 migration.

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `main_manager_id` INT UNSIGNED NULL AFTER `id_client`,
  ADD COLUMN IF NOT EXISTS `setup_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER `end_time`,
  ADD COLUMN IF NOT EXISTS `manager_assignment_status` VARCHAR(24) NOT NULL DEFAULT 'PENDING' AFTER `setup_minutes`,
  ADD COLUMN IF NOT EXISTS `availability_status` VARCHAR(32) NOT NULL DEFAULT 'NEEDS_RECHECK' AFTER `manager_assignment_status`,
  ADD COLUMN IF NOT EXISTS `availability_checked_at` DATETIME NULL AFTER `availability_status`,
  ADD INDEX IF NOT EXISTS `idx_orders_manager_schedule` (`id_owner`,`main_manager_id`,`event_date`,`start_time`,`end_time`);

CREATE TABLE IF NOT EXISTS `event_manager_profiles` (
  `id_owner` INT NOT NULL,
  `manager_id` INT UNSIGNED NOT NULL,
  `is_event_manager` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_owner`,`manager_id`),
  KEY `idx_event_manager_enabled` (`id_owner`,`is_event_manager`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Required by the existing team-member deactivation workflow. Some older
-- installations contain the repository code but never received its table.
CREATE TABLE IF NOT EXISTS `user_deactivations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `institution_id` INT UNSIGNED NOT NULL,
  `deactivated_by` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(1000) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_deactivations_user` (`user_id`,`created_at`),
  KEY `idx_user_deactivations_institution` (`institution_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  KEY `idx_manager_availability_window` (`id_owner`,`manager_id`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_availability_checks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL, `context_type` VARCHAR(32) NOT NULL,
  `context_id` BIGINT NULL, `manager_id` INT UNSIGNED NULL,
  `event_date` DATE NOT NULL, `start_time` TIME NOT NULL, `end_time` TIME NOT NULL,
  `setup_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `status` VARCHAR(32) NOT NULL, `reason_code` VARCHAR(64) NULL,
  `details_json` LONGTEXT NULL, `checked_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_availability_check_context` (`id_owner`,`context_type`,`context_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_availability_overrides` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL, `context_type` VARCHAR(32) NOT NULL,
  `context_id` BIGINT NOT NULL, `check_id` BIGINT UNSIGNED NULL,
  `authorized_by` INT UNSIGNED NOT NULL, `reason` VARCHAR(1000) NOT NULL,
  `conflict_snapshot_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_availability_override_context` (`id_owner`,`context_type`,`context_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_assignment_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NOT NULL, `order_id` INT UNSIGNED NOT NULL,
  `previous_manager_id` INT UNSIGNED NULL, `new_manager_id` INT UNSIGNED NULL,
  `action` VARCHAR(40) NOT NULL, `note` VARCHAR(1000) NULL,
  `changed_by` INT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_manager_assignment_order` (`id_owner`,`order_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `orders`
SET `manager_assignment_status` = IF(`main_manager_id` IS NULL,'PENDING','ASSIGNED'),
    `availability_status` = 'NEEDS_RECHECK',
    `availability_checked_at` = NULL
WHERE `availability_status` IS NULL OR `availability_status` = '';
