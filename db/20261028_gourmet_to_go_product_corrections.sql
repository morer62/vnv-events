-- VNV Gourmet To Go product corrections.
-- Safe to run more than once after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

ALTER TABLE store_gourmet_settings
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
SET @p_box := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box' LIMIT 1);
SET @p_ens := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='ensaimada-family-recipe' LIMIT 1);
SET @p_brazo := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='brazo-gitano' LIMIT 1);
SET @p_prof := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='cream-profiteroles' LIMIT 1);
SET @p_tart := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='pastry-cream-tartlets' LIMIT 1);

-- Product state and customer-facing images.
UPDATE store_products
SET status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='chocolate-truffles';
UPDATE store_products
SET status='DRAFT',is_public=0,allow_immediate_payment=0,price=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='boca-abierta';
UPDATE store_products
SET main_image=CASE slug
        WHEN 'appetizer-box' THEN '/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp'
        WHEN 'cream-profiteroles' THEN '/assets/images/gourmet-to-go/gtg-profiteroles-white-box-v1.webp'
        WHEN 'pastry-cream-tartlets' THEN '/assets/images/gourmet-to-go/gtg-tartlets-white-box-v1.webp'
        ELSE main_image END,
    updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site
  AND slug IN ('appetizer-box','cream-profiteroles','pastry-cream-tartlets');

-- Keep only the approved white-box hero in the appetizer gallery. This prevents
-- the previous green/black package photos from resurfacing in thumbnails.
UPDATE store_product_media
SET image_url='/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp',
    alt_text='White VNV Gourmet To Go appetizer box with assorted savory bites',
    status='ACTIVE',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND id_product=@p_box AND slot='HERO';
UPDATE store_product_media
SET status='INACTIVE',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND id_product=@p_box AND slot<>'HERO';

-- Exact pieces and servings shown in the selector and retained in cart snapshots.
UPDATE store_product_variations v
JOIN store_products p ON p.id=v.id_product
SET v.name=CASE v.piece_count
        WHEN 30 THEN '30 pieces · serves 6'
        WHEN 45 THEN '45 pieces · serves 9'
        WHEN 60 THEN '60 pieces · serves 12'
        ELSE v.name END,
    v.guests_min=CASE v.piece_count WHEN 30 THEN 6 WHEN 45 THEN 9 WHEN 60 THEN 12 ELSE v.guests_min END,
    v.guests_max=CASE v.piece_count WHEN 30 THEN 6 WHEN 45 THEN 9 WHEN 60 THEN 12 ELSE v.guests_max END,
    v.updated_at=NOW()
WHERE p.id_owner=@owner AND p.site_key=@site AND p.slug='appetizer-box';

UPDATE store_product_variations v
JOIN store_products p ON p.id=v.id_product
SET v.name=CASE v.piece_count
        WHEN 24 THEN '24 pieces · serves 8–12'
        WHEN 48 THEN '48 pieces · serves 16–24'
        ELSE v.name END,
    v.guests_min=CASE v.piece_count WHEN 24 THEN 8 WHEN 48 THEN 16 ELSE v.guests_min END,
    v.guests_max=CASE v.piece_count WHEN 24 THEN 12 WHEN 48 THEN 24 ELSE v.guests_max END,
    v.updated_at=NOW()
WHERE p.id_owner=@owner AND p.site_key=@site AND p.slug IN ('cream-profiteroles','pastry-cream-tartlets');

UPDATE store_product_variations v
JOIN store_products p ON p.id=v.id_product
SET v.name=CASE v.size_code WHEN 'M' THEN 'Medium · serves 8–10' WHEN 'L' THEN 'Large · serves 12–15' ELSE v.name END,
    v.guests_min=CASE v.size_code WHEN 'M' THEN 8 WHEN 'L' THEN 12 ELSE v.guests_min END,
    v.guests_max=CASE v.size_code WHEN 'M' THEN 10 WHEN 'L' THEN 15 ELSE v.guests_max END,
    v.updated_at=NOW()
WHERE p.id_owner=@owner AND p.site_key=@site AND p.slug='ensaimada-family-recipe';

UPDATE store_products
SET product_type='FIXED',price=49,short_description='Light sponge rolled with pastry cream. $49 · 10–12 slices.',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='brazo-gitano';

-- Only the two public catalog categories remain attached to relaunch products.
DELETE FROM store_products_categories
WHERE id_product IN (@p_box,@p_ens,@p_brazo,@p_prof,@p_tart);
INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT @owner,@p_box,@cat_box WHERE @p_box IS NOT NULL AND @cat_box IS NOT NULL;
INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT @owner,id,@cat_dessert FROM store_products
WHERE id_owner=@owner AND site_key=@site
  AND slug IN ('ensaimada-family-recipe','brazo-gitano','cream-profiteroles','pastry-cream-tartlets')
  AND @cat_dessert IS NOT NULL;

UPDATE store_gourmet_settings
SET minimum_order_notice_hours=24,
    hero_image_url='/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp',
    dropoff_disclaimer='<strong>Free pickup in Sunrise</strong> or delivery for a fee. Order 24h ahead.',
    food_arrival_policy='Free pickup in Sunrise or delivery for a fee. Order 24h ahead.',
    updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site;
