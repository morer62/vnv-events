CREATE TABLE IF NOT EXISTS `seo_files_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_type` VARCHAR(50) NOT NULL,
  `generated_by` INT UNSIGNED NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'success',
  `message` TEXT NULL,
  `items_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `file_path` VARCHAR(500) NULL,
  `public_url` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seo_files_logs_type_created` (`file_type`, `created_at`),
  KEY `idx_seo_files_logs_generated_by` (`generated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
