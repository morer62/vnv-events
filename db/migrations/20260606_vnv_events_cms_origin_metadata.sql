-- VNV Events local CMS compatibility + origin metadata.
-- Safe to run more than once on MySQL 8+ / MariaDB with ADD COLUMN IF NOT EXISTS.
-- This does not rename or remove Ophyra Growth Hub columns; it only ensures
-- VNV Events local CMS tables/columns exist and remain scoped to site_key='vnvevents'.

CREATE TABLE IF NOT EXISTS cms_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NULL,
  site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  name VARCHAR(190) NOT NULL,
  template_key VARCHAR(190) NOT NULL,
  description TEXT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'page',
  preview_html LONGTEXT NULL,
  template_structure_json MEDIUMTEXT NULL,
  css_text LONGTEXT NULL,
  metadata_json MEDIUMTEXT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_templates_template_key_unique (template_key),
  KEY cms_templates_owner_type_idx (id_owner, type),
  KEY cms_templates_site_idx (site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NULL,
  site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  description TEXT NULL,
  featured_image_url VARCHAR(500) NULL,
  featured_image_alt VARCHAR(255) NULL,
  applies_to_pages TINYINT(1) NOT NULL DEFAULT 1,
  applies_to_blog TINYINT(1) NOT NULL DEFAULT 1,
  applies_to_locations TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events',
  origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  created_by INT NULL,
  updated_by INT NULL,
  origin_metadata_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_categories_site_slug_unique (site_key, slug),
  KEY cms_categories_owner_site_idx (id_owner, site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NULL,
  site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  description TEXT NULL,
  meta_title VARCHAR(255) NULL,
  meta_description TEXT NULL,
  meta_keywords TEXT NULL,
  featured_image_url VARCHAR(500) NULL,
  schema_json MEDIUMTEXT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ACTIVE',
  content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events',
  origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  created_by INT NULL,
  updated_by INT NULL,
  origin_metadata_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY blog_categories_site_slug_unique (site_key, slug),
  KEY blog_categories_owner_site_idx (id_owner, site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_contents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NULL,
  site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  id_template INT NULL,
  id_blog_category INT NULL,
  id_cms_category INT NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'page',
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(220) NOT NULL,
  language VARCHAR(12) NOT NULL DEFAULT 'en',
  content_mode VARCHAR(40) NOT NULL DEFAULT 'builder',
  excerpt TEXT NULL,
  content_json LONGTEXT NULL,
  body_html LONGTEXT NULL,
  meta_title VARCHAR(255) NULL,
  meta_description TEXT NULL,
  meta_keywords TEXT NULL,
  canonical_url VARCHAR(255) NULL,
  robots VARCHAR(120) NOT NULL DEFAULT 'index,follow',
  schema_json MEDIUMTEXT NULL,
  featured_image_url VARCHAR(500) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  is_homepage TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  last_generated_at DATETIME NULL,
  content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events',
  origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  created_by INT NULL,
  updated_by INT NULL,
  origin_metadata_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_contents_site_type_lang_slug_unique (site_key, type, language, slug),
  KEY cms_contents_owner_site_status_idx (id_owner, site_key, status),
  KEY cms_contents_type_lang_idx (type, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_routes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NULL,
  site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  id_content INT NULL,
  route VARCHAR(255) NOT NULL,
  route_hash CHAR(64) NULL,
  is_main TINYINT(1) NOT NULL DEFAULT 1,
  language VARCHAR(12) NOT NULL DEFAULT 'en',
  public_php_path VARCHAR(500) NULL,
  public_twig_path VARCHAR(500) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ACTIVE',
  redirect_to VARCHAR(255) NULL,
  content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events',
  origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  created_by INT NULL,
  updated_by INT NULL,
  origin_metadata_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_routes_site_route_lang_unique (site_key, route, language),
  KEY cms_routes_content_idx (id_content),
  KEY cms_routes_owner_site_idx (id_owner, site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_location_pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NULL,
  site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(220) NOT NULL,
  category VARCHAR(120) NULL,
  template_key VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  county VARCHAR(120) NULL,
  state VARCHAR(80) NULL,
  hero_title VARCHAR(255) NULL,
  hero_subtitle TEXT NULL,
  excerpt TEXT NULL,
  content_long LONGTEXT NULL,
  primary_keyword VARCHAR(255) NULL,
  secondary_keywords TEXT NULL,
  hero_image VARCHAR(500) NULL,
  gallery_json MEDIUMTEXT NULL,
  faq_json MEDIUMTEXT NULL,
  dynamic_blocks_json LONGTEXT NULL,
  meta_title VARCHAR(255) NULL,
  meta_description TEXT NULL,
  meta_keywords TEXT NULL,
  og_title VARCHAR(255) NULL,
  og_description TEXT NULL,
  og_image VARCHAR(500) NULL,
  canonical_url VARCHAR(255) NULL,
  schema_json MEDIUMTEXT NULL,
  custom_css LONGTEXT NULL,
  custom_js LONGTEXT NULL,
  is_indexable TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  published_at DATETIME NULL,
  content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events',
  origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents',
  created_by INT NULL,
  updated_by INT NULL,
  origin_metadata_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_location_pages_site_slug_unique (site_key, slug),
  KEY cms_location_pages_owner_site_status_idx (id_owner, site_key, status),
  KEY cms_location_pages_city_state_idx (city, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normalize Ophyra Growth Hub tables so the local VNV Events CMS code can use them too.
ALTER TABLE cms_templates
    ADD COLUMN IF NOT EXISTS id_owner INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner,
    ADD COLUMN IF NOT EXISTS name VARCHAR(190) NULL AFTER site_key,
    ADD COLUMN IF NOT EXISTS template_key VARCHAR(190) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER template_key,
    ADD COLUMN IF NOT EXISTS type VARCHAR(60) NOT NULL DEFAULT 'page' AFTER description,
    ADD COLUMN IF NOT EXISTS preview_html LONGTEXT NULL AFTER type,
    ADD COLUMN IF NOT EXISTS template_structure_json MEDIUMTEXT NULL AFTER preview_html,
    ADD COLUMN IF NOT EXISTS css_text LONGTEXT NULL AFTER template_structure_json,
    ADD COLUMN IF NOT EXISTS metadata_json MEDIUMTEXT NULL AFTER css_text,
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'ACTIVE' AFTER metadata_json,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE cms_contents
    ADD COLUMN IF NOT EXISTS id_owner INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner,
    ADD COLUMN IF NOT EXISTS id_template INT NULL AFTER site_key,
    ADD COLUMN IF NOT EXISTS id_blog_category INT NULL AFTER id_template,
    ADD COLUMN IF NOT EXISTS type VARCHAR(40) NOT NULL DEFAULT 'page' AFTER id_blog_category,
    ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL AFTER type,
    ADD COLUMN IF NOT EXISTS slug VARCHAR(220) NULL AFTER title,
    ADD COLUMN IF NOT EXISTS language VARCHAR(12) NOT NULL DEFAULT 'en' AFTER slug,
    ADD COLUMN IF NOT EXISTS content_mode VARCHAR(40) NOT NULL DEFAULT 'builder' AFTER language,
    ADD COLUMN IF NOT EXISTS excerpt TEXT NULL AFTER content_mode,
    ADD COLUMN IF NOT EXISTS content_json LONGTEXT NULL AFTER excerpt,
    ADD COLUMN IF NOT EXISTS body_html LONGTEXT NULL AFTER content_json,
    ADD COLUMN IF NOT EXISTS meta_title VARCHAR(255) NULL AFTER body_html,
    ADD COLUMN IF NOT EXISTS meta_description TEXT NULL AFTER meta_title,
    ADD COLUMN IF NOT EXISTS meta_keywords TEXT NULL AFTER meta_description,
    ADD COLUMN IF NOT EXISTS canonical_url VARCHAR(255) NULL AFTER meta_keywords,
    ADD COLUMN IF NOT EXISTS robots VARCHAR(120) NOT NULL DEFAULT 'index,follow' AFTER canonical_url,
    ADD COLUMN IF NOT EXISTS schema_json MEDIUMTEXT NULL AFTER robots,
    ADD COLUMN IF NOT EXISTS featured_image_url VARCHAR(500) NULL AFTER schema_json,
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'DRAFT' AFTER featured_image_url,
    ADD COLUMN IF NOT EXISTS is_homepage TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER is_homepage,
    ADD COLUMN IF NOT EXISTS last_generated_at DATETIME NULL AFTER published_at,
    ADD COLUMN IF NOT EXISTS content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events' AFTER last_generated_at,
    ADD COLUMN IF NOT EXISTS origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER content_origin,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER origin_site_key,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS origin_metadata_json MEDIUMTEXT NULL AFTER updated_by,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER origin_metadata_json,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE cms_routes
    ADD COLUMN IF NOT EXISTS id_owner INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner,
    ADD COLUMN IF NOT EXISTS id_content INT NULL AFTER site_key,
    ADD COLUMN IF NOT EXISTS route VARCHAR(255) NULL AFTER id_content,
    ADD COLUMN IF NOT EXISTS route_hash CHAR(64) NULL AFTER route,
    ADD COLUMN IF NOT EXISTS is_main TINYINT(1) NOT NULL DEFAULT 1 AFTER route_hash,
    ADD COLUMN IF NOT EXISTS language VARCHAR(12) NOT NULL DEFAULT 'en' AFTER is_main,
    ADD COLUMN IF NOT EXISTS public_php_path VARCHAR(500) NULL AFTER language,
    ADD COLUMN IF NOT EXISTS public_twig_path VARCHAR(500) NULL AFTER public_php_path,
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'ACTIVE' AFTER public_twig_path,
    ADD COLUMN IF NOT EXISTS redirect_to VARCHAR(255) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events' AFTER redirect_to,
    ADD COLUMN IF NOT EXISTS origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER content_origin,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER origin_site_key,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS origin_metadata_json MEDIUMTEXT NULL AFTER updated_by,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER origin_metadata_json,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE cms_location_pages
    ADD COLUMN IF NOT EXISTS content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events' AFTER published_at,
    ADD COLUMN IF NOT EXISTS origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER content_origin,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER origin_site_key,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS origin_metadata_json MEDIUMTEXT NULL AFTER updated_by;

ALTER TABLE blog_categories
    ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner,
    ADD COLUMN IF NOT EXISTS id_cms_category INT NULL AFTER id_blog_category,
    ADD COLUMN IF NOT EXISTS content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events' AFTER status,
    ADD COLUMN IF NOT EXISTS origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER content_origin,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER origin_site_key,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS origin_metadata_json MEDIUMTEXT NULL AFTER updated_by;

ALTER TABLE cms_categories
    ADD COLUMN IF NOT EXISTS id_owner INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER id_owner,
    ADD COLUMN IF NOT EXISTS featured_image_url VARCHAR(500) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS featured_image_alt VARCHAR(255) NULL AFTER featured_image_url,
    ADD COLUMN IF NOT EXISTS applies_to_pages TINYINT(1) NOT NULL DEFAULT 1 AFTER featured_image_alt,
    ADD COLUMN IF NOT EXISTS applies_to_blog TINYINT(1) NOT NULL DEFAULT 1 AFTER applies_to_pages,
    ADD COLUMN IF NOT EXISTS applies_to_locations TINYINT(1) NOT NULL DEFAULT 1 AFTER applies_to_blog,
    ADD COLUMN IF NOT EXISTS content_origin VARCHAR(80) NOT NULL DEFAULT 'vnv_events' AFTER is_active,
    ADD COLUMN IF NOT EXISTS origin_site_key VARCHAR(80) NOT NULL DEFAULT 'vnvevents' AFTER content_origin,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER origin_site_key,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS origin_metadata_json MEDIUMTEXT NULL AFTER updated_by;

UPDATE cms_contents SET site_key = 'vnvevents' WHERE site_key IS NULL OR site_key = '';
UPDATE cms_templates SET site_key = 'vnvevents' WHERE site_key IS NULL OR site_key = '';
UPDATE cms_contents SET content_origin = 'vnv_events', origin_site_key = 'vnvevents' WHERE content_origin IS NULL OR content_origin = '';
UPDATE cms_routes SET site_key = 'vnvevents' WHERE site_key IS NULL OR site_key = '';
UPDATE cms_routes SET content_origin = 'vnv_events', origin_site_key = 'vnvevents' WHERE content_origin IS NULL OR content_origin = '';
UPDATE cms_location_pages SET site_key = 'vnvevents' WHERE site_key IS NULL OR site_key = '';
UPDATE cms_location_pages SET content_origin = 'vnv_events', origin_site_key = 'vnvevents' WHERE content_origin IS NULL OR content_origin = '';
UPDATE blog_categories SET site_key = 'vnvevents' WHERE site_key IS NULL OR site_key = '';
UPDATE blog_categories SET content_origin = 'vnv_events', origin_site_key = 'vnvevents' WHERE content_origin IS NULL OR content_origin = '';
UPDATE cms_categories SET site_key = 'vnvevents' WHERE site_key IS NULL OR site_key = '';
UPDATE cms_categories SET content_origin = 'vnv_events', origin_site_key = 'vnvevents' WHERE content_origin IS NULL OR content_origin = '';
