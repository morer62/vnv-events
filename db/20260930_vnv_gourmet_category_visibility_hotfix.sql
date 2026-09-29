-- Makes the five VNV Gourmet To Go launch categories publicly accessible.
-- Safe to run after db/20260930_vnv_gourmet_real_catalog.sql and safe to repeat.
SET NAMES utf8mb4;

INSERT INTO site_visibility
  (site_key,entity_type,entity_id,id_user_business,is_visible,visibility_status,notes,created_at,updated_at)
SELECT
  'vnvevents','store_category',c.id,2,1,'VISIBLE',
  'VNV Gourmet To Go launch category.',NOW(),NOW()
FROM store_categories c
WHERE c.id_owner=2
  AND c.site_key='vnvevents'
  AND c.slug IN (
    'family-meals',
    'party-boxes',
    'party-trays',
    'desserts-add-ons',
    'seasonal-holiday-packages'
  )
ON DUPLICATE KEY UPDATE
  id_user_business=2,
  is_visible=1,
  visibility_status='VISIBLE',
  notes=VALUES(notes),
  updated_at=NOW();

SELECT 'VNV Gourmet To Go category visibility ready' AS result;
