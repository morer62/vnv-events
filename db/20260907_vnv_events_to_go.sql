-- VNV Events To Go: product policies, shared catalog visibility and delivery scheduling.
-- Idempotent and scoped to owner 2 / vnvevents. It never moves or duplicates Pasta Station products.
SET NAMES utf8mb4;

ALTER TABLE store_products
  ADD COLUMN IF NOT EXISTS purchase_mode VARCHAR(24) NOT NULL DEFAULT 'REQUEST' AFTER product_type,
  ADD COLUMN IF NOT EXISTS allow_immediate_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER purchase_mode,
  ADD COLUMN IF NOT EXISTS allow_recurring_purchase TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_immediate_payment,
  ADD COLUMN IF NOT EXISTS fulfillment_type VARCHAR(24) NOT NULL DEFAULT 'SERVICE' AFTER allow_recurring_purchase;

ALTER TABLE store_orders
  ADD COLUMN IF NOT EXISTS fulfillment_method VARCHAR(24) NOT NULL DEFAULT 'DELIVERY' AFTER pricing_mode,
  ADD COLUMN IF NOT EXISTS delivery_timing VARCHAR(24) NOT NULL DEFAULT 'SCHEDULED' AFTER fulfillment_method,
  ADD COLUMN IF NOT EXISTS requested_delivery_at DATETIME NULL AFTER delivery_timing,
  ADD COLUMN IF NOT EXISTS promised_delivery_at DATETIME NULL AFTER requested_delivery_at,
  ADD INDEX IF NOT EXISTS idx_store_orders_delivery_queue (id_owner, site_key, delivery_timing, requested_delivery_at, status);

INSERT INTO store_categories (id_owner,site_key,name,slug,description,icon,meta_title,meta_description,status,created_at,updated_at)
SELECT 2,'vnvevents','Main Courses','to-go-main-courses','Chef-prepared main courses sized to share.','fa-utensils','Main Courses To Go | VNV Gourmet','Luxury main courses delivered to your table.','ACTIVE',NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM store_categories WHERE id_owner=2 AND site_key='vnvevents' AND slug='to-go-main-courses');
INSERT INTO store_categories (id_owner,site_key,name,slug,description,icon,meta_title,meta_description,status,created_at,updated_at)
SELECT 2,'vnvevents','Desserts','to-go-desserts','Desserts prepared for celebrations and gatherings.','fa-cake-candles','Desserts To Go | VNV Gourmet','Elegant desserts for celebrations and gatherings.','ACTIVE',NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM store_categories WHERE id_owner=2 AND site_key='vnvevents' AND slug='to-go-desserts');
INSERT INTO store_categories (id_owner,site_key,name,slug,description,icon,meta_title,meta_description,status,created_at,updated_at)
SELECT 2,'vnvevents','Appetizers','to-go-appetizers','Ready-to-serve appetizers for effortless hosting.','fa-plate-wheat','Appetizers To Go | VNV Gourmet','VNV Gourmet appetizers ready for your table.','ACTIVE',NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM store_categories WHERE id_owner=2 AND site_key='vnvevents' AND slug='to-go-appetizers');
INSERT INTO store_categories (id_owner,site_key,name,slug,description,icon,meta_title,meta_description,status,created_at,updated_at)
SELECT 2,'vnvevents','Seasonal','to-go-seasonal','Limited menus designed for holidays and seasonal gatherings.','fa-snowflake','Seasonal To Go | VNV Gourmet','Seasonal VNV Gourmet dishes delivered in South Florida.','ACTIVE',NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM store_categories WHERE id_owner=2 AND site_key='vnvevents' AND slug='to-go-seasonal');

-- Products are enabled individually from the product editor. Never turn on the
-- historical avomeal/The Pasta Station catalog as a bulk side effect.

INSERT INTO site_visibility
  (site_key, entity_type, entity_id, id_user_business, is_visible, visibility_status, notes, created_at, updated_at)
SELECT 'vnvevents','store_category',sc.id,2,1,'VISIBLE','VNV Events To Go category',NOW(),NOW()
FROM store_categories sc
WHERE sc.id_owner=2 AND sc.site_key='vnvevents' AND sc.slug LIKE 'to-go-%'
  AND NOT EXISTS (SELECT 1 FROM site_visibility sv WHERE sv.site_key='vnvevents' AND sv.entity_type='store_category' AND sv.entity_id=sc.id);

SELECT id,name,slug,purchase_mode,allow_immediate_payment,allow_recurring_purchase,fulfillment_type
FROM store_products WHERE id_owner=2 AND site_key='vnvevents' ORDER BY name;
