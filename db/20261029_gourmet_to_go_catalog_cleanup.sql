-- Final Gourmet To Go catalog cleanup: canonical Dessert Box, retired products,
-- approved Brazo Gitano copy, image paths and option thumbnails.
-- Safe to run more than once after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

-- Pick the product that already carries the new option selector. If an old
-- duplicate exists, retire it under a non-public legacy slug so only one row
-- can answer /product/dessert-box.
SET @p_dessert := COALESCE(
  (SELECT p.id FROM store_products p
   JOIN store_product_option_groups g ON g.id_product=p.id AND g.choice_set_key='dessert-varieties'
   WHERE p.id_owner=@owner AND p.site_key=@site AND p.slug='dessert-box'
   ORDER BY (p.sku='GTG-DESSERT-BOX') DESC,p.id DESC LIMIT 1),
  (SELECT id FROM store_products
   WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box'
   ORDER BY (sku='GTG-DESSERT-BOX') DESC,id DESC LIMIT 1)
);

UPDATE store_products
SET slug=CONCAT('dessert-box-legacy-',id),status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box' AND id<>@p_dessert;

UPDATE store_products
SET name='Dessert Box',slug='dessert-box',sku='GTG-DESSERT-BOX',brand_name='VNV Gourmet To Go',
    short_description='Build a refrigerated dessert box with your favorite family-recipe sweets.',
    description='<p>Choose your dessert varieties. Each variety is packed in equal 12-piece blocks.</p>',
    product_type='VARIABLE',purchase_mode='DIRECT',allow_immediate_payment=1,fulfillment_type='DELIVERY',
    lead_hours=24,price=49,main_image='/assets/images/gourmet-to-go/gtg-dessert-box.webp',
    stock_quantity=999,min_purchase_qty=1,is_featured=1,is_public=1,status='ACTIVE',updated_at=NOW()
WHERE id=@p_dessert;

-- The old Dessert Box profile is the source of the anonymous brownies,
-- alfajores, lemon bars and obsolete S/M/L presentation.
DELETE FROM store_product_food_profiles WHERE id_product=@p_dessert;

UPDATE store_product_variations
SET status='INACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug NOT IN ('24-pieces','48-pieces');
UPDATE store_product_variations
SET name='24 pieces · serves 8–12',size_code='S',guests_min=8,guests_max=12,piece_count=24,
    lead_hours=24,sku='GTG-DESSERT-24',price=49,sort_order=10,status='ACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug='24-pieces';
UPDATE store_product_variations
SET name='48 pieces · serves 16–24',size_code='L',guests_min=16,guests_max=24,piece_count=48,
    lead_hours=24,sku='GTG-DESSERT-48',price=89,sort_order=20,status='ACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug='48-pieces';

-- Retire Ensaimada without deleting historical order references.
SET @p_ens := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='ensaimada-family-recipe' ORDER BY id DESC LIMIT 1);
UPDATE store_products SET status='INACTIVE',is_public=0,updated_at=NOW() WHERE id=@p_ens;
DELETE FROM store_product_relationships WHERE id_product=@p_ens OR id_related_product=@p_ens;

-- Boca Abierta was only a draft and has no approved offer. Remove its draft
-- catalog footprint completely so it cannot surface in admin or sitemap data.
SET @p_boca := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='boca-abierta' ORDER BY id DESC LIMIT 1);
DELETE r FROM store_product_variation_option_rules r
JOIN store_product_variations v ON v.id=r.id_product_variation WHERE v.id_product=@p_boca;
DELETE r FROM store_product_variation_option_rules r
JOIN store_product_option_groups g ON g.id=r.id_group WHERE g.id_product=@p_boca;
DELETE c FROM store_product_option_choices c
JOIN store_product_option_groups g ON g.id=c.id_group WHERE g.id_product=@p_boca;
DELETE FROM store_product_option_groups WHERE id_product=@p_boca;
DELETE FROM store_product_media WHERE id_product=@p_boca;
DELETE FROM store_product_recommendations WHERE id_product=@p_boca;
DELETE FROM store_product_relationships WHERE id_product=@p_boca OR id_related_product=@p_boca;
DELETE FROM store_product_food_profiles WHERE id_product=@p_boca;
DELETE FROM store_products_categories WHERE id_product=@p_boca;
DELETE FROM store_product_variations WHERE id_product=@p_boca;
DELETE FROM store_products WHERE id=@p_boca;

-- Approved Brazo Gitano merchandising copy and image. Removing any legacy food
-- profile prevents a second, contradictory description block from appearing.
SET @p_brazo := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='brazo-gitano' ORDER BY id DESC LIMIT 1);
UPDATE store_products
SET short_description='Light sponge rolled with pastry cream, topped with fresh strawberries.',
    description='<p>Our family-recipe brazo gitano: light sponge rolled with pastry cream and finished with fresh strawberries. Slice and serve, no heating needed. Keep refrigerated until serving.</p>',
    product_type='FIXED',price=49,main_image='/assets/images/gourmet-to-go/gtg-brazo-gitano.webp',
    status='ACTIVE',is_public=1,updated_at=NOW()
WHERE id=@p_brazo;
DELETE FROM store_product_food_profiles WHERE id_product=@p_brazo;

-- Product hero images.
UPDATE store_products SET main_image='/assets/images/gourmet-to-go/gtg-breakfast-box.webp',updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box-breakfast-brunch';

-- Purpose-named option images are easy to replace without touching templates.
UPDATE store_product_option_choices c
JOIN store_product_option_groups g ON g.id=c.id_group
SET c.image_url=CASE c.slug
  WHEN 'ham-croquetas' THEN '/assets/images/gourmet-to-go/choice-ham-croquetas.webp'
  WHEN 'cheese-tequenos' THEN '/assets/images/gourmet-to-go/choice-tequenos.webp'
  WHEN 'mini-cachitos' THEN '/assets/images/gourmet-to-go/choice-cachitos.webp'
  WHEN 'mini-croissant-sandwiches' THEN '/assets/images/gourmet-to-go/choice-croissant-sandwiches.webp'
  WHEN 'mini-wraps-turkey-cheese' THEN '/assets/images/gourmet-to-go/choice-mini-wraps.webp'
  WHEN 'anchovy-cream-tartlets' THEN '/assets/images/gourmet-to-go/choice-anchovy-tartlets.webp'
  ELSE c.image_url END,
  c.updated_at=NOW()
WHERE g.id_owner=@owner AND g.site_key=@site AND g.choice_set_key='appetizer-varieties'
  AND c.slug IN ('ham-croquetas','cheese-tequenos','mini-cachitos','mini-croissant-sandwiches','mini-wraps-turkey-cheese','anchovy-cream-tartlets');

-- Reassert the exact dessert image paths used by the lightbox.
UPDATE store_product_option_choices c
JOIN store_product_option_groups g ON g.id=c.id_group
SET c.image_url=CASE c.slug
  WHEN 'cream-profiteroles' THEN '/assets/images/gourmet-to-go/choice-profiteroles.webp'
  WHEN 'pastry-cream-fresh-fruit-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-fruit.webp'
  WHEN 'chocolate-ganache-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-chocolate.webp'
  WHEN 'dulce-de-leche-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-dulce-de-leche.webp'
  WHEN 'guava-cream-cheese-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-guava.webp'
  WHEN 'key-lime-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-key-lime.webp'
  ELSE c.image_url END,
  c.updated_at=NOW()
WHERE g.id_owner=@owner AND g.site_key=@site AND g.choice_set_key='dessert-varieties';
