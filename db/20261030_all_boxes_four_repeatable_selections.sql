-- All VNV Gourmet To Go boxes use four repeatable selection blocks.
-- Safe to run more than once after pulling the matching code commit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @owner := COALESCE((SELECT id_owner FROM store_gourmet_settings WHERE site_key='vnvevents' ORDER BY id LIMIT 1), 2);
SET @site := 'vnvevents';

ALTER TABLE store_product_option_groups
  ADD COLUMN IF NOT EXISTS allow_choice_repeats TINYINT(1) NOT NULL DEFAULT 0 AFTER preferred_tag;

SET @p_dessert := (SELECT id FROM store_products WHERE id_owner=@owner AND site_key=@site AND slug='dessert-box' LIMIT 1);
SET @dessert_group := (
  SELECT id
  FROM store_product_option_groups
  WHERE id_owner=@owner AND site_key=@site AND id_product=@p_dessert AND slug='dessert-varieties'
  ORDER BY id LIMIT 1
);

UPDATE store_products
SET short_description='Build your refrigerated dessert box with four selections. Repeat any favorite.',
    description='<p>Build your refrigerated dessert box with four selections. Repeat any favorite.</p>',
    updated_at=NOW()
WHERE id=@p_dessert;

UPDATE store_product_option_groups
SET allow_choice_repeats=1,
    required_count_default=4,
    pieces_per_option_default=6,
    updated_at=NOW()
WHERE id=@dessert_group;

UPDATE store_product_variation_option_rules r
JOIN store_product_variations v ON v.id=r.id_product_variation
SET r.required_count=4,
    r.pieces_per_option=CASE v.piece_count
      WHEN 24 THEN 6
      WHEN 48 THEN 12
      ELSE r.pieces_per_option
    END
WHERE r.id_group=@dessert_group
  AND v.id_product=@p_dessert
  AND v.status='ACTIVE'
  AND v.piece_count IN (24,48);

