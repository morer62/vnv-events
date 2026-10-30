-- Appetizer boxes: four selection blocks at every size, with repeatable varieties.
-- Safe to run more than once after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

ALTER TABLE store_product_option_groups
  ADD COLUMN IF NOT EXISTS allow_choice_repeats TINYINT(1) NOT NULL DEFAULT 0 AFTER preferred_tag;

SET @p_all_day := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box' LIMIT 1);
SET @p_breakfast := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='appetizer-box-breakfast-brunch' LIMIT 1);

UPDATE store_products
SET short_description='Build your box with four selections. Repeat any favorite.',
    description='<p>Build your box with four selections. Repeat any favorite.</p>',
    updated_at=NOW()
WHERE id IN (@p_all_day,@p_breakfast);

UPDATE store_product_variations
SET name=CASE size_code
      WHEN 'S' THEN '32 pieces · serves 6'
      WHEN 'M' THEN '48 pieces · serves 9'
      WHEN 'L' THEN '64 pieces · serves 12'
      ELSE name END,
    piece_count=CASE size_code WHEN 'S' THEN 32 WHEN 'M' THEN 48 WHEN 'L' THEN 64 ELSE piece_count END,
    guests_min=CASE size_code WHEN 'S' THEN 6 WHEN 'M' THEN 9 WHEN 'L' THEN 12 ELSE guests_min END,
    guests_max=CASE size_code WHEN 'S' THEN 6 WHEN 'M' THEN 9 WHEN 'L' THEN 12 ELSE guests_max END,
    updated_at=NOW()
WHERE id_product IN (@p_all_day,@p_breakfast) AND size_code IN ('S','M','L');

UPDATE store_product_option_groups
SET allow_choice_repeats=1,
    required_count_default=4,
    pieces_per_option_default=8,
    updated_at=NOW()
WHERE id_owner=@owner AND site_key=@site AND id_product IN (@p_all_day,@p_breakfast) AND status='ACTIVE';

UPDATE store_product_variation_option_rules r
JOIN store_product_option_groups g ON g.id=r.id_group
JOIN store_product_variations v ON v.id=r.id_product_variation
SET r.required_count=4,
    r.pieces_per_option=CASE v.size_code WHEN 'S' THEN 8 WHEN 'M' THEN 12 WHEN 'L' THEN 16 ELSE r.pieces_per_option END
WHERE g.id_owner=@owner AND g.site_key=@site
  AND g.id_product IN (@p_all_day,@p_breakfast)
  AND v.size_code IN ('S','M','L');

-- Dessert Box remains a distinct-choice product.
UPDATE store_product_option_groups g
JOIN store_products p ON p.id=g.id_product
SET g.allow_choice_repeats=0,g.updated_at=NOW()
WHERE p.id_owner=@owner AND p.site_key=@site AND p.slug='dessert-box';
