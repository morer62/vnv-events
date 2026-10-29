-- Switch the four corrected appetizer photos to new public URLs so stale CDN
-- copies of the earlier mixed-box placeholders cannot continue to surface.
-- Safe to run more than once after pulling the matching image commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';
SET @app_product := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box' LIMIT 1);
SET @app_group := (SELECT id FROM store_product_option_groups WHERE id_owner=@owner AND site_key=@site AND id_product=@app_product AND slug='appetizer-varieties' LIMIT 1);

UPDATE store_product_option_choices
SET image_url=CASE slug
    WHEN 'chicken-avocado-mini-sliders' THEN '/assets/images/gourmet-to-go/choice-slider-chicken-avocado-v2.webp'
    WHEN 'turkey-swiss-mini-sliders' THEN '/assets/images/gourmet-to-go/choice-slider-turkey-swiss-v2.webp'
    WHEN 'breakfast-brioche-sliders' THEN '/assets/images/gourmet-to-go/choice-slider-breakfast-v2.webp'
    WHEN 'yogurt-granola-parfait-cups' THEN '/assets/images/gourmet-to-go/choice-parfait-v2.webp'
    ELSE image_url
END,
updated_at=NOW()
WHERE id_group=@app_group
  AND slug IN (
    'chicken-avocado-mini-sliders',
    'turkey-swiss-mini-sliders',
    'breakfast-brioche-sliders',
    'yogurt-granola-parfait-cups'
  );
