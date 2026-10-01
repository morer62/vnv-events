-- VNV Gourmet Express commercial model, catalog and operational controls.
-- Idempotent. Run after 20260930_vnv_gourmet_real_catalog.sql.
SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE store_gourmet_settings
  ADD COLUMN IF NOT EXISTS pickup_minimum DECIMAL(10,2) NOT NULL DEFAULT 80.00 AFTER minimum_order_notice_hours,
  ADD COLUMN IF NOT EXISTS delivery_minimum DECIMAL(10,2) NOT NULL DEFAULT 150.00 AFTER pickup_minimum,
  ADD COLUMN IF NOT EXISTS daily_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 25 AFTER delivery_minimum,
  ADD COLUMN IF NOT EXISTS window_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 6 AFTER daily_capacity,
  ADD COLUMN IF NOT EXISTS store_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER window_capacity,
  ADD COLUMN IF NOT EXISTS pause_message VARCHAR(300) NULL AFTER store_paused,
  ADD COLUMN IF NOT EXISTS reopen_at DATETIME NULL AFTER pause_message;

ALTER TABLE store_products
  ADD COLUMN IF NOT EXISTS product_role ENUM('MAIN','ADDON','BUNDLE') NOT NULL DEFAULT 'MAIN' AFTER fulfillment_type,
  ADD COLUMN IF NOT EXISTS is_addon_only TINYINT(1) NOT NULL DEFAULT 0 AFTER product_role,
  ADD COLUMN IF NOT EXISTS lead_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER is_addon_only;

ALTER TABLE store_product_variations
  ADD COLUMN IF NOT EXISTS size_code VARCHAR(8) NULL AFTER slug,
  ADD COLUMN IF NOT EXISTS guests_min SMALLINT UNSIGNED NULL AFTER size_code,
  ADD COLUMN IF NOT EXISTS guests_max SMALLINT UNSIGNED NULL AFTER guests_min,
  ADD COLUMN IF NOT EXISTS piece_count SMALLINT UNSIGNED NULL AFTER guests_max,
  ADD COLUMN IF NOT EXISTS lead_hours SMALLINT UNSIGNED NULL AFTER piece_count;

ALTER TABLE store_product_relationships
  MODIFY relationship_type ENUM('INCLUDED_SIDE','OPTIONAL_SIDE','PAID_SIDE','ADD_ON','RELATED','BUNDLE_COMPONENT') NOT NULL,
  ADD COLUMN IF NOT EXISTS parent_size_code VARCHAR(8) NULL AFTER included_quantity,
  ADD COLUMN IF NOT EXISTS related_size_code VARCHAR(8) NULL AFTER parent_size_code,
  ADD COLUMN IF NOT EXISTS quantity_multiplier DECIMAL(8,3) NOT NULL DEFAULT 1 AFTER related_size_code,
  ADD COLUMN IF NOT EXISTS size_match TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity_multiplier;

CREATE TABLE IF NOT EXISTS store_gourmet_delivery_zones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  zone_name VARCHAR(80) NOT NULL,
  county_name VARCHAR(120) NOT NULL,
  zip_codes_json LONGTEXT NULL,
  delivery_fee DECIMAL(10,2) NOT NULL,
  free_delivery_threshold DECIMAL(10,2) NOT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gourmet_delivery_zone (id_owner,site_key,zone_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_gourmet_windows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  label VARCHAR(80) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 6,
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (id),
  UNIQUE KEY uq_gourmet_window (id_owner,site_key,start_time,end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_product_price_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  id_variation INT NULL,
  old_price DECIMAL(10,2) NOT NULL,
  new_price DECIMAL(10,2) NOT NULL,
  reason VARCHAR(300) NULL,
  changed_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_store_price_history (id_owner,site_key,id_product,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE store_gourmet_settings SET pickup_minimum=80,delivery_minimum=150,daily_capacity=25,window_capacity=6,
  minimum_order_notice_hours=24,slot_duration_minutes=120,delivery_start_time='10:00:00',delivery_end_time='18:00:00'
WHERE id_owner=2 AND site_key='vnvevents';

INSERT INTO store_gourmet_delivery_zones (id_owner,site_key,zone_name,county_name,zip_codes_json,delivery_fee,free_delivery_threshold) VALUES
  (2,'vnvevents','Broward','Broward County',JSON_ARRAY('33004','33009','33019','33020','33021','33023','33024','33025','33026','33027','33028','33029','33060','33062','33063','33064','33065','33066','33067','33068','33069','33071','33073','33076','33301','33304','33305','33306','33308','33309','33311','33312','33313','33314','33315','33316','33317','33319','33321','33322','33323','33324','33325','33326','33327','33328','33330','33331','33332','33334','33351'),25,350),
  (2,'vnvevents','Miami-Dade','Miami-Dade County',JSON_ARRAY('33010','33012','33013','33014','33015','33016','33018','33030','33031','33032','33033','33034','33035','33039','33054','33055','33056','33101','33109','33122','33125','33126','33127','33128','33129','33130','33131','33132','33133','33134','33135','33136','33137','33138','33139','33140','33141','33142','33143','33144','33145','33146','33147','33149','33150','33154','33155','33156','33157','33158','33160','33161','33162','33165','33166','33167','33168','33169','33170','33172','33173','33174','33175','33176','33177','33178','33179','33180','33181','33182','33183','33184','33185','33186','33187','33189','33190','33193','33194','33196'),45,500),
  (2,'vnvevents','Palm Beach','Palm Beach County',JSON_ARRAY('33401','33403','33404','33405','33406','33407','33408','33409','33410','33411','33412','33413','33414','33415','33417','33418','33426','33428','33431','33432','33433','33434','33435','33436','33437','33444','33445','33446','33449','33458','33460','33461','33462','33463','33467','33469','33470','33472','33473','33477','33480','33483','33484','33486','33487','33496','33498'),45,500)
ON DUPLICATE KEY UPDATE county_name=VALUES(county_name),zip_codes_json=VALUES(zip_codes_json),delivery_fee=VALUES(delivery_fee),free_delivery_threshold=VALUES(free_delivery_threshold),status='ACTIVE';

INSERT INTO store_gourmet_windows (id_owner,site_key,label,start_time,end_time,capacity,sort_order) VALUES
  (2,'vnvevents','10 AM–12 PM','10:00:00','12:00:00',6,10),(2,'vnvevents','12–2 PM','12:00:00','14:00:00',6,20),
  (2,'vnvevents','2–4 PM','14:00:00','16:00:00',6,30),(2,'vnvevents','4–6 PM','16:00:00','18:00:00',6,40)
ON DUPLICATE KEY UPDATE label=VALUES(label),capacity=VALUES(capacity),sort_order=VALUES(sort_order),status='ACTIVE';

-- Retire demo and superseded rows without deleting order history.
UPDATE store_products SET status='INACTIVE',is_public=0,allow_immediate_payment=0,allow_recurring_purchase=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND (slug LIKE 'demo-gourmet-to-go-%' OR slug IN ('extra-dessert-add-on','extra-hallaca','thanksgiving-feast'));

-- Existing commercial products.
UPDATE store_products SET brand_name='VNV Gourmet Express',product_role='MAIN',is_addon_only=0,lead_hours=24,
 allow_recurring_purchase=0,purchase_mode='DIRECT',allow_immediate_payment=1,fulfillment_type='DELIVERY',status='ACTIVE',is_public=1,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('homemade-lasagna-pasticho','latin-bites-box','signature-appetizer-box','charcuterie-to-go','chicken-chorizo-paella','seafood-paella','dessert-box');
UPDATE store_products SET lead_hours=48,allow_recurring_purchase=0 WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('thanksgiving-dinner-to-go','venezuelan-christmas-dinner','latin-holiday-dinner');
UPDATE store_products SET product_role='ADDON',is_addon_only=1,is_public=1,allow_recurring_purchase=0 WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('ensaimada-box','garlic-bread','salad-add-on','pan-de-jamon','pernil-by-the-pound');

-- Rename the holiday dinner in place so order history remains attached.
UPDATE store_products SET name='Nochebuena Dinner',slug='nochebuena-dinner',sku='VNV-GE-NOCHEBUENA',brand_name='VNV Gourmet Express',lead_hours=48,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='latin-holiday-dinner';

-- Products missing from the current catalog. New-photo rows remain DRAFT until visual approval.
INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,allow_recurring_purchase,fulfillment_type,product_role,is_addon_only,lead_hours,price,currency_code,stock_quantity,min_purchase_qty,is_featured,is_public,status,created_at,updated_at) VALUES
(2,'vnvevents','Pasta Station To Go','pasta-station-to-go','VNV-GE-PASTA','VNV Gourmet Express','Chef-prepared pasta with your choice of sauce.','<p>Choose Vodka, Alfredo or Bolognese sauce. Delivered ready to serve.</p>','VARIABLE','DIRECT',1,0,'DELIVERY','MAIN',0,24,0,'USD',999,1,1,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Fiesta for 10','fiesta-for-10','VNV-GE-B-FIESTA10','VNV Gourmet Express','Chicken paella, Latin bites and house salad for ten.','<p>A bundled Express menu with transparent savings.</p>','FIXED','DIRECT',1,0,'DELIVERY','BUNDLE',0,24,299,'USD',999,1,1,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Pasta Party for 10','pasta-party-for-10','VNV-GE-B-PASTA10','VNV Gourmet Express','Pasta, garlic bread, salad and dessert for ten.','<p>A complete pasta party bundle with transparent savings.</p>','FIXED','DIRECT',1,0,'DELIVERY','BUNDLE',0,24,269,'USD',999,1,1,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Celebration for 20','celebration-for-20','VNV-GE-B-CELEB20','VNV Gourmet Express','Seafood paella, Latin bites, salad and dessert for twenty.','<p>A large-format celebration bundle with transparent savings.</p>','FIXED','DIRECT',1,0,'DELIVERY','BUNDLE',0,48,699,'USD',999,1,1,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Hallacas by the Dozen','hallacas-by-the-dozen','VNV-GE-HALLACAS12','VNV Gourmet Express','Twelve traditional Venezuelan hallacas.','<p>Traditional hallacas prepared by the dozen for the holiday table.</p>','FIXED','DIRECT',1,0,'DELIVERY','MAIN',0,48,135,'USD',999,1,0,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Holiday Dessert Box','holiday-dessert-box','VNV-GE-HOL-DESSERT','VNV Gourmet Express','A festive assortment of holiday desserts.','<p>Seasonal desserts sized for sharing.</p>','VARIABLE','DIRECT',1,0,'DELIVERY','MAIN',0,48,0,'USD',999,1,0,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Brunch Box','brunch-box','VNV-GE-BRUNCH','VNV Gourmet Express','A polished brunch assortment ready to share.','<p>Chef-prepared brunch favorites packed for effortless hosting.</p>','VARIABLE','DIRECT',1,0,'DELIVERY','MAIN',0,48,0,'USD',999,1,0,0,'DRAFT',NOW(),NOW()),
(2,'vnvevents','Sauce','gourmet-sauce','VNV-GE-ADD-SAUCE','VNV Gourmet Express','Eight-ounce sauce add-on.','<p>Choose an extra 8 oz sauce.</p>','VARIABLE','DIRECT',1,0,'DELIVERY','ADDON',1,24,0,'USD',999,1,0,1,'ACTIVE',NOW(),NOW()),
(2,'vnvevents','Pumpkin or Apple Pie','holiday-pie','VNV-GE-ADD-PIE','VNV Gourmet Express','Ten-inch holiday pie.','<p>Choose pumpkin or apple.</p>','VARIABLE','DIRECT',1,0,'DELIVERY','ADDON',1,48,29,'USD',999,1,0,1,'ACTIVE',NOW(),NOW()),
(2,'vnvevents','Natilla & Buñuelos Tray','natilla-bunuelos-tray','VNV-GE-ADD-NATILLA','VNV Gourmet Express','Traditional natilla and buñuelos tray.','<p>A festive Colombian holiday add-on.</p>','FIXED','DIRECT',1,0,'DELIVERY','ADDON',1,48,39,'USD',999,1,0,1,'ACTIVE',NOW(),NOW()),
(2,'vnvevents','Puerto Rican Pasteles','puerto-rican-pasteles','VNV-GE-ADD-PASTELES','VNV Gourmet Express','Half dozen Puerto Rican pasteles.','<p>Six traditional pasteles.</p>','FIXED','DIRECT',1,0,'DELIVERY','ADDON',1,48,45,'USD',999,1,0,1,'ACTIVE',NOW(),NOW()),
(2,'vnvevents','Chafing Kit','chafing-kit','VNV-GE-ADD-CHAFER','VNV Gourmet Express','Disposable chafing setup.','<p>Everything needed to keep one catering tray warm.</p>','FIXED','DIRECT',1,0,'DELIVERY','ADDON',1,24,29,'USD',999,1,0,1,'ACTIVE',NOW(),NOW()),
(2,'vnvevents','Plates & Napkins','plates-napkins','VNV-GE-ADD-PLATES','VNV Gourmet Express','Disposable place settings for ten guests.','<p>Plates and napkins sold per ten guests.</p>','FIXED','DIRECT',1,0,'DELIVERY','ADDON',1,24,15,'USD',999,1,0,1,'ACTIVE',NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name),sku=VALUES(sku),brand_name=VALUES(brand_name),short_description=VALUES(short_description),description=VALUES(description),product_type=VALUES(product_type),purchase_mode='DIRECT',allow_immediate_payment=1,allow_recurring_purchase=0,fulfillment_type='DELIVERY',product_role=VALUES(product_role),is_addon_only=VALUES(is_addon_only),lead_hours=VALUES(lead_hours),price=VALUES(price),updated_at=NOW();

-- Canonical size/pricing matrix. Old variations are retained inactive for historical orders.
UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product SET v.status='INACTIVE',v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('seafood-paella','chicken-chorizo-paella','pasta-station-to-go','homemade-lasagna-pasticho','latin-bites-box','signature-appetizer-box','charcuterie-to-go','dessert-box','thanksgiving-dinner-to-go','nochebuena-dinner','venezuelan-christmas-dinner','holiday-dessert-box','brunch-box');

INSERT INTO store_product_variations (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status,created_at,updated_at)
SELECT 2,p.id,s.name,s.slug,s.size_code,s.gmin,s.gmax,s.pieces,s.lead,s.sku,s.price,'USD',999,1,s.ord,'ACTIVE',NOW(),NOW() FROM store_products p JOIN (
 SELECT 'seafood-paella' ps,'Small · Serves 6–8' name,'small' slug,'S' size_code,6 gmin,8 gmax,NULL pieces,24 lead,'VNV-GE-SP-S' sku,149 price,10 ord UNION ALL SELECT 'seafood-paella','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-SP-M',229,20 UNION ALL SELECT 'seafood-paella','Large · Serves 15–20','large','L',15,20,NULL,48,'VNV-GE-SP-L',349,30 UNION ALL
 SELECT 'chicken-chorizo-paella','Small · Serves 6–8','small','S',6,8,NULL,24,'VNV-GE-CP-S',109,10 UNION ALL SELECT 'chicken-chorizo-paella','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-CP-M',179,20 UNION ALL SELECT 'chicken-chorizo-paella','Large · Serves 15–20','large','L',15,20,NULL,48,'VNV-GE-CP-L',259,30 UNION ALL
 SELECT 'pasta-station-to-go','Small · Serves 6–8','small','S',6,8,NULL,24,'VNV-GE-PASTA-S',89,10 UNION ALL SELECT 'pasta-station-to-go','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-PASTA-M',139,20 UNION ALL SELECT 'pasta-station-to-go','Large · Serves 15–20','large','L',15,20,NULL,24,'VNV-GE-PASTA-L',199,30 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Small · Serves 6–8','small','S',6,8,NULL,24,'VNV-GE-LAS-S',89,10 UNION ALL SELECT 'homemade-lasagna-pasticho','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-LAS-M',139,20 UNION ALL SELECT 'homemade-lasagna-pasticho','Large · Serves 15–20','large','L',15,20,NULL,24,'VNV-GE-LAS-L',199,30 UNION ALL
 SELECT 'latin-bites-box','Small · 60 pieces','small','S',6,8,60,24,'VNV-GE-LB-S',99,10 UNION ALL SELECT 'latin-bites-box','Medium · 100 pieces','medium','M',10,12,100,24,'VNV-GE-LB-M',159,20 UNION ALL SELECT 'latin-bites-box','Large · 150 pieces','large','L',15,20,150,24,'VNV-GE-LB-L',229,30 UNION ALL
 SELECT 'signature-appetizer-box','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-SA-M',199,20 UNION ALL SELECT 'signature-appetizer-box','Large · Serves 15–20','large','L',15,20,NULL,24,'VNV-GE-SA-L',279,30 UNION ALL
 SELECT 'charcuterie-to-go','Small · Serves 6–8','small','S',6,8,NULL,24,'VNV-GE-CHAR-S',129,10 UNION ALL SELECT 'charcuterie-to-go','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-CHAR-M',199,20 UNION ALL SELECT 'charcuterie-to-go','Large · Serves 15–20','large','L',15,20,NULL,24,'VNV-GE-CHAR-L',299,30 UNION ALL
 SELECT 'dessert-box','Small · Serves 6–8','small','S',6,8,NULL,24,'VNV-GE-DES-S',89,10 UNION ALL SELECT 'dessert-box','Medium · Serves 10–12','medium','M',10,12,NULL,24,'VNV-GE-DES-M',129,20 UNION ALL SELECT 'dessert-box','Large · Serves 15–20','large','L',15,20,NULL,24,'VNV-GE-DES-L',179,30 UNION ALL
 SELECT 'thanksgiving-dinner-to-go','Serves 8–10','serves-8-10','M',8,10,NULL,48,'VNV-GE-TG-810',329,10 UNION ALL SELECT 'thanksgiving-dinner-to-go','Serves 14–16','serves-14-16','L',14,16,NULL,48,'VNV-GE-TG-1416',479,20 UNION ALL
 SELECT 'nochebuena-dinner','Serves 8–10','serves-8-10','M',8,10,NULL,48,'VNV-GE-NB-810',279,10 UNION ALL SELECT 'nochebuena-dinner','Serves 14–16','serves-14-16','L',14,16,NULL,48,'VNV-GE-NB-1416',419,20 UNION ALL
 SELECT 'venezuelan-christmas-dinner','Serves 8–10','serves-8-10','M',8,10,NULL,48,'VNV-GE-VC-810',329,10 UNION ALL SELECT 'venezuelan-christmas-dinner','Serves 14–16','serves-14-16','L',14,16,NULL,48,'VNV-GE-VC-1416',489,20 UNION ALL
 SELECT 'holiday-dessert-box','Serves 6–8','small','S',6,8,NULL,48,'VNV-GE-HD-S',89,10 UNION ALL SELECT 'holiday-dessert-box','Serves 10–12','medium','M',10,12,NULL,48,'VNV-GE-HD-M',129,20 UNION ALL
 SELECT 'brunch-box','Serves 6–8','small','S',6,8,NULL,48,'VNV-GE-BR-S',149,10 UNION ALL SELECT 'brunch-box','Serves 10–12','medium','M',10,12,NULL,48,'VNV-GE-BR-M',219,20
) s ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.ps
ON DUPLICATE KEY UPDATE name=VALUES(name),size_code=VALUES(size_code),guests_min=VALUES(guests_min),guests_max=VALUES(guests_max),piece_count=VALUES(piece_count),lead_hours=VALUES(lead_hours),sku=VALUES(sku),price=VALUES(price),stock_quantity=999,sort_order=VALUES(sort_order),status='ACTIVE',updated_at=NOW();

-- Add-on price matrices.
UPDATE store_products SET name='House Salad',price=0 WHERE id_owner=2 AND site_key='vnvevents' AND slug='salad-add-on';
UPDATE store_products SET price=0 WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('garlic-bread','ensaimada-box');
INSERT INTO store_product_variations (id_owner,id_product,name,slug,size_code,guests_min,guests_max,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status,created_at,updated_at)
SELECT 2,p.id,s.name,s.slug,s.size_code,s.gmin,s.gmax,s.lead,s.sku,s.price,'USD',999,1,s.ord,'ACTIVE',NOW(),NOW() FROM store_products p JOIN (
 SELECT 'salad-add-on' ps,'Small · Serves 6–8' name,'small' slug,'S' size_code,6 gmin,8 gmax,24 lead,'VNV-GE-SAL-S' sku,29 price,10 ord UNION ALL SELECT 'salad-add-on','Medium · Serves 10–12','medium','M',10,12,24,'VNV-GE-SAL-M',45,20 UNION ALL SELECT 'salad-add-on','Large · Serves 15–20','large','L',15,20,24,'VNV-GE-SAL-L',65,30 UNION ALL
 SELECT 'garlic-bread','12 pieces','12-pieces','S',NULL,NULL,24,'VNV-GE-GB-12',18,10 UNION ALL SELECT 'garlic-bread','24 pieces','24-pieces','M',NULL,NULL,24,'VNV-GE-GB-24',29,20 UNION ALL
 SELECT 'ensaimada-box','6 count','6-count','S',NULL,NULL,24,'VNV-GE-EN-6',34,10 UNION ALL SELECT 'ensaimada-box','12 count','12-count','M',NULL,NULL,24,'VNV-GE-EN-12',59,20 UNION ALL
 SELECT 'gourmet-sauce','Vodka · 8 oz','vodka-8oz',NULL,NULL,NULL,24,'VNV-GE-SAU-V',6,10 UNION ALL SELECT 'gourmet-sauce','Alfredo · 8 oz','alfredo-8oz',NULL,NULL,NULL,24,'VNV-GE-SAU-A',6,20 UNION ALL SELECT 'gourmet-sauce','Bolognese · 8 oz','bolognese-8oz',NULL,NULL,NULL,24,'VNV-GE-SAU-B',6,30 UNION ALL
 SELECT 'holiday-pie','Pumpkin · 10 inch','pumpkin-10-inch',NULL,NULL,NULL,48,'VNV-GE-PIE-P',29,10 UNION ALL SELECT 'holiday-pie','Apple · 10 inch','apple-10-inch',NULL,NULL,NULL,48,'VNV-GE-PIE-A',29,20
) s ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.ps
ON DUPLICATE KEY UPDATE name=VALUES(name),size_code=VALUES(size_code),guests_min=VALUES(guests_min),guests_max=VALUES(guests_max),lead_hours=VALUES(lead_hours),sku=VALUES(sku),price=VALUES(price),status='ACTIVE',updated_at=NOW();

-- Ensure every Express product is non-recurring.
UPDATE store_products SET allow_recurring_purchase=0 WHERE id_owner=2 AND site_key='vnvevents' AND brand_name='VNV Gourmet Express';
UPDATE store_products SET status='DRAFT',is_public=0,allow_immediate_payment=0
WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('pasta-station-to-go','fiesta-for-10','pasta-party-for-10','celebration-for-20','hallacas-by-the-dozen','holiday-dessert-box','brunch-box','gourmet-sauce','holiday-pie','natilla-bunuelos-tray','puerto-rican-pasteles','chafing-kit','plates-napkins');

INSERT INTO store_categories (id_owner,site_key,name,slug,description,status,created_at,updated_at) VALUES
(2,'vnvevents','Express Bundles','express-bundles','Complete VNV Gourmet Express menus with transparent savings.','ACTIVE',NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),status='ACTIVE',updated_at=NOW();

INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT 2,p.id,c.id FROM store_products p JOIN store_categories c ON c.id_owner=2 AND c.site_key='vnvevents' AND c.slug=CASE
 WHEN p.product_role='BUNDLE' THEN 'express-bundles'
 WHEN p.slug IN ('pasta-station-to-go') THEN 'family-meals'
 WHEN p.slug IN ('hallacas-by-the-dozen','holiday-dessert-box','brunch-box','nochebuena-dinner') THEN 'seasonal-holiday-packages'
 ELSE 'desserts-add-ons' END
LEFT JOIN store_products_categories pc ON pc.id_product=p.id AND pc.id_category=c.id
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('pasta-station-to-go','fiesta-for-10','pasta-party-for-10','celebration-for-20','hallacas-by-the-dozen','holiday-dessert-box','brunch-box','gourmet-sauce','holiday-pie','natilla-bunuelos-tray','puerto-rican-pasteles','chafing-kit','plates-napkins') AND pc.id IS NULL;

-- Eligible add-ons and bundle composition. Bundle rows use related size codes
-- so retail value and savings can be recalculated from live component prices.
INSERT INTO store_product_relationships (id_owner,site_key,id_product,id_related_product,relationship_type,included_quantity,parent_size_code,related_size_code,quantity_multiplier,size_match,sort_order,status)
SELECT 2,'vnvevents',parent.id,child.id,seed.kind,seed.qty,seed.parent_size,seed.child_size,seed.multiplier,seed.size_match,seed.ord,'ACTIVE'
FROM (
 SELECT 'fiesta-for-10' parent_slug,'chicken-chorizo-paella' child_slug,'BUNDLE_COMPONENT' kind,1 qty,NULL parent_size,'M' child_size,1 multiplier,0 size_match,10 ord UNION ALL
 SELECT 'fiesta-for-10','latin-bites-box','BUNDLE_COMPONENT',1,NULL,'S',1,0,20 UNION ALL SELECT 'fiesta-for-10','salad-add-on','BUNDLE_COMPONENT',1,NULL,'M',1,0,30 UNION ALL
 SELECT 'pasta-party-for-10','pasta-station-to-go','BUNDLE_COMPONENT',1,NULL,'M',1,0,10 UNION ALL SELECT 'pasta-party-for-10','garlic-bread','BUNDLE_COMPONENT',1,NULL,'M',1,0,20 UNION ALL SELECT 'pasta-party-for-10','salad-add-on','BUNDLE_COMPONENT',1,NULL,'M',1,0,30 UNION ALL SELECT 'pasta-party-for-10','dessert-box','BUNDLE_COMPONENT',1,NULL,'S',1,0,40 UNION ALL
 SELECT 'celebration-for-20','seafood-paella','BUNDLE_COMPONENT',1,NULL,'L',1,0,10 UNION ALL SELECT 'celebration-for-20','latin-bites-box','BUNDLE_COMPONENT',1,NULL,'L',1,0,20 UNION ALL SELECT 'celebration-for-20','salad-add-on','BUNDLE_COMPONENT',1,NULL,'L',1,0,30 UNION ALL SELECT 'celebration-for-20','dessert-box','BUNDLE_COMPONENT',1,NULL,'L',1,0,40 UNION ALL
 SELECT 'seafood-paella','salad-add-on','ADD_ON',0,NULL,NULL,1,1,10 UNION ALL SELECT 'seafood-paella','dessert-box','ADD_ON',0,NULL,NULL,1,1,20 UNION ALL SELECT 'seafood-paella','chafing-kit','ADD_ON',0,NULL,NULL,1,0,30 UNION ALL SELECT 'seafood-paella','plates-napkins','ADD_ON',0,NULL,NULL,1,0,40 UNION ALL
 SELECT 'chicken-chorizo-paella','salad-add-on','ADD_ON',0,NULL,NULL,1,1,10 UNION ALL SELECT 'chicken-chorizo-paella','dessert-box','ADD_ON',0,NULL,NULL,1,1,20 UNION ALL SELECT 'pasta-station-to-go','garlic-bread','ADD_ON',0,NULL,NULL,1,1,10 UNION ALL SELECT 'pasta-station-to-go','gourmet-sauce','ADD_ON',0,NULL,NULL,1,0,20 UNION ALL SELECT 'homemade-lasagna-pasticho','garlic-bread','ADD_ON',0,NULL,NULL,1,1,10 UNION ALL SELECT 'homemade-lasagna-pasticho','salad-add-on','ADD_ON',0,NULL,NULL,1,1,20 UNION ALL
 SELECT 'thanksgiving-dinner-to-go','holiday-pie','ADD_ON',0,NULL,NULL,1,0,10 UNION ALL SELECT 'nochebuena-dinner','pan-de-jamon','ADD_ON',0,NULL,NULL,1,0,10 UNION ALL SELECT 'nochebuena-dinner','natilla-bunuelos-tray','ADD_ON',0,NULL,NULL,1,0,20 UNION ALL SELECT 'venezuelan-christmas-dinner','hallacas-by-the-dozen','ADD_ON',0,NULL,NULL,1,0,10
) seed JOIN store_products parent ON parent.id_owner=2 AND parent.site_key='vnvevents' AND parent.slug=seed.parent_slug
JOIN store_products child ON child.id_owner=2 AND child.site_key='vnvevents' AND child.slug=seed.child_slug
ON DUPLICATE KEY UPDATE included_quantity=VALUES(included_quantity),parent_size_code=VALUES(parent_size_code),related_size_code=VALUES(related_size_code),quantity_multiplier=VALUES(quantity_multiplier),size_match=VALUES(size_match),sort_order=VALUES(sort_order),status='ACTIVE';

COMMIT;
SELECT 'VNV Gourmet Express master schema and catalog ready' AS result;
