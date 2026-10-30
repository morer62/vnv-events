-- Add the 64-piece Dessert Box and enable the existing recurring-order flow.
-- Safe to run more than once after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';
SET @p_dessert := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box' LIMIT 1);
SET @dessert_group := (
  SELECT id FROM store_product_option_groups
  WHERE id_owner=@owner AND site_key=@site AND id_product=@p_dessert AND slug='dessert-varieties'
  ORDER BY id LIMIT 1
);

INSERT INTO store_product_variations
  (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status)
SELECT @owner,@p_dessert,'64 pieces · serves 21–32','64-pieces','XL',21,32,64,24,'GTG-DESSERT-64',115,'USD',999,1,30,'ACTIVE'
WHERE @p_dessert IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM store_product_variations WHERE id_product=@p_dessert AND slug='64-pieces');

UPDATE store_product_variations
SET name='64 pieces · serves 21–32',size_code='XL',guests_min=21,guests_max=32,
    piece_count=64,lead_hours=24,sku='GTG-DESSERT-64',price=115,currency_code='USD',
    stock_quantity=999,min_purchase_qty=1,sort_order=30,status='ACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug='64-pieces';

INSERT INTO store_product_variation_option_rules (id_group,id_product_variation,required_count,pieces_per_option)
SELECT @dessert_group,v.id,4,16
FROM store_product_variations v
WHERE v.id_product=@p_dessert AND v.slug='64-pieces'
ON DUPLICATE KEY UPDATE required_count=4,pieces_per_option=16;

UPDATE store_products
SET allow_recurring_purchase=1,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site
  AND slug IN ('appetizer-box','appetizer-box-breakfast-brunch','dessert-box','ensaimada-family-recipe','brazo-gitano')
  AND status='ACTIVE' AND is_public=1;

