-- Ophyra Growth Hub level 1 foundation.
-- Manual review required before execution.
-- Non-destructive: creates/extends central CMS/SEO tables scoped by id_owner + site_key.

START TRANSACTION;

-- Existing installations may already have CMS/media tables. Keep this migration
-- additive so Growth Hub can run on top of older schemas.

CREATE TABLE IF NOT EXISTS growth_sites (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  site_name VARCHAR(180) NOT NULL,
  domain VARCHAR(255) NULL,
  public_base_url VARCHAR(255) NULL,
  default_language VARCHAR(12) NOT NULL DEFAULT 'en',
  secondary_language VARCHAR(12) NULL,
  brand_voice TEXT NULL,
  target_locations JSON NULL,
  main_services JSON NULL,
  main_products JSON NULL,
  excluded_topics JSON NULL,
  allowed_content_types JSON NULL,
  default_cta_label VARCHAR(160) NULL,
  default_cta_url VARCHAR(255) NULL,
  phone VARCHAR(80) NULL,
  contact_email VARCHAR(190) NULL,
  cloudinary_folder VARCHAR(255) NULL,
  openai_model_preferences JSON NULL,
  approval_rules JSON NULL,
  auto_publish_allowed TINYINT(1) NOT NULL DEFAULT 0,
  sitemap_settings JSON NULL,
  route_rules JSON NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY growth_sites_owner_site_unique (id_owner, site_key),
  KEY growth_sites_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS id_owner INT(11) NOT NULL AFTER id;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NOT NULL AFTER id_owner;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS site_name VARCHAR(180) NOT NULL AFTER site_key;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS domain VARCHAR(255) NULL AFTER site_name;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS public_base_url VARCHAR(255) NULL AFTER domain;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS default_language VARCHAR(12) NOT NULL DEFAULT 'en' AFTER public_base_url;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS secondary_language VARCHAR(12) NULL AFTER default_language;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS brand_voice TEXT NULL AFTER secondary_language;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS target_locations JSON NULL AFTER brand_voice;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS main_services JSON NULL AFTER target_locations;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS main_products JSON NULL AFTER main_services;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS excluded_topics JSON NULL AFTER main_products;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS allowed_content_types JSON NULL AFTER excluded_topics;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS default_cta_label VARCHAR(160) NULL AFTER allowed_content_types;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS default_cta_url VARCHAR(255) NULL AFTER default_cta_label;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS phone VARCHAR(80) NULL AFTER default_cta_url;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS contact_email VARCHAR(190) NULL AFTER phone;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS cloudinary_folder VARCHAR(255) NULL AFTER contact_email;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS openai_model_preferences JSON NULL AFTER cloudinary_folder;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS approval_rules JSON NULL AFTER openai_model_preferences;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS auto_publish_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_rules;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS sitemap_settings JSON NULL AFTER auto_publish_allowed;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS route_rules JSON NULL AFTER sitemap_settings;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'active' AFTER route_rules;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status;
ALTER TABLE growth_sites ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE growth_sites ADD UNIQUE KEY IF NOT EXISTS growth_sites_owner_site_unique (id_owner, site_key);
ALTER TABLE growth_sites ADD KEY IF NOT EXISTS growth_sites_status_idx (status);

CREATE TABLE IF NOT EXISTS cms_contents (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  content_type VARCHAR(60) NOT NULL DEFAULT 'blog',
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(220) NOT NULL,
  excerpt TEXT NULL,
  body LONGTEXT NULL,
  seo_title VARCHAR(255) NULL,
  meta_description TEXT NULL,
  primary_keyword VARCHAR(255) NULL,
  target_location VARCHAR(180) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  approval_status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  scheduled_at DATETIME NULL,
  published_at DATETIME NULL,
  generated_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT(11) NULL,
  approved_by INT(11) NULL,
  schema_json JSON NULL,
  metadata_json JSON NULL,
  id_user_business INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_contents_owner_site_slug_unique (id_owner, site_key, slug),
  KEY cms_contents_owner_site_status_idx (id_owner, site_key, status),
  KEY cms_contents_type_idx (content_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS id_owner INT(11) NULL AFTER id;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NULL AFTER id_owner;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS content_type VARCHAR(60) NOT NULL DEFAULT 'blog' AFTER site_key;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL AFTER content_type;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS slug VARCHAR(220) NULL AFTER title;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS excerpt TEXT NULL AFTER slug;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS body LONGTEXT NULL AFTER excerpt;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS seo_title VARCHAR(255) NULL AFTER body;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS meta_description TEXT NULL AFTER seo_title;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS primary_keyword VARCHAR(255) NULL AFTER meta_description;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS target_location VARCHAR(180) NULL AFTER primary_keyword;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'DRAFT' AFTER target_location;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS approval_status VARCHAR(40) NOT NULL DEFAULT 'DRAFT' AFTER status;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS scheduled_at DATETIME NULL AFTER approval_status;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER scheduled_at;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS generated_by_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER published_at;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS created_by INT(11) NULL AFTER generated_by_agent;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS approved_by INT(11) NULL AFTER created_by;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS schema_json JSON NULL AFTER approved_by;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER schema_json;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS id_user_business INT(11) NULL AFTER metadata_json;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER id_user_business;
ALTER TABLE cms_contents ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE cms_contents ADD UNIQUE KEY IF NOT EXISTS cms_contents_owner_site_slug_unique (id_owner, site_key, slug);
ALTER TABLE cms_contents ADD KEY IF NOT EXISTS cms_contents_owner_site_status_idx (id_owner, site_key, status);
ALTER TABLE cms_contents ADD KEY IF NOT EXISTS cms_contents_type_idx (content_type);

CREATE TABLE IF NOT EXISTS cms_content_blocks (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_content INT(11) NOT NULL,
  block_type VARCHAR(80) NOT NULL,
  block_key VARCHAR(120) NULL,
  title VARCHAR(255) NULL,
  data_json JSON NULL,
  sort_order INT(11) NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  generated_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  locked_by_user TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY cms_content_blocks_content_idx (id_content),
  KEY cms_content_blocks_owner_site_idx (id_owner, site_key),
  KEY cms_content_blocks_type_idx (block_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS id_owner INT(11) NULL AFTER id;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NULL AFTER id_owner;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS id_content INT(11) NULL AFTER site_key;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS block_type VARCHAR(80) NULL AFTER id_content;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS block_key VARCHAR(120) NULL AFTER block_type;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL AFTER block_key;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS data_json JSON NULL AFTER title;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS sort_order INT(11) NOT NULL DEFAULT 0 AFTER data_json;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'active' AFTER sort_order;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS generated_by_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS locked_by_user TINYINT(1) NOT NULL DEFAULT 0 AFTER generated_by_agent;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER locked_by_user;
ALTER TABLE cms_content_blocks ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE cms_content_blocks ADD KEY IF NOT EXISTS cms_content_blocks_content_idx (id_content);
ALTER TABLE cms_content_blocks ADD KEY IF NOT EXISTS cms_content_blocks_owner_site_idx (id_owner, site_key);
ALTER TABLE cms_content_blocks ADD KEY IF NOT EXISTS cms_content_blocks_type_idx (block_type);

CREATE TABLE IF NOT EXISTS cms_routes (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_content INT(11) NULL,
  route VARCHAR(255) NOT NULL,
  route_type VARCHAR(60) NOT NULL DEFAULT 'landing',
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  canonical_url VARCHAR(255) NULL,
  redirect_to VARCHAR(255) NULL,
  route_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_routes_owner_site_route_unique (id_owner, site_key, route),
  KEY cms_routes_content_idx (id_content),
  KEY cms_routes_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS id_owner INT(11) NULL AFTER id;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NULL AFTER id_owner;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS id_content INT(11) NULL AFTER site_key;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS route VARCHAR(255) NULL AFTER id_content;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS route_type VARCHAR(60) NOT NULL DEFAULT 'landing' AFTER route;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'draft' AFTER route_type;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS canonical_url VARCHAR(255) NULL AFTER status;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS redirect_to VARCHAR(255) NULL AFTER canonical_url;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS route_hash CHAR(64) NULL AFTER redirect_to;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER route_hash;
ALTER TABLE cms_routes ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE cms_routes ADD UNIQUE KEY IF NOT EXISTS cms_routes_owner_site_route_unique (id_owner, site_key, route);
ALTER TABLE cms_routes ADD KEY IF NOT EXISTS cms_routes_content_idx (id_content);
ALTER TABLE cms_routes ADD KEY IF NOT EXISTS cms_routes_status_idx (status);

CREATE TABLE IF NOT EXISTS cms_media (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_content INT(11) NULL,
  related_block_id INT(11) NULL,
  cloudinary_public_id VARCHAR(255) NOT NULL,
  cloudinary_url TEXT NULL,
  secure_url TEXT NOT NULL,
  asset_type VARCHAR(40) NULL,
  media_type VARCHAR(80) NULL,
  source_type VARCHAR(40) NOT NULL DEFAULT 'uploaded',
  usage_type VARCHAR(40) NOT NULL DEFAULT 'gallery',
  prompt_used TEXT NULL,
  revised_prompt TEXT NULL,
  model VARCHAR(120) NULL,
  alt_text VARCHAR(255) NULL,
  title_text VARCHAR(255) NULL,
  caption TEXT NULL,
  width INT(11) NULL,
  height INT(11) NULL,
  format VARCHAR(30) NULL,
  bytes BIGINT NULL,
  folder VARCHAR(255) NULL,
  metadata_json JSON NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  created_by INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY cms_media_cloudinary_public_unique (cloudinary_public_id),
  KEY cms_media_owner_site_idx (id_owner, site_key),
  KEY cms_media_content_idx (id_content),
  KEY cms_media_source_usage_idx (source_type, usage_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS id_owner INT(11) NULL AFTER id;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS site_key VARCHAR(80) NULL AFTER id_owner;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS id_content INT(11) NULL AFTER site_key;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS related_block_id INT(11) NULL AFTER id_content;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS cloudinary_public_id VARCHAR(255) NULL AFTER related_block_id;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS cloudinary_url TEXT NULL AFTER cloudinary_public_id;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS secure_url TEXT NULL AFTER cloudinary_url;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS asset_type VARCHAR(40) NULL AFTER secure_url;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS media_type VARCHAR(80) NULL AFTER asset_type;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS source_type VARCHAR(40) NOT NULL DEFAULT 'uploaded' AFTER media_type;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS usage_type VARCHAR(40) NOT NULL DEFAULT 'gallery' AFTER source_type;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS prompt_used TEXT NULL AFTER usage_type;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS revised_prompt TEXT NULL AFTER prompt_used;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS model VARCHAR(120) NULL AFTER revised_prompt;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL AFTER model;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS title_text VARCHAR(255) NULL AFTER alt_text;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS caption TEXT NULL AFTER title_text;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS width INT(11) NULL AFTER caption;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS height INT(11) NULL AFTER width;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS format VARCHAR(30) NULL AFTER height;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS bytes BIGINT NULL AFTER format;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS folder VARCHAR(255) NULL AFTER bytes;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER folder;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'active' AFTER metadata_json;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS created_by INT(11) NULL AFTER status;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by;
ALTER TABLE cms_media ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
UPDATE cms_media SET cloudinary_public_id = CONCAT('legacy-', id) WHERE cloudinary_public_id IS NULL OR cloudinary_public_id = '';
UPDATE cms_media SET secure_url = COALESCE(secure_url, cloudinary_url, '') WHERE secure_url IS NULL;
ALTER TABLE cms_media MODIFY cloudinary_public_id VARCHAR(255) NOT NULL;
ALTER TABLE cms_media MODIFY secure_url TEXT NOT NULL;
ALTER TABLE cms_media ADD UNIQUE KEY IF NOT EXISTS cms_media_cloudinary_public_unique (cloudinary_public_id);
ALTER TABLE cms_media ADD KEY IF NOT EXISTS cms_media_owner_site_idx (id_owner, site_key);
ALTER TABLE cms_media ADD KEY IF NOT EXISTS cms_media_content_idx (id_content);
ALTER TABLE cms_media ADD KEY IF NOT EXISTS cms_media_source_usage_idx (source_type, usage_type);

CREATE TABLE IF NOT EXISTS seo_keywords (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  keyword_text VARCHAR(255) NOT NULL,
  location VARCHAR(180) NULL,
  service_or_product VARCHAR(190) NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'manual',
  avg_monthly_searches INT(11) NULL,
  competition VARCHAR(80) NULL,
  cpc_low DECIMAL(10,2) NULL,
  cpc_high DECIMAL(10,2) NULL,
  intent VARCHAR(60) NULL,
  priority_score DECIMAL(6,2) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  imported_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY seo_keywords_owner_site_keyword_unique (id_owner, site_key, keyword_text, location),
  KEY seo_keywords_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS growth_target_locations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  location_name VARCHAR(180) NOT NULL,
  county VARCHAR(180) NULL,
  state VARCHAR(80) NULL,
  country VARCHAR(80) NOT NULL DEFAULT 'US',
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  priority_score DECIMAL(6,2) NOT NULL DEFAULT 50.00,
  notes TEXT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY growth_locations_owner_site_name_unique (id_owner, site_key, location_name, county, state),
  KEY growth_locations_owner_site_idx (id_owner, site_key),
  KEY growth_locations_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS growth_competitors (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  competitor_name VARCHAR(190) NOT NULL,
  competitor_url VARCHAR(255) NOT NULL,
  competitor_domain VARCHAR(190) NOT NULL,
  county VARCHAR(180) NULL,
  city VARCHAR(180) NULL,
  state VARCHAR(80) NULL,
  service_or_product VARCHAR(190) NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'manual',
  notes TEXT NULL,
  scan_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  last_scanned_at DATETIME NULL,
  strengths_json JSON NULL,
  gaps_json JSON NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY growth_competitors_owner_site_domain_unique (id_owner, site_key, competitor_domain),
  KEY growth_competitors_owner_site_idx (id_owner, site_key),
  KEY growth_competitors_location_idx (county, city, state),
  KEY growth_competitors_scan_idx (scan_status, last_scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_serp_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_keyword INT(11) NULL,
  keyword_text VARCHAR(255) NOT NULL,
  location_name VARCHAR(180) NULL,
  county VARCHAR(180) NULL,
  state VARCHAR(80) NULL,
  device VARCHAR(40) NOT NULL DEFAULT 'desktop',
  search_engine VARCHAR(40) NOT NULL DEFAULT 'google',
  source VARCHAR(80) NOT NULL DEFAULT 'manual_serp',
  own_domain VARCHAR(190) NULL,
  own_best_position INT(11) NULL,
  own_best_url VARCHAR(255) NULL,
  top_competitor_domain VARCHAR(190) NULL,
  top_competitor_position INT(11) NULL,
  result_count INT(11) NULL,
  raw_response_json JSON NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY seo_serp_snapshots_owner_site_idx (id_owner, site_key),
  KEY seo_serp_snapshots_keyword_idx (id_keyword),
  KEY seo_serp_snapshots_location_idx (location_name, county, state),
  KEY seo_serp_snapshots_checked_idx (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_serp_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_snapshot BIGINT UNSIGNED NOT NULL,
  id_competitor INT(11) NULL,
  result_position INT(11) NOT NULL,
  result_title VARCHAR(255) NULL,
  result_url TEXT NULL,
  result_domain VARCHAR(190) NULL,
  snippet TEXT NULL,
  is_own_domain TINYINT(1) NOT NULL DEFAULT 0,
  result_type VARCHAR(60) NOT NULL DEFAULT 'organic',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY seo_serp_results_snapshot_idx (id_snapshot),
  KEY seo_serp_results_domain_idx (result_domain),
  KEY seo_serp_results_competitor_idx (id_competitor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_search_console_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  keyword_text VARCHAR(255) NOT NULL,
  page_url TEXT NULL,
  country VARCHAR(12) NULL,
  device VARCHAR(40) NULL,
  clicks INT(11) NOT NULL DEFAULT 0,
  impressions INT(11) NOT NULL DEFAULT 0,
  ctr DECIMAL(8,4) NULL,
  average_position DECIMAL(8,2) NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY seo_gsc_owner_site_keyword_idx (id_owner, site_key, keyword_text),
  KEY seo_gsc_dates_idx (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_google_trends_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  keyword_text VARCHAR(255) NOT NULL,
  location_name VARCHAR(180) NULL,
  geo_code VARCHAR(40) NULL,
  interest_score INT(11) NULL,
  trend_direction VARCHAR(40) NULL,
  related_queries_json JSON NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY seo_trends_owner_site_keyword_idx (id_owner, site_key, keyword_text),
  KEY seo_trends_location_idx (location_name, geo_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_opportunities (
  id INT(11) NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_keyword INT(11) NULL,
  opportunity_type VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  keyword_text VARCHAR(255) NULL,
  location_name VARCHAR(180) NULL,
  service_or_product VARCHAR(190) NULL,
  score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  reason_summary TEXT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'IDEA',
  recommended_content_type VARCHAR(60) NULL,
  recommended_route VARCHAR(255) NULL,
  data_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY seo_opportunities_owner_site_idx (id_owner, site_key),
  KEY seo_opportunities_status_idx (status),
  KEY seo_opportunities_score_idx (score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_agent_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT(11) NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  run_type VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
  input_json JSON NULL,
  output_json JSON NULL,
  summary TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY seo_agent_runs_owner_site_idx (id_owner, site_key),
  KEY seo_agent_runs_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO growth_sites
  (id_owner, site_key, site_name, domain, public_base_url, default_language, brand_voice, target_locations, main_services, main_products, default_cta_label, default_cta_url, cloudinary_folder, auto_publish_allowed, sitemap_settings, route_rules, status)
VALUES
  (2, 'vnvevents', 'VNV Events', 'vnvevents.com', 'https://vnvevents.com', 'en', 'Professional, warm, luxury but accessible, local South Florida expert.', JSON_ARRAY('Miami-Dade','Broward','Palm Beach','Miami','Doral','Weston','Sunrise','Fort Lauderdale','Coral Gables','Miami Lakes','Pembroke Pines','Hialeah','Kendall','Aventura','Hollywood FL','Boca Raton','West Palm Beach'), JSON_ARRAY('wedding planning','corporate events','quinceaneras','baby showers','event rentals','flowers and decor','DJ','photo and video','multimedia','bartending','event staffing'), JSON_ARRAY(), 'Request a Quote', '/quote', 'ophyra-growth-hub/vnvevents', 0, JSON_OBJECT('public_base_url', 'https://vnvevents.com', 'sitemap_url', 'https://vnvevents.com/sitemap.xml'), JSON_OBJECT('page', '/{slug}', 'landing', '/{slug}', 'custom', '/{slug}', 'location', '/locations/{slug}', 'blog', '/blog/{slug}'), 'active'),
  (2, 'avomeal', 'Avomeal / VNV Gourmet', 'avomeal.com', 'https://avomeal.com', 'en', 'Fresh, practical, warm, food-forward and reliable for meals and catering.', JSON_ARRAY('Miami','Doral','Broward','South Florida'), JSON_ARRAY('catering','meal prep','office lunch','event food setup'), JSON_ARRAY('meal prep containers','catering trays','Venezuelan food','office lunches'), 'Order / Request Catering', '/order', 'ophyra-growth-hub/avomeal', 0, JSON_OBJECT('public_base_url', 'https://avomeal.com', 'sitemap_url', 'https://avomeal.com/sitemap.xml'), JSON_OBJECT('page', '/{slug}', 'landing', '/{slug}', 'custom', '/{slug}', 'location', '/locations/{slug}', 'blog', '/blog/{slug}'), 'active'),
  (2, 'jonnysmedia', 'Jonnys Media', 'jonnys.media', 'https://jonnys.media', 'en', 'Creative, direct, technical and premium visual production.', JSON_ARRAY('Miami','South Florida'), JSON_ARRAY('photo','video','multimedia sessions','creative production'), JSON_ARRAY(), 'Book a Session', '/contact', 'ophyra-growth-hub/jonnysmedia', 0, JSON_OBJECT('public_base_url', 'https://jonnys.media', 'sitemap_url', 'https://jonnys.media/sitemap.xml'), JSON_OBJECT('page', '/{slug}', 'landing', '/{slug}', 'custom', '/{slug}', 'location', '/locations/{slug}', 'blog', '/blog/{slug}'), 'active')
ON DUPLICATE KEY UPDATE
  site_name = VALUES(site_name),
  domain = COALESCE(NULLIF(domain, ''), VALUES(domain)),
  public_base_url = COALESCE(NULLIF(public_base_url, ''), VALUES(public_base_url)),
  cloudinary_folder = VALUES(cloudinary_folder),
  sitemap_settings = COALESCE(sitemap_settings, VALUES(sitemap_settings)),
  route_rules = COALESCE(route_rules, VALUES(route_rules)),
  updated_at = NOW();

INSERT INTO growth_target_locations
  (id_owner, site_key, location_name, county, state, lat, lng, priority_score, notes, status)
VALUES
  (2, 'vnvevents', 'Miami', 'Miami-Dade', 'FL', 25.7617000, -80.1918000, 95.00, 'Core South Florida market.', 'active'),
  (2, 'vnvevents', 'Doral', 'Miami-Dade', 'FL', 25.8195000, -80.3553000, 90.00, 'Important quinceanera, corporate and event planning market.', 'active'),
  (2, 'vnvevents', 'Weston', 'Broward', 'FL', 26.1004000, -80.3998000, 82.00, 'High-value event planning target area.', 'active'),
  (2, 'vnvevents', 'Fort Lauderdale', 'Broward', 'FL', 26.1224000, -80.1373000, 85.00, 'Broward event planning target.', 'active'),
  (2, 'vnvevents', 'West Palm Beach', 'Palm Beach', 'FL', 26.7153000, -80.0534000, 78.00, 'Palm Beach expansion target.', 'active'),
  (2, 'avomeal', 'Miami', 'Miami-Dade', 'FL', 25.7617000, -80.1918000, 92.00, 'Meal prep and catering core market.', 'active'),
  (2, 'avomeal', 'Doral', 'Miami-Dade', 'FL', 25.8195000, -80.3553000, 88.00, 'Office lunch and catering target.', 'active'),
  (2, 'jonnysmedia', 'Miami', 'Miami-Dade', 'FL', 25.7617000, -80.1918000, 90.00, 'Creative production core market.', 'active')
ON DUPLICATE KEY UPDATE
  priority_score = VALUES(priority_score),
  notes = VALUES(notes),
  updated_at = NOW();

COMMIT;
