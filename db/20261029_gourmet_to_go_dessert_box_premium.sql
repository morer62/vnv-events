-- Premium appetizer choices and the unified VNV Gourmet To Go Dessert Box.
-- Safe to run more than once after pulling the matching code commit.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

ALTER TABLE store_product_option_choices
  ADD COLUMN IF NOT EXISTS price_modifier DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tags,
  ADD COLUMN IF NOT EXISTS serving_instructions VARCHAR(500) NULL AFTER price_modifier;

SET @cat_dessert := (SELECT id FROM store_categories WHERE id_owner=@owner AND site_key=@site AND slug='desserts' ORDER BY id LIMIT 1);
SET @p_all := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box' LIMIT 1);
SET @app_group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@p_all AND choice_set_key='appetizer-varieties' ORDER BY id LIMIT 1);

INSERT INTO store_product_option_choices
  (id_group,name,slug,serve_label,tags,price_modifier,serving_instructions,image_url,status,sort_order)
VALUES
  (@app_group,'Chicken & avocado mini sliders','chicken-avocado-mini-sliders','READY TO SERVE','all_day,premium',15.00,'Keep refrigerated. Serve chilled or at room temperature within 6 hours. No heating needed.','/assets/images/gourmet-to-go/choice-slider-chicken-avocado-v2.webp','ACTIVE',140),
  (@app_group,'Turkey & Swiss mini sliders','turkey-swiss-mini-sliders','READY TO SERVE','all_day,premium',15.00,'Keep refrigerated. Serve chilled or at room temperature within 6 hours. No heating needed.','/assets/images/gourmet-to-go/choice-slider-turkey-swiss-v2.webp','ACTIVE',150),
  (@app_group,'Breakfast brioche sliders, egg & avocado','breakfast-brioche-sliders','READY TO SERVE','breakfast,premium',15.00,'Keep refrigerated. Serve chilled or at room temperature within 6 hours. No heating needed.','/assets/images/gourmet-to-go/choice-slider-breakfast-v2.webp','ACTIVE',160),
  (@app_group,'Yogurt & granola parfait cups with fresh fruit','yogurt-granola-parfait-cups','READY TO SERVE','breakfast',0.00,'Keep refrigerated. Granola is added the day of pickup. Serve chilled.','/assets/images/gourmet-to-go/choice-parfait-v2.webp','ACTIVE',170)
ON DUPLICATE KEY UPDATE
  name=VALUES(name),serve_label=VALUES(serve_label),tags=VALUES(tags),price_modifier=VALUES(price_modifier),
  serving_instructions=VALUES(serving_instructions),image_url=VALUES(image_url),status='ACTIVE',sort_order=VALUES(sort_order);

INSERT INTO store_products
  (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_featured,is_public,status)
SELECT @owner,@site,'Dessert Box','dessert-box','GTG-DESSERT-BOX','VNV Gourmet To Go',
  'Build a refrigerated dessert box with your favorite family-recipe sweets.',
  '<p>Choose your dessert varieties. Each variety is packed in equal 12-piece blocks.</p>',
  'VARIABLE','DIRECT',1,'DELIVERY',24,49,'USD','/assets/images/gourmet-to-go/gtg-dessert-box.webp',999,1,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box');

SET @p_dessert := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box' LIMIT 1);
UPDATE store_products SET name='Dessert Box',sku='GTG-DESSERT-BOX',brand_name='VNV Gourmet To Go',
  short_description='Build a refrigerated dessert box with your favorite family-recipe sweets.',
  description='<p>Choose your dessert varieties. Each variety is packed in equal 12-piece blocks.</p>',
  product_type='VARIABLE',purchase_mode='DIRECT',allow_immediate_payment=1,fulfillment_type='DELIVERY',lead_hours=24,
  price=49,main_image='/assets/images/gourmet-to-go/gtg-dessert-box.webp',stock_quantity=999,min_purchase_qty=1,
  is_featured=1,is_public=1,status='ACTIVE',updated_at=NOW()
WHERE id=@p_dessert;

-- Keep only the two launch sizes public. Earlier draft runs created 36- and
-- 54-piece variations; leaving them active makes them reappear in the selector.
UPDATE store_product_variations
SET status='INACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug NOT IN ('24-pieces','48-pieces');

DELETE FROM store_products_categories WHERE id_product=@p_dessert;
INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT @owner,@p_dessert,@cat_dessert WHERE @p_dessert IS NOT NULL AND @cat_dessert IS NOT NULL;

INSERT INTO store_product_variations
  (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status)
SELECT @owner,@p_dessert,'24 pieces · serves 8–12','24-pieces','S',8,12,24,24,'GTG-DESSERT-24',49,'USD',999,1,10,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_product_variations WHERE id_product=@p_dessert AND slug='24-pieces');
INSERT INTO store_product_variations
  (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status)
SELECT @owner,@p_dessert,'48 pieces · serves 16–24','48-pieces','L',16,24,48,24,'GTG-DESSERT-48',89,'USD',999,1,20,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_product_variations WHERE id_product=@p_dessert AND slug='48-pieces');
UPDATE store_product_variations SET name='24 pieces · serves 8–12',size_code='S',guests_min=8,guests_max=12,piece_count=24,lead_hours=24,sku='GTG-DESSERT-24',price=49,stock_quantity=999,min_purchase_qty=1,sort_order=10,status='ACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug='24-pieces';
UPDATE store_product_variations SET name='48 pieces · serves 16–24',size_code='L',guests_min=16,guests_max=24,piece_count=48,lead_hours=24,sku='GTG-DESSERT-48',price=89,stock_quantity=999,min_purchase_qty=1,sort_order=20,status='ACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug='48-pieces';

INSERT INTO store_product_option_groups
  (id_owner,site_key,id_product,name,slug,choice_set_key,preferred_tag,option_type,required_count_default,pieces_per_option_default,status,sort_order)
VALUES (@owner,@site,@p_dessert,'Dessert varieties','dessert-varieties','dessert-varieties',NULL,'MULTI_SELECT_EXACT',2,12,'ACTIVE',10)
ON DUPLICATE KEY UPDATE name=VALUES(name),choice_set_key='dessert-varieties',preferred_tag=NULL,option_type='MULTI_SELECT_EXACT',required_count_default=2,pieces_per_option_default=12,status='ACTIVE',sort_order=10;
SET @dessert_group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@p_dessert AND slug='dessert-varieties' LIMIT 1);

INSERT INTO store_product_option_choices
  (id_group,name,slug,serve_label,tags,price_modifier,serving_instructions,image_url,status,sort_order)
VALUES
  (@dessert_group,'Cream profiteroles','cream-profiteroles','READY TO SERVE','dessert',0.00,'Keep refrigerated until serving.','/assets/images/gourmet-to-go/choice-profiteroles.webp','ACTIVE',10),
  (@dessert_group,'Pastry cream & fresh fruit tartlets','pastry-cream-fresh-fruit-tartlets','READY TO SERVE','dessert',0.00,'Keep refrigerated until serving.','/assets/images/gourmet-to-go/choice-tartlet-fruit.webp','ACTIVE',20),
  (@dessert_group,'Chocolate ganache tartlets','chocolate-ganache-tartlets','READY TO SERVE','dessert',0.00,'Keep refrigerated until serving.','/assets/images/gourmet-to-go/choice-tartlet-chocolate.webp','ACTIVE',30),
  (@dessert_group,'Dulce de leche tartlets','dulce-de-leche-tartlets','READY TO SERVE','dessert',0.00,'Keep refrigerated until serving.','/assets/images/gourmet-to-go/choice-tartlet-dulce-de-leche.webp','ACTIVE',40),
  (@dessert_group,'Guava & cream cheese tartlets','guava-cream-cheese-tartlets','READY TO SERVE','dessert',0.00,'Keep refrigerated until serving.','/assets/images/gourmet-to-go/choice-tartlet-guava.webp','ACTIVE',50),
  (@dessert_group,'Key lime tartlets','key-lime-tartlets','READY TO SERVE','dessert',0.00,'Keep refrigerated until serving.','/assets/images/gourmet-to-go/choice-tartlet-key-lime.webp','ACTIVE',60)
ON DUPLICATE KEY UPDATE
  name=VALUES(name),serve_label=VALUES(serve_label),tags=VALUES(tags),price_modifier=VALUES(price_modifier),
  serving_instructions=VALUES(serving_instructions),image_url=VALUES(image_url),status='ACTIVE',sort_order=VALUES(sort_order);

DELETE FROM store_product_variation_option_rules WHERE id_group=@dessert_group;
INSERT INTO store_product_variation_option_rules (id_group,id_product_variation,required_count,pieces_per_option)
SELECT @dessert_group,id,CASE piece_count WHEN 24 THEN 2 ELSE 4 END,12
FROM store_product_variations
WHERE id_product=@p_dessert AND status='ACTIVE' AND slug IN ('24-pieces','48-pieces');

-- Preserve historical order rows while replacing the two public product pages.
UPDATE store_products SET status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug IN ('cream-profiteroles','pastry-cream-tartlets');
