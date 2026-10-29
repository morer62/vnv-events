-- VNV Gourmet To Go relaunch: compact catalog, exact-count option groups and pickup defaults.
-- Safe to run more than once. Production: run after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

CREATE TABLE IF NOT EXISTS store_product_option_groups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  option_type VARCHAR(40) NOT NULL DEFAULT 'MULTI_SELECT_EXACT',
  required_count_default SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pieces_per_option_default SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_product_option_group (id_owner,site_key,id_product,slug), KEY idx_option_product (id_product,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_product_option_choices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_group INT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  serve_label VARCHAR(80) DEFAULT NULL,
  image_url VARCHAR(255) DEFAULT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_group_choice (id_group,slug), KEY idx_choice_group (id_group,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_product_variation_option_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_group INT UNSIGNED NOT NULL,
  id_product_variation INT NOT NULL,
  required_count SMALLINT UNSIGNED NOT NULL,
  pieces_per_option SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_group_variation (id_group,id_product_variation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_product_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_owner INT NOT NULL,
  site_key VARCHAR(80) NOT NULL,
  id_product INT NOT NULL,
  slot ENUM('HERO','IN_THE_BOX','SCALE_CONTEXT','OG') NOT NULL,
  image_url VARCHAR(900) NOT NULL,
  alt_text VARCHAR(255) NULL,
  focal_x DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  focal_y DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  width_px INT UNSIGNED NULL,
  height_px INT UNSIGNED NULL,
  is_real_photo TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_product_media_slot (id_owner,site_key,id_product,slot),
  KEY idx_store_product_media_product (id_product,status,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_gourmet_settings
  ADD COLUMN IF NOT EXISTS hero_image_url VARCHAR(900) NULL,
  ADD COLUMN IF NOT EXISTS dropoff_disclaimer TEXT NULL,
  ADD COLUMN IF NOT EXISTS food_arrival_policy TEXT NULL;

INSERT INTO store_categories (id_owner,site_key,name,slug,description,status)
SELECT @owner,@site,'Appetizer Boxes','appetizer-boxes','Build-your-own appetizer boxes for pickup or delivery.','ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_categories WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-boxes');
INSERT INTO store_categories (id_owner,site_key,name,slug,description,status)
SELECT @owner,@site,'Desserts','desserts','Family-recipe desserts and small sweet bites.','ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_categories WHERE id_owner=@owner AND site_key=@site AND slug='desserts');
SET @cat_box := (SELECT id FROM store_categories WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-boxes' ORDER BY id LIMIT 1);
SET @cat_dessert := (SELECT id FROM store_categories WHERE id_owner=@owner AND site_key=@site AND slug='desserts' ORDER BY id LIMIT 1);

-- Retire the previous dinner-rescue catalog without deleting historical products.
UPDATE store_products SET status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND brand_name IN ('VNV Gourmet Express','VNV Gourmet To Go');

INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_featured,is_public,status)
SELECT @owner,@site,'Appetizer Box','appetizer-box','GTG-APP-BOX','VNV Gourmet To Go','Choose your bites — 15 pieces of each. Baked to order by the VNV Events kitchen.','<p>Choose your bites — 15 pieces of each. Baked to order by the VNV Events kitchen.</p>','VARIABLE','DIRECT',1,'DELIVERY',24,79,'USD','/assets/images/gourmet-to-go/gtg-box-hero.webp',999,1,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box');

INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_public,status)
SELECT @owner,@site,'Ensaimada','ensaimada-family-recipe','GTG-ENSAIMADA','VNV Gourmet To Go','Our family-recipe ensaimada: soft, buttery spiral pastry finished with powdered sugar. Made to slice at the table.','<p>Our family-recipe ensaimada: soft, buttery spiral pastry finished with powdered sugar. Made to slice at the table.</p>','VARIABLE','DIRECT',1,'DELIVERY',24,55,'USD','/assets/images/gourmet-to-go/gtg-ensaimada.webp',999,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='ensaimada-family-recipe');
INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_public,status)
SELECT @owner,@site,'Brazo Gitano','brazo-gitano','GTG-BRAZO','VNV Gourmet To Go','Light sponge rolled with pastry cream. Slices into 10–12 pieces.','<p>Light sponge rolled with pastry cream. Slices into 10–12 pieces.</p>','FIXED','DIRECT',1,'DELIVERY',24,49,'USD','/assets/images/gourmet-to-go/gtg-brazo-gitano.webp',999,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='brazo-gitano');
INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_public,status)
SELECT @owner,@site,'Cream Profiteroles','cream-profiteroles','GTG-PROFITEROLES','VNV Gourmet To Go','Choux puffs filled with pastry cream.','<p>Choux puffs filled with pastry cream.</p>','VARIABLE','DIRECT',1,'DELIVERY',24,49,'USD','/assets/images/gourmet-to-go/gtg-profiteroles.webp',999,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='cream-profiteroles');
INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_public,status)
SELECT @owner,@site,'Pastry Cream Tartlets','pastry-cream-tartlets','GTG-TARTLETS','VNV Gourmet To Go','Crisp tartlet shells filled with silky pastry cream.','<p>Crisp tartlet shells filled with silky pastry cream.</p>','VARIABLE','DIRECT',1,'DELIVERY',24,49,'USD','/assets/images/gourmet-to-go/gtg-tartlets.webp',999,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='pastry-cream-tartlets');
INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_public,status)
SELECT @owner,@site,'Chocolate Truffles','chocolate-truffles','GTG-TRUFFLES','VNV Gourmet To Go','Hand-rolled chocolate truffles made for sharing at the dessert table.','<p>Hand-rolled chocolate truffles made for sharing at the dessert table.</p>','VARIABLE','DIRECT',1,'DELIVERY',24,49,'USD','/assets/images/gourmet-to-go/gtg-chocolate-truffles.webp',999,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='chocolate-truffles');
INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,stock_quantity,min_purchase_qty,is_public,status)
SELECT @owner,@site,'Boca Abierta','boca-abierta','GTG-BOCA','VNV Gourmet To Go','Family-recipe Boca Abierta. Format and price are being finalized.','<p>Family-recipe Boca Abierta. Format and price are being finalized.</p>','FIXED','DIRECT',0,'DELIVERY',24,0,'USD',0,1,0,'DRAFT'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='boca-abierta');

-- Restore/update the relaunch records on repeat runs.
UPDATE store_products SET brand_name='VNV Gourmet To Go',purchase_mode='DIRECT',fulfillment_type='DELIVERY',lead_hours=24,is_public=1,status='ACTIVE',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug IN ('appetizer-box','ensaimada-family-recipe','brazo-gitano','cream-profiteroles','pastry-cream-tartlets','chocolate-truffles');

UPDATE store_products SET main_image=CASE slug
 WHEN 'appetizer-box' THEN '/assets/images/gourmet-to-go/gtg-box-hero.webp'
 WHEN 'ensaimada-family-recipe' THEN '/assets/images/gourmet-to-go/gtg-ensaimada.webp'
 WHEN 'brazo-gitano' THEN '/assets/images/gourmet-to-go/gtg-brazo-gitano.webp'
 WHEN 'cream-profiteroles' THEN '/assets/images/gourmet-to-go/gtg-profiteroles.webp'
 WHEN 'pastry-cream-tartlets' THEN '/assets/images/gourmet-to-go/gtg-tartlets.webp'
 WHEN 'chocolate-truffles' THEN '/assets/images/gourmet-to-go/gtg-chocolate-truffles.webp'
 ELSE main_image END,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug IN ('appetizer-box','ensaimada-family-recipe','brazo-gitano','cream-profiteroles','pastry-cream-tartlets','chocolate-truffles');

SET @p_box := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box' LIMIT 1);
SET @p_ens := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='ensaimada-family-recipe' LIMIT 1);
SET @p_brazo := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='brazo-gitano' LIMIT 1);
SET @p_prof := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='cream-profiteroles' LIMIT 1);
SET @p_tart := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='pastry-cream-tartlets' LIMIT 1);
SET @p_truf := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='chocolate-truffles' LIMIT 1);

INSERT INTO store_product_media (id_owner,site_key,id_product,slot,image_url,alt_text,width_px,height_px,is_real_photo,status,sort_order) VALUES
(@owner,@site,@p_box,'HERO','/assets/images/gourmet-to-go/gtg-box-hero.webp','VNV Gourmet To Go appetizer box assortment',1600,2000,0,'ACTIVE',10),
(@owner,@site,@p_box,'IN_THE_BOX','/assets/images/gourmet-to-go/gtg-box-30.webp','Small 30-piece appetizer box',1200,1500,0,'ACTIVE',20),
(@owner,@site,@p_box,'SCALE_CONTEXT','/assets/images/gourmet-to-go/gtg-box-45.webp','Medium 45-piece appetizer box',1200,1500,0,'ACTIVE',30),
(@owner,@site,@p_box,'OG','/assets/images/gourmet-to-go/gtg-box-60.webp','Large 60-piece appetizer box',1600,2000,0,'ACTIVE',40)
ON DUPLICATE KEY UPDATE image_url=VALUES(image_url),alt_text=VALUES(alt_text),width_px=VALUES(width_px),height_px=VALUES(height_px),is_real_photo=0,status='ACTIVE',sort_order=VALUES(sort_order);

DELETE FROM store_products_categories WHERE id_product IN (@p_box,@p_ens,@p_brazo,@p_prof,@p_tart,@p_truf);
INSERT INTO store_products_categories (id_owner,id_product,id_category) VALUES
(@owner,@p_box,@cat_box),(@owner,@p_ens,@cat_dessert),(@owner,@p_brazo,@cat_dessert),(@owner,@p_prof,@cat_dessert),(@owner,@p_tart,@cat_dessert),(@owner,@p_truf,@cat_dessert);

DELETE FROM store_product_variations WHERE id_product IN (@p_box,@p_ens,@p_prof,@p_tart,@p_truf);
INSERT INTO store_product_variations (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status) VALUES
(@owner,@p_box,'Small · 30 pieces','small-30','S',6,6,30,24,'GTG-APP-S',79,'USD',999,1,10,'ACTIVE'),
(@owner,@p_box,'Medium · 45 pieces','medium-45','M',9,9,45,24,'GTG-APP-M',115,'USD',999,1,20,'ACTIVE'),
(@owner,@p_box,'Large · 60 pieces','large-60','L',12,12,60,24,'GTG-APP-L',149,'USD',999,1,30,'ACTIVE'),
(@owner,@p_ens,'Medium · serves 8–10','medium','M',8,10,NULL,24,'GTG-ENS-M',55,'USD',999,1,10,'ACTIVE'),
(@owner,@p_ens,'Large · serves 12–15','large','L',12,15,NULL,24,'GTG-ENS-L',75,'USD',999,1,20,'ACTIVE'),
(@owner,@p_prof,'24 pieces','24-pieces','S',NULL,NULL,24,24,'GTG-PROF-24',49,'USD',999,1,10,'ACTIVE'),
(@owner,@p_prof,'48 pieces','48-pieces','L',NULL,NULL,48,24,'GTG-PROF-48',89,'USD',999,1,20,'ACTIVE'),
(@owner,@p_tart,'24 pieces','24-pieces','S',NULL,NULL,24,24,'GTG-TART-24',49,'USD',999,1,10,'ACTIVE'),
(@owner,@p_tart,'48 pieces','48-pieces','L',NULL,NULL,48,24,'GTG-TART-48',89,'USD',999,1,20,'ACTIVE'),
(@owner,@p_truf,'24 pieces','24-pieces','S',NULL,NULL,24,24,'GTG-TRUF-24',49,'USD',999,1,10,'ACTIVE'),
(@owner,@p_truf,'48 pieces','48-pieces','L',NULL,NULL,48,24,'GTG-TRUF-48',89,'USD',999,1,20,'ACTIVE');

INSERT INTO store_product_option_groups (id_owner,site_key,id_product,name,slug,option_type,required_count_default,pieces_per_option_default,status)
VALUES (@owner,@site,@p_box,'Appetizer varieties','appetizer-varieties','MULTI_SELECT_EXACT',2,15,'ACTIVE')
ON DUPLICATE KEY UPDATE name=VALUES(name),option_type=VALUES(option_type),status='ACTIVE';
SET @group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@p_box AND slug='appetizer-varieties' LIMIT 1);
DELETE FROM store_product_option_choices WHERE id_group=@group;
INSERT INTO store_product_option_choices (id_group,name,slug,serve_label,image_url,sort_order) VALUES
(@group,'Argentine beef empanaditas','argentine-beef-empanaditas','OVEN 10 MIN','/assets/images/gourmet-to-go/choice-beef-empanaditas.webp',10),
(@group,'Argentine cheese empanaditas','argentine-cheese-empanaditas','OVEN 10 MIN','/assets/images/gourmet-to-go/choice-cheese-empanaditas.webp',20),
(@group,'Mushroom cream tartlets','mushroom-cream-tartlets','OVEN 10 MIN','/assets/images/gourmet-to-go/choice-mushroom-tartlets.webp',30),
(@group,'Mini quiche Lorraine','mini-quiche-lorraine','OVEN 10 MIN','/assets/images/gourmet-to-go/choice-quiche-lorraine.webp',40),
(@group,'Bacon-wrapped dates with goat cheese','bacon-wrapped-dates','OVEN 5 MIN','/assets/images/gourmet-to-go/choice-bacon-dates.webp',50),
(@group,'Caprese skewers','caprese-skewers','READY TO SERVE','/assets/images/gourmet-to-go/choice-caprese.webp',60),
(@group,'Antipasto skewers','antipasto-skewers','READY TO SERVE','/assets/images/gourmet-to-go/choice-antipasto.webp',70);
DELETE FROM store_product_variation_option_rules WHERE id_group=@group;
INSERT INTO store_product_variation_option_rules (id_group,id_product_variation,required_count,pieces_per_option)
SELECT @group,id,CASE size_code WHEN 'S' THEN 2 WHEN 'M' THEN 3 ELSE 4 END,15 FROM store_product_variations WHERE id_product=@p_box AND status='ACTIVE';

UPDATE store_gourmet_settings SET minimum_order_notice_hours=24,pickup_minimum=65,delivery_minimum=65,
 pickup_name='VNV Events',pickup_address_1='10258 NW 47th St',pickup_city='Sunrise',pickup_state='FL',pickup_zip='33351',pickup_country='US',hero_image_url='/assets/images/gourmet-to-go/gtg-box-hero.webp',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site;

-- Final corrections shared with the production follow-up migration.
UPDATE store_products SET status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='chocolate-truffles';
UPDATE store_products SET status='DRAFT',is_public=0,allow_immediate_payment=0,price=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='boca-abierta';
UPDATE store_products SET main_image='/assets/images/gourmet-to-go/gtg-dessert-assortment-black-box-v2.webp',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug IN ('cream-profiteroles','pastry-cream-tartlets');
UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product
SET v.name=CASE v.piece_count WHEN 24 THEN '24 pieces · serves 8–12' WHEN 48 THEN '48 pieces · serves 16–24' ELSE v.name END,
    v.guests_min=CASE v.piece_count WHEN 24 THEN 8 WHEN 48 THEN 16 ELSE v.guests_min END,
    v.guests_max=CASE v.piece_count WHEN 24 THEN 12 WHEN 48 THEN 24 ELSE v.guests_max END,
    v.updated_at=NOW()
WHERE p.id_owner=@owner AND p.site_key=@site AND p.slug IN ('cream-profiteroles','pastry-cream-tartlets');
UPDATE store_gourmet_settings
SET dropoff_disclaimer='<strong>Free pickup in Sunrise</strong> or delivery for a fee. Order 24h ahead.',
    food_arrival_policy='Free pickup in Sunrise or delivery for a fee. Order 24h ahead.',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site;
