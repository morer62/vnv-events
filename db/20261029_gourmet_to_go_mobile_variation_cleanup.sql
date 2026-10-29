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

-- Historical option rules are intentionally retained. Inactive variations are
-- excluded by the storefront and keeping the rows avoids destructive cleanup.
