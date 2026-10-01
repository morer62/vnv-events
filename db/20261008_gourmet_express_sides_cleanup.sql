-- Removes unavailable sides and fixes Garlic Bread customer pricing.
-- Idempotent. Scoped to owner 2 / vnvevents.
SET NAMES utf8mb4;
START TRANSACTION;

-- House Salad is not currently offered. Keep the row only for historical order references.
UPDATE store_products
SET status='INACTIVE',is_public=0,allow_immediate_payment=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='salad-add-on';

UPDATE store_product_variations v
JOIN store_products p ON p.id=v.id_product
SET v.status='INACTIVE',v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug='salad-add-on';

UPDATE store_product_relationships r
JOIN store_products child ON child.id=r.id_related_product
SET r.status='INACTIVE'
WHERE r.id_owner=2 AND r.site_key='vnvevents' AND child.slug='salad-add-on';

-- Dessert Box remains a public dessert, but is not suggested as an accompaniment for now.
UPDATE store_product_relationships r
JOIN store_products child ON child.id=r.id_related_product
SET r.status='INACTIVE'
WHERE r.id_owner=2 AND r.site_key='vnvevents'
  AND child.slug='dessert-box'
  AND r.relationship_type IN ('ADD_ON','OPTIONAL_SIDE','PAID_SIDE');

-- Garlic Bread has two purchasable sizes; its base price mirrors the lowest active variation.
UPDATE store_products
SET product_type='VARIABLE',price=18,short_description='Garlic bread available in 12- or 24-piece portions.',
    description='<p>Choose 12 pieces for $18 or 24 pieces for $29. Available as an optional accompaniment with eligible pasta or lasagna orders.</p>',
    status='ACTIVE',is_public=1,allow_immediate_payment=1,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='garlic-bread';

COMMIT;
SELECT 'VNV Gourmet Express sides cleanup ready' AS result;
