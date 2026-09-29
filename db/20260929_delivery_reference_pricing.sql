-- VNV Gourmet To Go: quote-only delivery pricing and administrative audit.
-- Uber is a pricing reference only. This schema does not store dispatches.
SET NAMES utf8mb4;

ALTER TABLE store_gourmet_settings
  ADD COLUMN IF NOT EXISTS delivery_minimum_fee DECIMAL(10,2) NOT NULL DEFAULT 10.00 AFTER delivery_rounding_increment,
  ADD COLUMN IF NOT EXISTS delivery_max_distance_miles DECIMAL(8,2) NOT NULL DEFAULT 30.00 AFTER delivery_minimum_fee,
  ADD COLUMN IF NOT EXISTS delivery_quote_cache_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER delivery_max_distance_miles,
  ADD COLUMN IF NOT EXISTS delivery_peak_windows_json LONGTEXT NULL AFTER delivery_quote_cache_minutes,
  ADD COLUMN IF NOT EXISTS delivery_calibration_frequency VARCHAR(24) NOT NULL DEFAULT 'MANUAL' AFTER delivery_peak_windows_json;

UPDATE store_gourmet_settings
SET delivery_peak_windows_json=COALESCE(delivery_peak_windows_json, JSON_ARRAY(
  JSON_OBJECT('name','Lunch','weekdays',JSON_ARRAY(1,2,3,4,5,6,7),'start','11:30','end','13:30'),
  JSON_OBJECT('name','Dinner','weekdays',JSON_ARRAY(1,2,3,4,5,6,7),'start','17:00','end','20:00'),
  JSON_OBJECT('name','Weekend evening','weekdays',JSON_ARRAY(5,6),'start','17:00','end','22:00')
))
WHERE id_owner=2 AND site_key='vnvevents';

ALTER TABLE store_delivery_quotes
  ADD COLUMN IF NOT EXISTS pickup_name VARCHAR(160) NULL AFTER provider_reference,
  ADD COLUMN IF NOT EXISTS pickup_address VARCHAR(500) NULL AFTER pickup_name,
  ADD COLUMN IF NOT EXISTS pickup_latitude DECIMAL(10,7) NULL AFTER pickup_address,
  ADD COLUMN IF NOT EXISTS pickup_longitude DECIMAL(10,7) NULL AFTER pickup_latitude,
  ADD COLUMN IF NOT EXISTS destination_address VARCHAR(500) NULL AFTER pickup_longitude,
  ADD COLUMN IF NOT EXISTS destination_zip VARCHAR(20) NULL AFTER destination_address,
  ADD COLUMN IF NOT EXISTS destination_latitude DECIMAL(10,7) NULL AFTER destination_zip,
  ADD COLUMN IF NOT EXISTS destination_longitude DECIMAL(10,7) NULL AFTER destination_latitude,
  ADD COLUMN IF NOT EXISTS requested_delivery_at DATETIME NULL AFTER destination_longitude,
  ADD COLUMN IF NOT EXISTS pricing_source VARCHAR(32) NOT NULL DEFAULT 'DISTANCE_FALLBACK' AFTER eta_minutes,
  ADD COLUMN IF NOT EXISTS historical_peak_cost DECIMAL(12,2) NULL AFTER pricing_source,
  ADD COLUMN IF NOT EXISTS markup_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000 AFTER historical_peak_cost,
  ADD COLUMN IF NOT EXISTS rounding_increment DECIMAL(6,2) NOT NULL DEFAULT 1.00 AFTER markup_percent,
  ADD COLUMN IF NOT EXISTS minimum_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER rounding_increment,
  ADD COLUMN IF NOT EXISTS quote_context VARCHAR(32) NOT NULL DEFAULT 'CHECKOUT' AFTER minimum_fee,
  ADD COLUMN IF NOT EXISTS queried_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER quote_context,
  ADD INDEX IF NOT EXISTS idx_store_delivery_quote_cache (id_owner,site_key,destination_zip,status,queried_at),
  ADD INDEX IF NOT EXISTS idx_store_delivery_quote_history (id_owner,site_key,pricing_source,requested_delivery_at);

CREATE TABLE IF NOT EXISTS store_delivery_reference_zones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  zone_name VARCHAR(120) NOT NULL,
  representative_address VARCHAR(500) NOT NULL,
  zip_code VARCHAR(20) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  last_calibrated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_delivery_zone (id_owner,site_key,zone_name),
  KEY idx_store_delivery_zone_active (id_owner,site_key,status,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_delivery_fallback_tiers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  min_distance_miles DECIMAL(8,2) NOT NULL,
  max_distance_miles DECIMAL(8,2) NOT NULL,
  customer_fee DECIMAL(10,2) NOT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_delivery_fallback_tier (id_owner,site_key,min_distance_miles,max_distance_miles)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO store_delivery_fallback_tiers (id_owner,site_key,min_distance_miles,max_distance_miles,customer_fee)
VALUES (2,'vnvevents',0,5,10),(2,'vnvevents',5,10,14),(2,'vnvevents',10,15,18),(2,'vnvevents',15,20,22),(2,'vnvevents',20,25,26),(2,'vnvevents',25,30,30)
ON DUPLICATE KEY UPDATE customer_fee=customer_fee;

INSERT INTO store_delivery_reference_zones (id_owner,site_key,zone_name,representative_address,zip_code,sort_order)
VALUES
  (2,'vnvevents','Sunrise','10000 W Oakland Park Blvd, Sunrise, FL 33351','33351',10),
  (2,'vnvevents','Plantation','400 NW 73rd Ave, Plantation, FL 33317','33317',20),
  (2,'vnvevents','Weston','17200 Royal Palm Blvd, Weston, FL 33326','33326',30),
  (2,'vnvevents','Davie','6591 Orange Dr, Davie, FL 33314','33314',40),
  (2,'vnvevents','Fort Lauderdale','100 N Andrews Ave, Fort Lauderdale, FL 33301','33301',50),
  (2,'vnvevents','Pembroke Pines','601 City Center Way, Pembroke Pines, FL 33025','33025',60),
  (2,'vnvevents','Miramar','2300 Civic Center Pl, Miramar, FL 33025','33025',70),
  (2,'vnvevents','Hollywood','2600 Hollywood Blvd, Hollywood, FL 33020','33020',80),
  (2,'vnvevents','Aventura','19200 W Country Club Dr, Aventura, FL 33180','33180',90),
  (2,'vnvevents','North Miami','776 NE 125th St, North Miami, FL 33161','33161',100),
  (2,'vnvevents','Miami','3500 Pan American Dr, Miami, FL 33133','33133',110),
  (2,'vnvevents','Brickell','701 Brickell Ave, Miami, FL 33131','33131',120),
  (2,'vnvevents','Doral','8401 NW 53rd Ter, Doral, FL 33166','33166',130),
  (2,'vnvevents','Coral Gables','405 Biltmore Way, Coral Gables, FL 33134','33134',140),
  (2,'vnvevents','Boca Raton','201 W Palmetto Park Rd, Boca Raton, FL 33432','33432',150)
ON DUPLICATE KEY UPDATE representative_address=VALUES(representative_address),zip_code=VALUES(zip_code),sort_order=VALUES(sort_order);

CREATE TABLE IF NOT EXISTS store_delivery_fee_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_store_order INT NOT NULL,
  original_fee DECIMAL(12,2) NOT NULL,
  override_fee DECIMAL(12,2) NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  payment_state ENUM('BEFORE_PAYMENT','AFTER_PAYMENT') NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_delivery_override_order (id_owner,site_key,id_store_order,created_at),
  CONSTRAINT fk_store_delivery_override_order FOREIGN KEY (id_store_order) REFERENCES store_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_delivery_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_store_order INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('ZELLE','CASH','CHECK','MANUAL_SQUARE','MANUAL_STRIPE','OTHER') NOT NULL,
  status ENUM('PENDING','ISSUED','CANCELLED') NOT NULL DEFAULT 'ISSUED',
  refund_date DATE NOT NULL,
  reference VARCHAR(255) NULL,
  internal_note TEXT NULL,
  proof_url VARCHAR(700) NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_delivery_refund_order (id_owner,site_key,id_store_order,status),
  CONSTRAINT fk_store_delivery_refund_order FOREIGN KEY (id_store_order) REFERENCES store_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS delivery_quote_id BIGINT UNSIGNED NULL AFTER delivery_margin,
  ADD COLUMN IF NOT EXISTS delivery_pricing_source VARCHAR(32) NULL AFTER delivery_quote_id,
  ADD COLUMN IF NOT EXISTS delivery_distance_miles DECIMAL(10,2) NULL AFTER delivery_pricing_source,
  ADD COLUMN IF NOT EXISTS delivery_markup_percent DECIMAL(7,3) NULL AFTER delivery_distance_miles,
  ADD COLUMN IF NOT EXISTS delivery_reference_quoted_at DATETIME NULL AFTER delivery_markup_percent,
  ADD COLUMN IF NOT EXISTS delivery_operational_provider VARCHAR(40) NULL AFTER delivery_reference_quoted_at,
  ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(32) NOT NULL DEFAULT 'SCHEDULED' AFTER delivery_operational_provider,
  ADD COLUMN IF NOT EXISTS delivery_refund_status ENUM('NONE','PENDING','PARTIAL','REFUNDED') NOT NULL DEFAULT 'NONE' AFTER delivery_operational_provider,
  ADD COLUMN IF NOT EXISTS delivery_refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER delivery_refund_status;

ALTER TABLE store_carts
  ADD COLUMN IF NOT EXISTS delivery_quote_id BIGINT UNSIGNED NULL AFTER checkout_payment_intent_id,
  ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount,
  ADD COLUMN IF NOT EXISTS delivery_pricing_snapshot LONGTEXT NULL AFTER delivery_fee;

SELECT 'VNV delivery reference pricing ready' AS result;
