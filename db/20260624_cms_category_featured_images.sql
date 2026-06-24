-- Adds featured image support to unified CMS categories.
-- Safe to run more than once on MySQL 8+ / MariaDB with ADD COLUMN IF NOT EXISTS.

ALTER TABLE cms_categories
    ADD COLUMN IF NOT EXISTS featured_image_url VARCHAR(500) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS featured_image_alt VARCHAR(255) NULL AFTER featured_image_url;

