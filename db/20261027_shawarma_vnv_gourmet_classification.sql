-- Classify Shawarma Station as full-service VNV Gourmet, not Gourmet To Go.
-- Also aligns the operational requirement with the real tabletop service.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE store_products
SET brand_name='VNV Gourmet',
    purchase_mode='REQUEST',
    allow_immediate_payment=0,
    allow_recurring_purchase=0,
    fulfillment_type='SERVICE',
    updated_at=NOW()
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug='shawarma-station';

UPDATE orders_services
SET requirements='Plan for a covered service area, safe load-in access, tabletop hot and cold food holding, professional catering pans, service table, serving utensils, guest-queue clearance and a weather contingency. Confirm equipment, venue rules, selections and service timing in the written proposal.'
WHERE id_owner=2
  AND is_archived=0
  AND LOWER(name) IN ('shawarma station','shawarma station (per guest)');

COMMIT;
