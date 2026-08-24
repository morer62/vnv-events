-- Internal documents for the Level 1 Weekly Execution board.
-- These records are intentionally separate from orders_files so they are
-- never exposed in client order pages or public Order Access.

CREATE TABLE IF NOT EXISTS `order_execution_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` INT UNSIGNED NOT NULL,
  `id_owner` INT UNSIGNED NOT NULL,
  `id_uploaded_by` INT UNSIGNED NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_execution_files_order` (`id_order`, `created_at`),
  KEY `idx_execution_files_owner` (`id_owner`, `id_order`),
  CONSTRAINT `fk_execution_files_order`
    FOREIGN KEY (`id_order`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
