-- Remove obsolete Dessert Box draft sizes that could remain active in an
-- existing database. Safe to run more than once after pulling the matching
-- code commit.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';
SET @p_dessert := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box' LIMIT 1);

UPDATE store_product_variations
SET status='INACTIVE',updated_at=NOW()
WHERE id_product=@p_dessert AND slug NOT IN ('24-pieces','48-pieces');

SET @dessert_group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@p_dessert AND slug='dessert-varieties' LIMIT 1);
DELETE r
FROM store_product_variation_option_rules r
JOIN store_product_variations v ON v.id=r.id_product_variation
WHERE r.id_group=@dessert_group AND v.slug NOT IN ('24-pieces','48-pieces');
