-- Shared appetizer selector, Breakfast & Brunch box, and warm-pickup flag.
-- Safe to run more than once after pulling the matching code commit.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

ALTER TABLE store_product_option_groups
  ADD COLUMN IF NOT EXISTS choice_set_key VARCHAR(160) NULL AFTER slug,
  ADD COLUMN IF NOT EXISTS preferred_tag VARCHAR(40) NULL AFTER choice_set_key;
ALTER TABLE store_product_option_choices
  ADD COLUMN IF NOT EXISTS tags VARCHAR(160) NULL AFTER serve_label;
ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS pickup_warm TINYINT(1) NOT NULL DEFAULT 0 AFTER fulfillment_method;

SET @cat_box := (SELECT id FROM store_categories WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-boxes' ORDER BY id LIMIT 1);
SET @p_all := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box' LIMIT 1);
UPDATE store_products SET name='Appetizer Box — All Day',updated_at=NOW() WHERE id=@p_all;

INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,fulfillment_type,lead_hours,price,currency_code,main_image,stock_quantity,min_purchase_qty,is_featured,is_public,status)
SELECT @owner,@site,'Appetizer Box — Breakfast & Brunch','appetizer-box-breakfast-brunch','GTG-APP-BRUNCH','VNV Gourmet To Go','Build a breakfast or brunch box from our complete appetizer selection.','<p>Choose your breakfast and brunch favorites — 15 pieces of each. Baked to order by the VNV Events kitchen.</p>','VARIABLE','DIRECT',1,'DELIVERY',24,79,'USD','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp',999,1,1,1,'ACTIVE'
WHERE NOT EXISTS (SELECT 1 FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box-breakfast-brunch');
SET @p_breakfast := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box-breakfast-brunch' LIMIT 1);

INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT @owner,@p_breakfast,@cat_box WHERE @p_breakfast IS NOT NULL AND @cat_box IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM store_products_categories WHERE id_product=@p_breakfast AND id_category=@cat_box);

DELETE FROM store_product_variations WHERE id_product=@p_breakfast;
INSERT INTO store_product_variations (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status) VALUES
(@owner,@p_breakfast,'30 pieces · serves 6','small-30','S',6,6,30,24,'GTG-BRUNCH-S',79,'USD',999,1,10,'ACTIVE'),
(@owner,@p_breakfast,'45 pieces · serves 9','medium-45','M',9,9,45,24,'GTG-BRUNCH-M',115,'USD',999,1,20,'ACTIVE'),
(@owner,@p_breakfast,'60 pieces · serves 12','large-60','L',12,12,60,24,'GTG-BRUNCH-L',149,'USD',999,1,30,'ACTIVE');

SET @all_group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@p_all AND slug='appetizer-varieties' LIMIT 1);
UPDATE store_product_option_groups SET choice_set_key='appetizer-varieties',preferred_tag='all_day',name='Appetizer varieties',status='ACTIVE' WHERE id=@all_group;
INSERT INTO store_product_option_groups (id_owner,site_key,id_product,name,slug,choice_set_key,preferred_tag,option_type,required_count_default,pieces_per_option_default,status,sort_order)
VALUES (@owner,@site,@p_breakfast,'Appetizer varieties','appetizer-varieties-breakfast','appetizer-varieties','breakfast','MULTI_SELECT_EXACT',2,15,'ACTIVE',10)
ON DUPLICATE KEY UPDATE choice_set_key='appetizer-varieties',preferred_tag='breakfast',status='ACTIVE';
SET @breakfast_group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@p_breakfast AND slug='appetizer-varieties-breakfast' LIMIT 1);

UPDATE store_product_option_choices SET serve_label='OVEN 8–10 MIN',tags='all_day' WHERE id_group=@all_group AND slug='argentine-beef-empanaditas';
UPDATE store_product_option_choices SET serve_label='OVEN 8–10 MIN',tags='all_day,breakfast' WHERE id_group=@all_group AND slug IN ('argentine-cheese-empanaditas','mushroom-cream-tartlets','mini-quiche-lorraine');
UPDATE store_product_option_choices SET serve_label='OVEN 5 MIN',tags='all_day' WHERE id_group=@all_group AND slug='bacon-wrapped-dates';
UPDATE store_product_option_choices SET serve_label='READY TO SERVE',tags='all_day' WHERE id_group=@all_group AND slug IN ('caprese-skewers','antipasto-skewers');

INSERT INTO store_product_option_choices (id_group,name,slug,serve_label,tags,image_url,status,sort_order) VALUES
(@all_group,'Anchovy cream tartlets','anchovy-cream-tartlets','READY TO SERVE','all_day','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp','ACTIVE',80),
(@all_group,'Ham croquetas','ham-croquetas','OVEN 8 MIN · best picked up warm','all_day,breakfast','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp','ACTIVE',90),
(@all_group,'Cheese tequeños','cheese-tequenos','OVEN 8 MIN · best picked up warm','all_day,breakfast','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp','ACTIVE',100),
(@all_group,'Mini cachitos','mini-cachitos','OVEN 5 MIN','breakfast','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp','ACTIVE',110),
(@all_group,'Mini croissant sandwiches — ham & cheese','mini-croissant-sandwiches','READY TO SERVE','breakfast','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp','ACTIVE',120),
(@all_group,'Mini wraps — turkey & cheese','mini-wraps-turkey-cheese','READY TO SERVE','breakfast','/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp','ACTIVE',130)
ON DUPLICATE KEY UPDATE name=VALUES(name),serve_label=VALUES(serve_label),tags=VALUES(tags),image_url=VALUES(image_url),status='ACTIVE',sort_order=VALUES(sort_order);

DELETE FROM store_product_variation_option_rules WHERE id_group=@breakfast_group;
INSERT INTO store_product_variation_option_rules (id_group,id_product_variation,required_count,pieces_per_option)
SELECT @breakfast_group,id,CASE piece_count WHEN 30 THEN 2 WHEN 45 THEN 3 ELSE 4 END,15
FROM store_product_variations WHERE id_product=@p_breakfast AND status='ACTIVE';

