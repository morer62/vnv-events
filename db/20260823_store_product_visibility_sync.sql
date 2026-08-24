-- Repair missing public visibility rows for store products and align existing rows.
-- Safe to run more than once.

INSERT INTO site_visibility
    (site_key, entity_type, entity_id, id_user_business, is_visible, visibility_status, notes, created_at, updated_at)
SELECT
    sp.site_key,
    'store_product',
    sp.id,
    sp.id_owner,
    CASE WHEN sp.is_public = 1 AND sp.status = 'ACTIVE' THEN 1 ELSE 0 END,
    CASE WHEN sp.is_public = 1 AND sp.status = 'ACTIVE' THEN 'VISIBLE' ELSE 'HIDDEN' END,
    'Backfilled from store_products by 20260823_store_product_visibility_sync.sql.',
    NOW(),
    NOW()
FROM store_products sp
WHERE sp.site_key IS NOT NULL
  AND TRIM(sp.site_key) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM site_visibility sv
      WHERE sv.site_key = sp.site_key
        AND sv.entity_type = 'store_product'
        AND sv.entity_id = sp.id
  );

UPDATE site_visibility sv
INNER JOIN store_products sp
    ON sp.id = sv.entity_id
   AND sp.site_key = sv.site_key
SET sv.id_user_business = sp.id_owner,
    sv.is_visible = CASE WHEN sp.is_public = 1 AND sp.status = 'ACTIVE' THEN 1 ELSE 0 END,
    sv.visibility_status = CASE WHEN sp.is_public = 1 AND sp.status = 'ACTIVE' THEN 'VISIBLE' ELSE 'HIDDEN' END,
    sv.notes = 'Synchronized from store_products by 20260823_store_product_visibility_sync.sql.',
    sv.updated_at = NOW()
WHERE sv.entity_type = 'store_product';

-- Verification for the reported product.
SELECT
    sp.id,
    sp.slug,
    sp.site_key,
    sp.status,
    sp.is_public,
    sv.is_visible,
    sv.visibility_status
FROM store_products sp
LEFT JOIN site_visibility sv
    ON sv.site_key = sp.site_key
   AND sv.entity_type = 'store_product'
   AND sv.entity_id = sp.id
WHERE sp.id = 84;
