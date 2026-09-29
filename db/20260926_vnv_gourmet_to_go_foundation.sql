-- VNV Gourmet To Go foundation.
-- Idempotent schema additions. Scope every record by id_owner + site_key.
SET NAMES utf8mb4;

-- Base commerce columns may not exist in production when the earlier To Go
-- migration was not executed. Define them here so this foundation is complete
-- and safe to rerun after a partially successful phpMyAdmin execution.
ALTER TABLE store_products
  ADD COLUMN IF NOT EXISTS purchase_mode VARCHAR(24) NOT NULL DEFAULT 'REQUEST',
  ADD COLUMN IF NOT EXISTS allow_immediate_payment TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS allow_recurring_purchase TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(24) NOT NULL DEFAULT 'SERVICE';

ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS fulfillment_method VARCHAR(24) NOT NULL DEFAULT 'DELIVERY',
  ADD COLUMN IF NOT EXISTS delivery_timing VARCHAR(24) NOT NULL DEFAULT 'SCHEDULED',
  ADD COLUMN IF NOT EXISTS requested_delivery_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS promised_delivery_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS store_gourmet_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
  recurring_payment_lead_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48,
  minimum_order_notice_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  delivery_start_time TIME NOT NULL DEFAULT '08:00:00',
  delivery_end_time TIME NOT NULL DEFAULT '20:00:00',
  slot_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  preparation_buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_weekdays VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  delivery_markup_percent DECIMAL(6,3) NOT NULL DEFAULT 10.000,
  delivery_rounding_increment DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  material_price_change_percent DECIMAL(6,3) NOT NULL DEFAULT 10.000,
  tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  occurrence_horizon_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  service_area_json LONGTEXT NULL,
  pickup_name VARCHAR(160) NULL,
  pickup_address_1 VARCHAR(255) NULL,
  pickup_city VARCHAR(120) NULL,
  pickup_state VARCHAR(40) NULL,
  pickup_zip VARCHAR(20) NULL,
  pickup_country CHAR(2) NOT NULL DEFAULT 'US',
  pickup_latitude DECIMAL(10,7) NULL,
  pickup_longitude DECIMAL(10,7) NULL,
  blackout_dates_json LONGTEXT NULL,
  fallback_delivery_tiers_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_gourmet_settings_scope (id_owner, site_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_gourmet_settings
  ADD COLUMN IF NOT EXISTS tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000 AFTER material_price_change_percent,
  ADD COLUMN IF NOT EXISTS service_area_json LONGTEXT NULL AFTER occurrence_horizon_weeks,
  ADD COLUMN IF NOT EXISTS pickup_name VARCHAR(160) NULL AFTER service_area_json,
  ADD COLUMN IF NOT EXISTS pickup_address_1 VARCHAR(255) NULL AFTER pickup_name,
  ADD COLUMN IF NOT EXISTS pickup_city VARCHAR(120) NULL AFTER pickup_address_1,
  ADD COLUMN IF NOT EXISTS pickup_state VARCHAR(40) NULL AFTER pickup_city,
  ADD COLUMN IF NOT EXISTS pickup_zip VARCHAR(20) NULL AFTER pickup_state,
  ADD COLUMN IF NOT EXISTS pickup_country CHAR(2) NOT NULL DEFAULT 'US' AFTER pickup_zip,
  ADD COLUMN IF NOT EXISTS pickup_latitude DECIMAL(10,7) NULL AFTER pickup_country,
  ADD COLUMN IF NOT EXISTS pickup_longitude DECIMAL(10,7) NULL AFTER pickup_latitude;

INSERT INTO store_gourmet_settings
  (id_owner,site_key,timezone,recurring_payment_lead_hours,minimum_order_notice_hours,delivery_start_time,delivery_end_time,slot_duration_minutes,delivery_markup_percent,delivery_rounding_increment,service_area_json,fallback_delivery_tiers_json)
VALUES
  (2,'vnvevents','America/New_York',48,24,'08:00:00','20:00:00',60,10.000,1.00,
   JSON_OBJECT('counties',JSON_ARRAY('Broward County','Miami-Dade County'),'cities',JSON_ARRAY('Boca Raton')),
   JSON_ARRAY(
     JSON_OBJECT('min_miles',0,'max_miles',5,'fee',10.00),
     JSON_OBJECT('min_miles',5,'max_miles',8,'fee',12.00),
     JSON_OBJECT('min_miles',8,'max_miles',11,'fee',14.00),
     JSON_OBJECT('min_miles',11,'max_miles',14,'fee',16.00),
     JSON_OBJECT('min_miles',14,'max_miles',17,'fee',18.50),
     JSON_OBJECT('min_miles',17,'max_miles',20,'fee',20.00)
   ))
ON DUPLICATE KEY UPDATE updated_at=updated_at;

UPDATE store_gourmet_settings
SET tax_rate_percent=7.000,
    service_area_json=JSON_OBJECT(
      'counties',JSON_ARRAY('Miami-Dade County','Broward County'),
      'cities',JSON_ARRAY('Boca Raton'),
      'state','FL',
      'country','US'
    ),
    pickup_name='VNV Events / VNV Gourmet',
    pickup_address_1='10258 NW 47th St',
    pickup_city='Sunrise',
    pickup_state='FL',
    pickup_zip='33351',
    pickup_country='US',
    pickup_latitude=26.1832823,
    pickup_longitude=-80.2863131
WHERE id_owner=2 AND site_key='vnvevents';

CREATE TABLE IF NOT EXISTS store_product_food_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  minimum_servings SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  included_servings SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  maximum_servings SMALLINT UNSIGNED NULL,
  additional_person_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cooking_preferences_json LONGTEXT NULL,
  preparation_instructions MEDIUMTEXT NULL,
  reheating_instructions MEDIUMTEXT NULL,
  serving_instructions MEDIUMTEXT NULL,
  plating_instructions MEDIUMTEXT NULL,
  presentation_instructions MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_food_profile_product (id_owner,site_key,id_product),
  CONSTRAINT fk_store_food_profile_product FOREIGN KEY (id_product) REFERENCES store_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_product_food_profiles
  ADD COLUMN IF NOT EXISTS cooking_preferences_json LONGTEXT NULL AFTER additional_person_price;

CREATE TABLE IF NOT EXISTS store_product_relationships (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  id_related_product INT NOT NULL,
  relationship_type ENUM('INCLUDED_SIDE','OPTIONAL_SIDE','PAID_SIDE','ADD_ON','RELATED') NOT NULL,
  included_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  price_override DECIMAL(10,2) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_product_relationship (id_owner,site_key,id_product,id_related_product,relationship_type),
  KEY idx_store_product_relationship_related (id_related_product),
  CONSTRAINT fk_store_product_relationship_product FOREIGN KEY (id_product) REFERENCES store_products(id) ON DELETE CASCADE,
  CONSTRAINT fk_store_product_relationship_related FOREIGN KEY (id_related_product) REFERENCES store_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_product_recommendations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  image_url VARCHAR(500) NULL,
  destination_url VARCHAR(500) NULL,
  is_external TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_product_recommendations (id_owner,site_key,id_product,status,sort_order),
  CONSTRAINT fk_store_product_recommendation_product FOREIGN KEY (id_product) REFERENCES store_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_recurring_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_user INT NULL,
  id_client INT NULL,
  saved_payment_method_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  status ENUM('ACTIVE','PAUSED','CANCELLED','COMPLETED','PAYMENT_ACTION_REQUIRED') NOT NULL DEFAULT 'ACTIVE',
  recurrence_type ENUM('WEEKLY','SELECTED_WEEKDAYS','INTERVAL_DAYS','INTERVAL_WEEKS') NOT NULL,
  interval_value SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  weekdays_json VARCHAR(100) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
  local_delivery_time TIME NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  occurrence_limit SMALLINT UNSIGNED NULL,
  payment_lead_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48,
  delivery_address_json LONGTEXT NOT NULL,
  delivery_instructions TEXT NULL,
  expected_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  next_occurrence_at_utc DATETIME NULL,
  paused_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_recurring_orders_customer (id_owner,site_key,id_user,status),
  KEY idx_store_recurring_orders_next (status,next_occurrence_at_utc),
  CONSTRAINT fk_store_recurring_saved_method FOREIGN KEY (saved_payment_method_id) REFERENCES client_saved_payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_recurring_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recurring_order_id BIGINT UNSIGNED NOT NULL,
  id_product INT NOT NULL,
  id_product_variation INT NULL,
  product_name_snapshot VARCHAR(200) NOT NULL,
  variation_name_snapshot VARCHAR(180) NULL,
  configuration_json LONGTEXT NULL,
  quantity INT NOT NULL DEFAULT 1,
  servings SMALLINT UNSIGNED NULL,
  expected_unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_recurring_items_parent (recurring_order_id),
  CONSTRAINT fk_store_recurring_item_parent FOREIGN KEY (recurring_order_id) REFERENCES store_recurring_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_store_recurring_item_product FOREIGN KEY (id_product) REFERENCES store_products(id),
  CONSTRAINT fk_store_recurring_item_variation FOREIGN KEY (id_product_variation) REFERENCES store_product_variations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_recurring_occurrences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recurring_order_id BIGINT UNSIGNED NOT NULL,
  id_store_order INT NULL,
  sequence_number INT UNSIGNED NOT NULL,
  scheduled_at_utc DATETIME NOT NULL,
  local_scheduled_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
  charge_at_utc DATETIME NOT NULL,
  status ENUM('SCHEDULED','PROCESSING_PAYMENT','PAYMENT_ACTION_REQUIRED','CONFIRMED','SKIPPED','CANCELLED','FULFILLED') NOT NULL DEFAULT 'SCHEDULED',
  expected_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_subtotal DECIMAL(12,2) NULL,
  actual_delivery_provider_cost DECIMAL(12,2) NULL,
  actual_delivery_fee DECIMAL(12,2) NULL,
  actual_delivery_margin DECIMAL(12,2) NULL,
  actual_tax DECIMAL(12,2) NULL,
  actual_total DECIMAL(12,2) NULL,
  amount_charged DECIMAL(12,2) NULL,
  payment_status ENUM('PENDING','PROCESSING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  payment_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(160) NOT NULL,
  last_error TEXT NULL,
  configuration_override_json LONGTEXT NULL,
  address_override_json LONGTEXT NULL,
  charged_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_recurring_occurrence_sequence (recurring_order_id,sequence_number),
  UNIQUE KEY uq_store_recurring_occurrence_idempotency (idempotency_key),
  KEY idx_store_recurring_occurrences_due (status,payment_status,charge_at_utc),
  CONSTRAINT fk_store_occurrence_parent FOREIGN KEY (recurring_order_id) REFERENCES store_recurring_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_store_occurrence_order FOREIGN KEY (id_store_order) REFERENCES store_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_delivery_quotes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_store_order INT NULL,
  recurring_occurrence_id BIGINT UNSIGNED NULL,
  provider VARCHAR(60) NOT NULL,
  provider_quote_id VARCHAR(255) NULL,
  provider_reference VARCHAR(255) NULL,
  provider_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  customer_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  delivery_margin DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  distance_miles DECIMAL(10,2) NULL,
  eta_minutes INT UNSIGNED NULL,
  expires_at DATETIME NULL,
  status ENUM('QUOTED','ACCEPTED','EXPIRED','CANCELLED','FAILED') NOT NULL DEFAULT 'QUOTED',
  raw_response LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_delivery_quotes_order (id_owner,site_key,id_store_order),
  KEY idx_store_delivery_quotes_occurrence (recurring_occurrence_id),
  CONSTRAINT fk_store_delivery_quote_order FOREIGN KEY (id_store_order) REFERENCES store_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_store_delivery_quote_occurrence FOREIGN KEY (recurring_occurrence_id) REFERENCES store_recurring_occurrences(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_order_items
  ADD COLUMN IF NOT EXISTS servings SMALLINT UNSIGNED NULL AFTER quantity,
  ADD COLUMN IF NOT EXISTS configuration_snapshot LONGTEXT NULL AFTER variation_options_snapshot;

ALTER TABLE store_cart_items
  ADD COLUMN IF NOT EXISTS servings SMALLINT UNSIGNED NULL AFTER quantity,
  ADD COLUMN IF NOT EXISTS configuration_snapshot LONGTEXT NULL AFTER variation_options_snapshot;

ALTER TABLE store_carts
  ADD COLUMN IF NOT EXISTS checkout_provider_customer_id VARCHAR(255) NULL AFTER recovery_token,
  ADD COLUMN IF NOT EXISTS checkout_payment_intent_id VARCHAR(255) NULL AFTER checkout_provider_customer_id;

ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS recurring_order_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS recurring_occurrence_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS delivery_provider_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS delivery_margin DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS requested_delivery_at_utc DATETIME NULL,
  ADD COLUMN IF NOT EXISTS requested_delivery_timezone VARCHAR(64) NULL,
  ADD INDEX IF NOT EXISTS idx_store_orders_recurring (recurring_order_id,recurring_occurrence_id);

SELECT 'VNV Gourmet To Go foundation ready' AS result;
