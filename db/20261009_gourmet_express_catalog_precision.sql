-- VNV Gourmet Express final catalog precision pass.
-- Idempotent. Keeps historical rows but exposes only the canonical prices and add-ons.
SET NAMES utf8mb4;
START TRANSACTION;

-- Publishable products must carry a real starting price, even when variations drive checkout.
UPDATE store_products SET price=CASE slug
 WHEN 'seafood-paella' THEN 149 WHEN 'chicken-chorizo-paella' THEN 109
 WHEN 'pasta-station-to-go' THEN 89 WHEN 'homemade-lasagna-pasticho' THEN 89
 WHEN 'latin-bites-box' THEN 99 WHEN 'signature-appetizer-box' THEN 199
 WHEN 'charcuterie-to-go' THEN 129 WHEN 'dessert-box' THEN 89
 WHEN 'thanksgiving-dinner-to-go' THEN 329 WHEN 'nochebuena-dinner' THEN 229
 WHEN 'venezuelan-christmas-dinner' THEN 299 WHEN 'holiday-dessert-box' THEN 89
 WHEN 'brunch-box' THEN 129 ELSE price END,
 allow_recurring_purchase=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug IN
('seafood-paella','chicken-chorizo-paella','pasta-station-to-go','homemade-lasagna-pasticho','latin-bites-box','signature-appetizer-box','charcuterie-to-go','dessert-box','thanksgiving-dinner-to-go','nochebuena-dinner','venezuelan-christmas-dinner','holiday-dessert-box','brunch-box');

-- Only canonical size rows remain purchasable. Historical variations stay attached to old orders.
UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product
SET v.status='INACTIVE',v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN
('seafood-paella','chicken-chorizo-paella','pasta-station-to-go','homemade-lasagna-pasticho','latin-bites-box','signature-appetizer-box','charcuterie-to-go','dessert-box','thanksgiving-dinner-to-go','nochebuena-dinner','venezuelan-christmas-dinner','holiday-dessert-box','brunch-box');

INSERT INTO store_product_variations
(id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status,created_at,updated_at)
SELECT 2,p.id,s.name,s.slug,s.sz,s.gmin,s.gmax,s.pieces,s.lead,s.sku,s.price,'USD',999,1,s.ord,'ACTIVE',NOW(),NOW()
FROM store_products p JOIN (
 SELECT 'seafood-paella' ps,'Small - Serves 6-8' name,'small' slug,'S' sz,6 gmin,8 gmax,NULL pieces,24 lead,'VNV-GE-PAE-SEA-S' sku,149.00 price,10 ord UNION ALL
 SELECT 'seafood-paella','Medium - Serves 10-12','medium','M',10,12,NULL,24,'VNV-GE-PAE-SEA-M',219,20 UNION ALL
 SELECT 'seafood-paella','Large - Serves 15-20','large','L',15,20,NULL,48,'VNV-GE-PAE-SEA-L',319,30 UNION ALL
 SELECT 'chicken-chorizo-paella','Small - Serves 6-8','small','S',6,8,NULL,24,'VNV-GE-PAE-CHK-S',109,10 UNION ALL
 SELECT 'chicken-chorizo-paella','Medium - Serves 10-12','medium','M',10,12,NULL,24,'VNV-GE-PAE-CHK-M',179,20 UNION ALL
 SELECT 'chicken-chorizo-paella','Large - Serves 15-20','large','L',15,20,NULL,48,'VNV-GE-PAE-CHK-L',239,30 UNION ALL
 SELECT 'pasta-station-to-go','Small - Serves 6-8','small','S',6,8,NULL,24,'VNV-GE-PASTA-S',89,10 UNION ALL
 SELECT 'pasta-station-to-go','Medium - Serves 10-12','medium','M',10,12,NULL,24,'VNV-GE-PASTA-M',139,20 UNION ALL
 SELECT 'pasta-station-to-go','Large - Serves 15-20','large','L',15,20,NULL,24,'VNV-GE-PASTA-L',199,30 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Small - Serves 6-8','small','S',6,8,NULL,24,'VNV-GE-LAS-S',89,10 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Medium - Serves 10-12','medium','M',10,12,NULL,24,'VNV-GE-LAS-M',139,20 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Large - Serves 15-20','large','L',15,20,NULL,24,'VNV-GE-LAS-L',199,30 UNION ALL
 SELECT 'latin-bites-box','Small - Serves 6-8 - 60 pcs','small','S',6,8,60,24,'VNV-GE-LBB-S',99,10 UNION ALL
 SELECT 'latin-bites-box','Medium - Serves 10-12 - 100 pcs','medium','M',10,12,100,24,'VNV-GE-LBB-M',159,20 UNION ALL
 SELECT 'latin-bites-box','Large - Serves 15-20 - 150 pcs','large','L',15,20,150,24,'VNV-GE-LBB-L',229,30 UNION ALL
 SELECT 'signature-appetizer-box','Medium - Serves 10-12 - 80 pcs','medium','M',10,12,80,24,'VNV-GE-SAB-M',199,20 UNION ALL
 SELECT 'signature-appetizer-box','Large - Serves 15-20 - 120 pcs','large','L',15,20,120,24,'VNV-GE-SAB-L',279,30 UNION ALL
 SELECT 'charcuterie-to-go','Small - Serves 6-8','small','S',6,8,NULL,24,'VNV-GE-CHA-S',129,10 UNION ALL
 SELECT 'charcuterie-to-go','Medium - Serves 10-12','medium','M',10,12,NULL,24,'VNV-GE-CHA-M',199,20 UNION ALL
 SELECT 'charcuterie-to-go','Large - Serves 15-20','large','L',15,20,NULL,24,'VNV-GE-CHA-L',299,30 UNION ALL
 SELECT 'dessert-box','Small - Serves 6-8 - 36 pcs','small','S',6,8,36,24,'VNV-GE-DES-S',89,10 UNION ALL
 SELECT 'dessert-box','Medium - Serves 10-12 - 54 pcs','medium','M',10,12,54,24,'VNV-GE-DES-M',129,20 UNION ALL
 SELECT 'dessert-box','Large - Serves 15-20 - 80 pcs','large','L',15,20,80,24,'VNV-GE-DES-L',179,30 UNION ALL
 SELECT 'thanksgiving-dinner-to-go','Serves 8-10','serves-8-10','M',8,10,NULL,48,'VNV-GE-TG-810',329,10 UNION ALL
 SELECT 'thanksgiving-dinner-to-go','Serves 14-16','serves-14-16','L',14,16,NULL,48,'VNV-GE-TG-1416',479,20 UNION ALL
 SELECT 'nochebuena-dinner','Serves 8-10','serves-8-10','M',8,10,NULL,48,'VNV-GE-NB-810',229,10 UNION ALL
 SELECT 'nochebuena-dinner','Serves 14-16','serves-14-16','L',14,16,NULL,48,'VNV-GE-NB-1416',349,20 UNION ALL
 SELECT 'venezuelan-christmas-dinner','Serves 8-10','serves-8-10','M',8,10,NULL,48,'VNV-GE-VC-810',299,10 UNION ALL
 SELECT 'venezuelan-christmas-dinner','Serves 14-16','serves-14-16','L',14,16,NULL,48,'VNV-GE-VC-1416',459,20 UNION ALL
 SELECT 'holiday-dessert-box','Small - Serves 6-8','small','S',6,8,NULL,48,'VNV-GE-HD-S',89,10 UNION ALL
 SELECT 'holiday-dessert-box','Medium - Serves 10-12','medium','M',10,12,NULL,48,'VNV-GE-HD-M',129,20 UNION ALL
 SELECT 'brunch-box','Small - Serves 6-8','small','S',6,8,NULL,48,'VNV-GE-BR-S',129,10 UNION ALL
 SELECT 'brunch-box','Medium - Serves 10-12','medium','M',10,12,NULL,48,'VNV-GE-BR-M',199,20
) s ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.ps
LEFT JOIN store_product_variations present ON present.id_product=p.id AND present.sku=s.sku
WHERE present.id IS NULL
ON DUPLICATE KEY UPDATE name=VALUES(name),size_code=VALUES(size_code),guests_min=VALUES(guests_min),guests_max=VALUES(guests_max),piece_count=VALUES(piece_count),lead_hours=VALUES(lead_hours),sku=VALUES(sku),price=VALUES(price),stock_quantity=999,min_purchase_qty=1,sort_order=VALUES(sort_order),status='ACTIVE',updated_at=NOW();

-- Precise add-on products and descriptions. They are visible only inside eligible main products.
UPDATE store_products SET name='Chickpea Salad',sku='VNV-GE-ADD-CHICKPEA',brand_name='VNV Gourmet Express',
 short_description='Mediterranean chickpea salad with vinaigrette packed separately.',
 description='<p>Chickpeas, cucumber, cherry tomato, red onion, feta and parsley. Vinaigrette travels in a separate sealed cup. Keep refrigerated until serving; best served the same day.</p>',
 product_type='VARIABLE',product_role='ADDON',is_addon_only=1,price=29,min_purchase_qty=1,status='ACTIVE',is_public=1,allow_immediate_payment=1,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='salad-add-on';

UPDATE store_products SET product_role='ADDON',is_addon_only=1,status='ACTIVE',is_public=1,allow_immediate_payment=1,allow_recurring_purchase=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('garlic-bread','ensaimada-box','gourmet-sauce','holiday-pie','natilla-bunuelos-tray','puerto-rican-pasteles','chafing-kit','plates-napkins','pan-de-jamon','pernil-by-the-pound');
UPDATE store_products SET name='Sauces - 8 oz',price=6,product_type='VARIABLE',short_description='Choose Alioli, Guasacaca, Chimichurri, Salsa Rosada or Mojo.' WHERE id_owner=2 AND site_key='vnvevents' AND slug='gourmet-sauce';
UPDATE store_products SET name='Pumpkin or Apple Pie - 10 inch',price=25 WHERE id_owner=2 AND site_key='vnvevents' AND slug='holiday-pie';
UPDATE store_products SET name='Pasteles - Half Dozen',price=36,min_purchase_qty=1 WHERE id_owner=2 AND site_key='vnvevents' AND slug='puerto-rican-pasteles';
UPDATE store_products SET name='Plates & Napkins Kit',price=15 WHERE id_owner=2 AND site_key='vnvevents' AND slug='plates-napkins';
UPDATE store_products SET name='Extra Pan de Jamon',price=34 WHERE id_owner=2 AND site_key='vnvevents' AND slug='pan-de-jamon';
UPDATE store_products SET price=18,min_purchase_qty=3 WHERE id_owner=2 AND site_key='vnvevents' AND slug='pernil-by-the-pound';
UPDATE store_products SET price=18,product_type='VARIABLE' WHERE id_owner=2 AND site_key='vnvevents' AND slug='garlic-bread';
UPDATE store_products SET price=34,product_type='VARIABLE' WHERE id_owner=2 AND site_key='vnvevents' AND slug='ensaimada-box';

-- Replace the retired single hallaca with the canonical half-dozen add-on.
UPDATE store_products SET name='Hallacas - Half Dozen',slug='hallacas-half-dozen',sku='VNV-GE-ADD-HALLACAS6',brand_name='VNV Gourmet Express',
 short_description='Six traditional Venezuelan hallacas.',description='<p>Six cooked hallacas, refrigerated and packed in a sealed lidded pan.</p>',
 product_type='FIXED',product_role='ADDON',is_addon_only=1,lead_hours=48,price=69,min_purchase_qty=1,status='ACTIVE',is_public=1,allow_immediate_payment=1,allow_recurring_purchase=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='extra-hallaca';

-- Deactivate duplicate/old add-on choices, then restore exactly one canonical row per option.
UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product SET v.status='INACTIVE',v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('salad-add-on','garlic-bread','ensaimada-box','gourmet-sauce','holiday-pie');
INSERT INTO store_product_variations
(id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status,created_at,updated_at)
SELECT 2,p.id,s.name,s.slug,s.sz,s.gmin,s.gmax,s.pieces,s.lead,s.sku,s.price,'USD',999,1,s.ord,'ACTIVE',NOW(),NOW()
FROM store_products p JOIN (
 SELECT 'salad-add-on' ps,'Small - Serves 6-8' name,'small' slug,'S' sz,6 gmin,8 gmax,NULL pieces,24 lead,'VNV-GE-CHICKPEA-S' sku,29.00 price,10 ord UNION ALL
 SELECT 'salad-add-on','Medium - Serves 10-12','medium','M',10,12,NULL,24,'VNV-GE-CHICKPEA-M',45,20 UNION ALL
 SELECT 'salad-add-on','Large - Serves 15-20','large','L',15,20,NULL,24,'VNV-GE-CHICKPEA-L',65,30 UNION ALL
 SELECT 'garlic-bread','12 pieces','12-pieces','S',NULL,NULL,12,24,'VNV-GE-GB-12',18,10 UNION ALL
 SELECT 'garlic-bread','24 pieces','24-pieces','M',NULL,NULL,24,24,'VNV-GE-GB-24',29,20 UNION ALL
 SELECT 'ensaimada-box','6 pieces','6-count','S',NULL,NULL,6,24,'VNV-GE-EN-6',34,10 UNION ALL
 SELECT 'ensaimada-box','12 pieces','12-count','M',NULL,NULL,12,24,'VNV-GE-EN-12',59,20 UNION ALL
 SELECT 'gourmet-sauce','Alioli - 8 oz','alioli-8oz',NULL,NULL,NULL,NULL,24,'VNV-GE-SAU-ALI',6,10 UNION ALL
 SELECT 'gourmet-sauce','Guasacaca - 8 oz','guasacaca-8oz',NULL,NULL,NULL,NULL,24,'VNV-GE-SAU-GUA',6,20 UNION ALL
 SELECT 'gourmet-sauce','Chimichurri - 8 oz','chimichurri-8oz',NULL,NULL,NULL,NULL,24,'VNV-GE-SAU-CHI',6,30 UNION ALL
 SELECT 'gourmet-sauce','Salsa Rosada - 8 oz','salsa-rosada-8oz',NULL,NULL,NULL,NULL,24,'VNV-GE-SAU-ROS',6,40 UNION ALL
 SELECT 'gourmet-sauce','Mojo - 8 oz','mojo-8oz',NULL,NULL,NULL,NULL,24,'VNV-GE-SAU-MOJ',6,50 UNION ALL
 SELECT 'holiday-pie','Pumpkin - 10 inch','pumpkin-10-inch',NULL,NULL,NULL,NULL,48,'VNV-GE-PIE-P',25,10 UNION ALL
 SELECT 'holiday-pie','Apple - 10 inch','apple-10-inch',NULL,NULL,NULL,NULL,48,'VNV-GE-PIE-A',25,20
) s ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.ps
LEFT JOIN store_product_variations present ON present.id_product=p.id AND present.sku=s.sku
WHERE present.id IS NULL
ON DUPLICATE KEY UPDATE name=VALUES(name),size_code=VALUES(size_code),guests_min=VALUES(guests_min),guests_max=VALUES(guests_max),piece_count=VALUES(piece_count),lead_hours=VALUES(lead_hours),sku=VALUES(sku),price=VALUES(price),stock_quantity=999,min_purchase_qty=1,sort_order=VALUES(sort_order),status='ACTIVE',updated_at=NOW();

-- If an earlier migration produced duplicate slugs, keep only the lowest canonical row active.
UPDATE store_product_variations v JOIN (
 SELECT id_product,slug,MAX(id) keep_id FROM store_product_variations GROUP BY id_product,slug HAVING COUNT(*)>1
) d ON d.id_product=v.id_product AND d.slug=v.slug
SET v.status=IF(v.id=d.keep_id,'ACTIVE','INACTIVE'),v.updated_at=NOW()
WHERE v.slug IN ('small','medium','large','serves-8-10','serves-14-16','12-pieces','24-pieces','6-count','12-count','alioli-8oz','guasacaca-8oz','chimichurri-8oz','salsa-rosada-8oz','mojo-8oz','pumpkin-10-inch','apple-10-inch');

-- Canonical SKUs with no same-slug predecessor must also be reactivated on every rerun.
UPDATE store_product_variations SET status='ACTIVE',updated_at=NOW() WHERE sku IN (
 'VNV-GE-PAE-SEA-S','VNV-GE-PAE-SEA-M','VNV-GE-PAE-SEA-L','VNV-GE-PAE-CHK-S','VNV-GE-PAE-CHK-M','VNV-GE-PAE-CHK-L',
 'VNV-GE-PASTA-S','VNV-GE-PASTA-M','VNV-GE-PASTA-L','VNV-GE-LAS-S','VNV-GE-LAS-M','VNV-GE-LAS-L',
 'VNV-GE-LBB-S','VNV-GE-LBB-M','VNV-GE-LBB-L','VNV-GE-SAB-M','VNV-GE-SAB-L','VNV-GE-CHA-S','VNV-GE-CHA-M','VNV-GE-CHA-L',
 'VNV-GE-DES-S','VNV-GE-DES-M','VNV-GE-DES-L','VNV-GE-TG-810','VNV-GE-TG-1416','VNV-GE-NB-810','VNV-GE-NB-1416',
 'VNV-GE-VC-810','VNV-GE-VC-1416','VNV-GE-HD-S','VNV-GE-HD-M','VNV-GE-BR-S','VNV-GE-BR-M',
 'VNV-GE-CHICKPEA-S','VNV-GE-CHICKPEA-M','VNV-GE-CHICKPEA-L','VNV-GE-GB-12','VNV-GE-GB-24','VNV-GE-EN-6','VNV-GE-EN-12',
 'VNV-GE-SAU-ALI','VNV-GE-SAU-GUA','VNV-GE-SAU-CHI','VNV-GE-SAU-ROS','VNV-GE-SAU-MOJ','VNV-GE-PIE-P','VNV-GE-PIE-A'
);

-- Correct canonical rows that already existed under the same SKU before this pass.
UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product
SET v.price=CASE
 WHEN p.slug='nochebuena-dinner' AND v.slug='serves-8-10' THEN 229
 WHEN p.slug='nochebuena-dinner' AND v.slug='serves-14-16' THEN 349
 WHEN p.slug='venezuelan-christmas-dinner' AND v.slug='serves-8-10' THEN 299
 WHEN p.slug='venezuelan-christmas-dinner' AND v.slug='serves-14-16' THEN 459
 WHEN p.slug='brunch-box' AND v.slug='small' THEN 129
 WHEN p.slug='brunch-box' AND v.slug='medium' THEN 199
 ELSE v.price END,
 v.piece_count=CASE
 WHEN p.slug='signature-appetizer-box' AND v.slug='medium' THEN 80
 WHEN p.slug='signature-appetizer-box' AND v.slug='large' THEN 120
 WHEN p.slug='dessert-box' AND v.slug='small' THEN 36
 WHEN p.slug='dessert-box' AND v.slug='medium' THEN 54
 WHEN p.slug='dessert-box' AND v.slug='large' THEN 80
 ELSE v.piece_count END,
 v.name=CASE
 WHEN p.slug='signature-appetizer-box' AND v.slug='medium' THEN 'Medium - Serves 10-12 - 80 pcs'
 WHEN p.slug='signature-appetizer-box' AND v.slug='large' THEN 'Large - Serves 15-20 - 120 pcs'
 WHEN p.slug='dessert-box' AND v.slug='small' THEN 'Small - Serves 6-8 - 36 pcs'
 WHEN p.slug='dessert-box' AND v.slug='medium' THEN 'Medium - Serves 10-12 - 54 pcs'
 WHEN p.slug='dessert-box' AND v.slug='large' THEN 'Large - Serves 15-20 - 80 pcs'
 ELSE v.name END,
 v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND v.status='ACTIVE';

-- Retire every former public add-on map, then install the approved maximum-four matrix.
UPDATE store_product_relationships r JOIN store_products p ON p.id=r.id_product
SET r.status='INACTIVE',r.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND r.relationship_type IN ('ADD_ON','OPTIONAL_SIDE','PAID_SIDE');

INSERT INTO store_product_relationships
(id_owner,site_key,id_product,id_related_product,relationship_type,included_quantity,parent_size_code,related_size_code,quantity_multiplier,size_match,sort_order,status)
SELECT 2,'vnvevents',p.id,a.id,'ADD_ON',0,NULL,NULL,1,s.match_size,s.ord,'ACTIVE'
FROM (
 SELECT 'seafood-paella' p,'salad-add-on' a,1 match_size,10 ord UNION ALL SELECT 'seafood-paella','gourmet-sauce',0,20 UNION ALL SELECT 'seafood-paella','dessert-box',1,30 UNION ALL SELECT 'seafood-paella','chafing-kit',0,40 UNION ALL
 SELECT 'chicken-chorizo-paella','salad-add-on',1,10 UNION ALL SELECT 'chicken-chorizo-paella','gourmet-sauce',0,20 UNION ALL SELECT 'chicken-chorizo-paella','dessert-box',1,30 UNION ALL SELECT 'chicken-chorizo-paella','chafing-kit',0,40 UNION ALL
 SELECT 'homemade-lasagna-pasticho','garlic-bread',0,10 UNION ALL SELECT 'homemade-lasagna-pasticho','salad-add-on',1,20 UNION ALL SELECT 'homemade-lasagna-pasticho','dessert-box',1,30 UNION ALL SELECT 'homemade-lasagna-pasticho','chafing-kit',0,40 UNION ALL
 SELECT 'pasta-station-to-go','garlic-bread',0,10 UNION ALL SELECT 'pasta-station-to-go','salad-add-on',1,20 UNION ALL SELECT 'pasta-station-to-go','dessert-box',1,30 UNION ALL SELECT 'pasta-station-to-go','chafing-kit',0,40 UNION ALL
 SELECT 'charcuterie-to-go','plates-napkins',0,10 UNION ALL SELECT 'charcuterie-to-go','latin-bites-box',1,20 UNION ALL SELECT 'charcuterie-to-go','dessert-box',1,30 UNION ALL
 SELECT 'signature-appetizer-box','gourmet-sauce',0,10 UNION ALL SELECT 'signature-appetizer-box','plates-napkins',0,20 UNION ALL SELECT 'signature-appetizer-box','ensaimada-box',0,30 UNION ALL SELECT 'signature-appetizer-box','dessert-box',1,40 UNION ALL
 SELECT 'latin-bites-box','gourmet-sauce',0,10 UNION ALL SELECT 'latin-bites-box','plates-napkins',0,20 UNION ALL SELECT 'latin-bites-box','dessert-box',1,30 UNION ALL SELECT 'latin-bites-box','charcuterie-to-go',1,40 UNION ALL
 SELECT 'dessert-box','ensaimada-box',0,10 UNION ALL SELECT 'dessert-box','plates-napkins',0,20 UNION ALL
 SELECT 'nochebuena-dinner','pernil-by-the-pound',0,10 UNION ALL SELECT 'nochebuena-dinner','puerto-rican-pasteles',0,20 UNION ALL SELECT 'nochebuena-dinner','natilla-bunuelos-tray',0,30 UNION ALL SELECT 'nochebuena-dinner','chafing-kit',0,40 UNION ALL
 SELECT 'venezuelan-christmas-dinner','hallacas-half-dozen',0,10 UNION ALL SELECT 'venezuelan-christmas-dinner','pan-de-jamon',0,20 UNION ALL SELECT 'venezuelan-christmas-dinner','natilla-bunuelos-tray',0,30 UNION ALL SELECT 'venezuelan-christmas-dinner','chafing-kit',0,40 UNION ALL
 SELECT 'thanksgiving-dinner-to-go','holiday-pie',0,10 UNION ALL SELECT 'thanksgiving-dinner-to-go','salad-add-on',1,20 UNION ALL SELECT 'thanksgiving-dinner-to-go','dessert-box',1,30 UNION ALL SELECT 'thanksgiving-dinner-to-go','chafing-kit',0,40 UNION ALL
 SELECT 'hallacas-by-the-dozen','pan-de-jamon',0,10 UNION ALL SELECT 'hallacas-by-the-dozen','pernil-by-the-pound',0,20 UNION ALL SELECT 'hallacas-by-the-dozen','gourmet-sauce',0,30 UNION ALL
 SELECT 'holiday-dessert-box','ensaimada-box',0,10 UNION ALL SELECT 'holiday-dessert-box','plates-napkins',0,20 UNION ALL SELECT 'brunch-box','ensaimada-box',0,10 UNION ALL SELECT 'brunch-box','plates-napkins',0,20
) s JOIN store_products p ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.p
JOIN store_products a ON a.id_owner=2 AND a.site_key='vnvevents' AND a.slug=s.a
ON DUPLICATE KEY UPDATE included_quantity=0,parent_size_code=NULL,related_size_code=NULL,quantity_multiplier=1,size_match=VALUES(size_match),sort_order=VALUES(sort_order),status='ACTIVE',updated_at=NOW();

-- Removed single-item/obsolete products stay retired.
UPDATE store_products SET status='INACTIVE',is_public=0,allow_immediate_payment=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('extra-dessert-add-on','thanksgiving-feast');

COMMIT;
SELECT 'VNV Gourmet Express canonical catalog and add-ons ready' AS result;
