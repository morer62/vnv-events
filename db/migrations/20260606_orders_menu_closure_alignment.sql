CREATE TABLE IF NOT EXISTS `menu_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_categories_order` (`display_order`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_category_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_category` INT UNSIGNED NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `price_info` VARCHAR(120) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_category_items_category` (`id_category`, `display_order`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_service_menu_selections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` INT UNSIGNED NOT NULL,
  `id_suborder` INT UNSIGNED NULL,
  `id_service` INT UNSIGNED NOT NULL,
  `id_menu_category_item` INT UNSIGNED NOT NULL,
  `source` ENUM('main_order','suborder') NOT NULL DEFAULT 'main_order',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_service_menu_order` (`id_order`, `id_service`, `source`),
  KEY `idx_order_service_menu_suborder` (`id_suborder`),
  KEY `idx_order_service_menu_item` (`id_menu_category_item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders_closure_communications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` INT UNSIGNED NOT NULL,
  `description` TEXT NOT NULL,
  `photo_path` VARCHAR(500) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_closure_order` (`id_order`),
  KEY `idx_orders_closure_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders_team_order_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` INT UNSIGNED NOT NULL,
  `id_user` INT UNSIGNED NOT NULL,
  `photo_url` VARCHAR(500) NOT NULL,
  `uploaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_team_photos_order` (`id_order`, `uploaded_at`),
  KEY `idx_orders_team_photos_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders_acceptance_contracts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_acceptance_contracts_order` (`id_order`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
