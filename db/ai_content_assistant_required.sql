-- AI Content Assistant required tables.
-- Review before running. This file is intentionally not executed by Codex.

CREATE TABLE IF NOT EXISTS `ai_content_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NULL,
  `id_user_business` INT NULL,
  `site_key` VARCHAR(80) NOT NULL,
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_content_settings_site_key` (`site_key`, `setting_key`),
  KEY `idx_ai_content_settings_owner` (`id_owner`),
  KEY `idx_ai_content_settings_business` (`id_user_business`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_content_drafts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_owner` INT NULL,
  `id_user_business` INT NULL,
  `site_key` VARCHAR(80) NOT NULL,
  `brand_name` VARCHAR(160) NOT NULL,
  `content_type` ENUM('blog_post','location_page') NOT NULL,
  `status` ENUM('IDEA','DRAFT','NEEDS_REVIEW','REVISION_REQUESTED','APPROVED','PUBLISHED','REJECTED','ARCHIVED') NOT NULL DEFAULT 'NEEDS_REVIEW',
  `language` VARCHAR(12) NOT NULL DEFAULT 'en',
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `topic` VARCHAR(255) NULL,
  `service_name` VARCHAR(160) NULL,
  `city` VARCHAR(120) NULL,
  `state` VARCHAR(40) NULL,
  `excerpt` TEXT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `meta_title` VARCHAR(255) NULL,
  `meta_description` TEXT NULL,
  `meta_keywords` TEXT NULL,
  `schema_json` MEDIUMTEXT NULL,
  `faq_json` MEDIUMTEXT NULL,
  `thumbnail_prompt` TEXT NULL,
  `featured_image_url` TEXT NULL,
  `source_notes_json` MEDIUMTEXT NULL,
  `internal_links_json` MEDIUMTEXT NULL,
  `ai_model` VARCHAR(120) NULL,
  `ai_prompt_hash` CHAR(64) NULL,
  `review_feedback` TEXT NULL,
  `voice_note_path` TEXT NULL,
  `published_entity_type` VARCHAR(80) NULL,
  `published_entity_id` INT NULL,
  `published_at` DATETIME NULL,
  `created_by` INT NULL,
  `reviewed_by` INT NULL,
  `approved_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_content_draft_site_type_slug` (`site_key`, `content_type`, `slug`),
  KEY `idx_ai_content_drafts_status` (`site_key`, `status`, `updated_at`),
  KEY `idx_ai_content_drafts_owner` (`id_owner`),
  KEY `idx_ai_content_drafts_business` (`id_user_business`),
  KEY `idx_ai_content_drafts_location_topic` (`site_key`, `content_type`, `service_name`, `city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_content_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_draft` INT UNSIGNED NOT NULL,
  `id_user` INT NULL,
  `action` VARCHAR(80) NOT NULL,
  `feedback` TEXT NULL,
  `voice_note_path` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_content_reviews_draft` (`id_draft`),
  KEY `idx_ai_content_reviews_user` (`id_user`),
  CONSTRAINT `fk_ai_content_reviews_draft`
    FOREIGN KEY (`id_draft`) REFERENCES `ai_content_drafts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_content_sources` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_draft` INT UNSIGNED NOT NULL,
  `source_type` VARCHAR(80) NOT NULL,
  `source_url` TEXT NULL,
  `source_title` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_content_sources_draft` (`id_draft`),
  CONSTRAINT `fk_ai_content_sources_draft`
    FOREIGN KEY (`id_draft`) REFERENCES `ai_content_drafts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_content_assets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_draft` INT UNSIGNED NOT NULL,
  `asset_type` VARCHAR(80) NOT NULL,
  `file_url` TEXT NOT NULL,
  `original_name` VARCHAR(255) NULL,
  `mime_type` VARCHAR(120) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_content_assets_draft` (`id_draft`),
  CONSTRAINT `fk_ai_content_assets_draft`
    FOREIGN KEY (`id_draft`) REFERENCES `ai_content_drafts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ai_content_settings`
  (`id_owner`, `id_user_business`, `site_key`, `setting_key`, `setting_value`, `created_at`, `updated_at`)
VALUES
  (2, 2, 'vnv_events', 'enabled', '0', NOW(), NOW()),
  (2, 2, 'vnv_events', 'daily_blog_count', '1', NOW(), NOW()),
  (2, 2, 'vnv_events', 'daily_location_count', '5', NOW(), NOW()),
  (2, 2, 'vnv_events', 'auto_publish', '0', NOW(), NOW()),
  (2, 2, 'vnv_events', 'require_approval', '1', NOW(), NOW()),
  (2, 2, 'vnv_events', 'default_language', 'en', NOW(), NOW()),
  (2, 2, 'vnv_events', 'brand_name', 'VNV Events', NOW(), NOW()),
  (2, 2, 'vnv_events', 'priority_services', 'wedding planning, corporate events, social events, event rentals, catering coordination', NOW(), NOW()),
  (2, 2, 'vnv_events', 'priority_cities', 'Miami, Doral, Fort Lauderdale, Hollywood, Weston, Pembroke Pines, Coral Gables', NOW(), NOW()),
  (2, 2, 'vnv_events', 'location_state', 'FL', NOW(), NOW()),
  (2, 2, 'vnv_events', 'max_pending_drafts', '50', NOW(), NOW()),
  (2, 2, 'vnv_events', 'cloudinary_enabled', '1', NOW(), NOW()),
  (2, 2, 'vnv_events', 'reddit_sources_enabled', '0', NOW(), NOW()),
  (2, 2, 'avomeal', 'enabled', '0', NOW(), NOW()),
  (2, 2, 'avomeal', 'daily_blog_count', '1', NOW(), NOW()),
  (2, 2, 'avomeal', 'daily_location_count', '5', NOW(), NOW()),
  (2, 2, 'avomeal', 'auto_publish', '0', NOW(), NOW()),
  (2, 2, 'avomeal', 'require_approval', '1', NOW(), NOW()),
  (2, 2, 'avomeal', 'default_language', 'en', NOW(), NOW()),
  (2, 2, 'avomeal', 'brand_name', 'Avomeal', NOW(), NOW()),
  (2, 2, 'avomeal', 'priority_services', 'meal preps, holiday menus, party boxes, prepared meals, appetizers, desserts', NOW(), NOW()),
  (2, 2, 'avomeal', 'priority_cities', 'Miami, Doral, Fort Lauderdale, Hollywood, Weston, Pembroke Pines, Coral Gables', NOW(), NOW()),
  (2, 2, 'avomeal', 'location_state', 'FL', NOW(), NOW()),
  (2, 2, 'avomeal', 'max_pending_drafts', '50', NOW(), NOW()),
  (2, 2, 'avomeal', 'cloudinary_enabled', '1', NOW(), NOW()),
  (2, 2, 'avomeal', 'reddit_sources_enabled', '0', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `updated_at` = NOW();
