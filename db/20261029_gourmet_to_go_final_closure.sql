-- Gourmet To Go final closure: canonical Dessert Box, retired Ensaimada add-on,
-- and individually audited option photography.
-- Safe to run more than once after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

-- Resolve the canonical Dessert Box by its option set. Any legacy duplicate is
-- retired before the canonical slug is reasserted. The production unique slug
-- index prevents this ambiguity from returning.
SET @p_dessert := COALESCE(
  (SELECT p.id FROM store_products p
   JOIN store_product_option_groups g ON g.id_product=p.id AND g.choice_set_key='dessert-varieties'
   WHERE p.id_owner=@owner AND p.site_key=@site
   ORDER BY (p.sku='GTG-DESSERT-BOX') DESC,p.id DESC LIMIT 1),
  (SELECT id FROM store_products
   WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box'
   ORDER BY (sku='GTG-DESSERT-BOX') DESC,id DESC LIMIT 1)
);

UPDATE store_products
SET slug=CONCAT('dessert-box-legacy-',id),status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site
  AND (slug='dessert-box' OR name='Dessert Box') AND id<>@p_dessert;

UPDATE store_products
SET name='Dessert Box',slug='dessert-box',sku='GTG-DESSERT-BOX',
    brand_name='VNV Gourmet To Go',
    short_description='Build a refrigerated dessert box with your favorite family-recipe sweets.',
    description='<p>Choose your dessert varieties. Each variety is packed in equal 12-piece blocks.</p>',
    product_type='VARIABLE',purchase_mode='DIRECT',allow_immediate_payment=1,
    fulfillment_type='DELIVERY',lead_hours=24,price=49,
    main_image='/assets/images/gourmet-to-go/gtg-dessert-box.webp',
    stock_quantity=999,min_purchase_qty=1,is_featured=1,is_public=1,status='ACTIVE',updated_at=NOW()
WHERE id=@p_dessert;

-- Remove legacy presentation data so the page can only render the 24/48-piece
-- selector and the six approved dessert varieties.
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

-- Ensaimada is no longer a product or add-on in this catalog. Preserve its
-- historical row and order references, but remove every active merchandising
-- path that could make it appear on Dessert Box or another product.
SET @p_ens_product := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='ensaimada-family-recipe' ORDER BY id DESC LIMIT 1);
SET @p_ens_box := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='ensaimada-box' ORDER BY id DESC LIMIT 1);
UPDATE store_products SET status='INACTIVE',is_public=0,updated_at=NOW()
WHERE id IN (@p_ens_product,@p_ens_box);
DELETE FROM store_product_relationships
WHERE id_product IN (@p_ens_product,@p_ens_box) OR id_related_product IN (@p_ens_product,@p_ens_box);
DELETE FROM store_product_recommendations
WHERE id_product IN (@p_ens_product,@p_ens_box)
   OR title LIKE '%Ensaimada%' OR description LIKE '%Ensaimada%'
   OR destination_url LIKE '%ensaimada%';

-- Each selector thumbnail now depicts only the named item on the approved
-- neutral background. Versioned paths also prevent stale browser/CDN images.
UPDATE store_product_option_choices c
JOIN store_product_option_groups g ON g.id=c.id_group
SET c.image_url=CASE c.slug
  WHEN 'argentine-cheese-empanaditas' THEN '/assets/images/gourmet-to-go/choice-cheese-empanaditas-v2.webp'
  WHEN 'anchovy-cream-tartlets' THEN '/assets/images/gourmet-to-go/choice-anchovy-tartlets-v2.webp'
  WHEN 'ham-croquetas' THEN '/assets/images/gourmet-to-go/choice-ham-croquetas-v2.webp'
  WHEN 'cheese-tequenos' THEN '/assets/images/gourmet-to-go/choice-tequenos-v2.webp'
  WHEN 'mini-cachitos' THEN '/assets/images/gourmet-to-go/choice-cachitos-v2.webp'
  WHEN 'mini-croissant-sandwiches' THEN '/assets/images/gourmet-to-go/choice-croissant-sandwiches-v2.webp'
  WHEN 'mini-wraps-turkey-cheese' THEN '/assets/images/gourmet-to-go/choice-mini-wraps-v2.webp'
  WHEN 'breakfast-brioche-sliders' THEN '/assets/images/gourmet-to-go/choice-slider-breakfast-v3.webp'
  WHEN 'chicken-avocado-mini-sliders' THEN '/assets/images/gourmet-to-go/choice-slider-chicken-avocado-v3.webp'
  WHEN 'turkey-swiss-mini-sliders' THEN '/assets/images/gourmet-to-go/choice-slider-turkey-swiss-v3.webp'
  WHEN 'yogurt-granola-parfait-cups' THEN '/assets/images/gourmet-to-go/choice-parfait-v3.webp'
  ELSE c.image_url END,
  c.updated_at=NOW()
WHERE g.id_owner=@owner AND g.site_key=@site AND g.choice_set_key='appetizer-varieties';

UPDATE store_product_option_choices c
JOIN store_product_option_groups g ON g.id=c.id_group
SET c.image_url=CASE c.slug
  WHEN 'cream-profiteroles' THEN '/assets/images/gourmet-to-go/choice-profiteroles-v2.webp'
  WHEN 'pastry-cream-fresh-fruit-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-fruit-v2.webp'
  WHEN 'chocolate-ganache-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-chocolate-v2.webp'
  WHEN 'dulce-de-leche-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-dulce-de-leche-v2.webp'
  WHEN 'guava-cream-cheese-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-guava-v2.webp'
  WHEN 'key-lime-tartlets' THEN '/assets/images/gourmet-to-go/choice-tartlet-key-lime-v2.webp'
  ELSE c.image_url END,
  c.updated_at=NOW()
WHERE g.id_owner=@owner AND g.site_key=@site AND g.choice_set_key='dessert-varieties';

-- One verified contact pair across product pages, checkout and messages.
UPDATE store_gourmet_settings
SET staff_phone='3052045427',
    staff_whatsapp='13053761210',
    updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site;
