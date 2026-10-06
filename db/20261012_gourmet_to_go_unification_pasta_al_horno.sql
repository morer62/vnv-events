-- VNV Gourmet To Go: catalog unification and Pasta al Horno.
-- Idempotent; run after 20261011_gourmet_to_go_storefront_v2.sql.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE store_categories SET name='Family Occasion Meals',description='Family-style trays prepared for gatherings.',status='ACTIVE',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('family-meals','party-trays');
UPDATE store_categories SET name='Party Boxes',description='Party-ready boxes and platters for sharing.',status='ACTIVE',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='party-boxes';
UPDATE store_categories SET name='Desserts',description='Desserts and sweet boxes for sharing.',status='ACTIVE',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='desserts-add-ons';
UPDATE store_categories SET name='Seasonal',description='Seasonal menus for holiday gatherings.',status='ACTIVE',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='seasonal-holiday-packages';

UPDATE store_products SET brand_name='VNV Gourmet To Go',lead_hours=24,allow_recurring_purchase=0,updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND (brand_name IN ('VNV Gourmet Express','VNV Gourmet To Go') OR slug IN ('homemade-lasagna-pasticho','venezuelan-christmas-dinner','thanksgiving-dinner-to-go','nochebuena-dinner','seafood-paella','chicken-chorizo-paella'));
UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product SET v.lead_hours=24,v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents';
UPDATE store_gourmet_settings SET minimum_order_notice_hours=24,pause_message=REPLACE(COALESCE(pause_message,'VNV Gourmet To Go is temporarily pausing new orders. You can still browse the menu.'),'VNV Gourmet Express','VNV Gourmet To Go') WHERE id_owner=2 AND site_key='vnvevents';

UPDATE store_products SET name='Chickpea Salad',short_description='Fresh chickpea salad prepared as a chilled side.',description='<p>A chilled chickpea salad available in Small, Medium and Large sizes.</p>',updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='salad-add-on';

INSERT INTO store_products (id_owner,site_key,name,slug,sku,brand_name,short_description,description,main_image,product_type,purchase_mode,allow_immediate_payment,allow_recurring_purchase,fulfillment_type,product_role,is_addon_only,lead_hours,price,currency_code,stock_quantity,min_purchase_qty,is_featured,is_public,status,created_at,updated_at)
VALUES (2,'vnvevents','Pasta al Horno','pasta-al-horno','VNV-GTG-PAH','VNV Gourmet To Go','Baked pasta, house style: rich sauce, melted mozzarella, golden on top.','<p>A house specialty from the VNV kitchen. Pasta baked in a rich sauce under a layer of melted mozzarella, delivered in the tray, ready for the oven.</p><ul><li><strong>Classic:</strong> slow-cooked beef ragù, ricotta and mozzarella.</li><li><strong>Creamy Chicken:</strong> shredded chicken in a creamy white sauce with mozzarella.</li><li><strong>Four Cheese (vegetarian):</strong> tomato sauce, ricotta, mozzarella, parmesan and provolone.</li></ul>','/assets/images/vnv-gourmet-to-go/pasta-al-horno.webp','VARIABLE','DIRECT',1,0,'DELIVERY','MAIN',0,24,0,'USD',999,1,1,1,'ACTIVE',NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name),sku=VALUES(sku),brand_name=VALUES(brand_name),short_description=VALUES(short_description),description=VALUES(description),main_image=VALUES(main_image),product_type='VARIABLE',purchase_mode='DIRECT',allow_immediate_payment=1,allow_recurring_purchase=0,fulfillment_type='DELIVERY',product_role='MAIN',is_addon_only=0,lead_hours=24,price=0,stock_quantity=999,is_featured=1,is_public=1,status='ACTIVE',updated_at=NOW();

DELETE pc FROM store_products_categories pc JOIN store_products p ON p.id=pc.id_product WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug='pasta-al-horno';
INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT 2,p.id,c.id FROM store_products p JOIN store_categories c ON c.id_owner=2 AND c.site_key='vnvevents' AND c.slug='family-meals'
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug='pasta-al-horno' LIMIT 1;

DELETE pc FROM store_products_categories pc JOIN store_products p ON p.id=pc.id_product JOIN store_categories c ON c.id=pc.id_category
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('homemade-lasagna-pasticho','chicken-chorizo-paella','seafood-paella') AND c.slug IN ('party-trays','family-meals');
INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT 2,p.id,c.id FROM store_products p JOIN store_categories c ON c.id_owner=2 AND c.site_key='vnvevents' AND c.slug='family-meals'
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('homemade-lasagna-pasticho','chicken-chorizo-paella','seafood-paella');

UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product SET v.status='INACTIVE',v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('homemade-lasagna-pasticho','pasta-al-horno','venezuelan-christmas-dinner');

INSERT INTO store_product_variations (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,currency_code,stock_quantity,min_purchase_qty,sort_order,status,created_at,updated_at)
SELECT 2,p.id,s.name,s.slug,s.size_code,s.gmin,s.gmax,NULL,24,CONCAT(p.sku,'-',s.sku_suffix),s.price,'USD',999,1,s.ord,'ACTIVE',NOW(),NOW()
FROM store_products p JOIN (
 SELECT 'homemade-lasagna-pasticho' ps,'Beef · Small · Serves 6–8' name,'beef-small' slug,'S' size_code,6 gmin,8 gmax,'B-S' sku_suffix,89 price,10 ord UNION ALL
 SELECT 'homemade-lasagna-pasticho','Beef · Medium · Serves 10–12','beef-medium','M',10,12,'B-M',139,20 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Beef · Large · Serves 15–20','beef-large','L',15,20,'B-L',199,30 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Chicken · Small · Serves 6–8','chicken-small','S',6,8,'C-S',89,40 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Chicken · Medium · Serves 10–12','chicken-medium','M',10,12,'C-M',139,50 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Chicken · Large · Serves 15–20','chicken-large','L',15,20,'C-L',199,60 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Vegetarian · Small · Serves 6–8','vegetarian-small','S',6,8,'V-S',89,70 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Vegetarian · Medium · Serves 10–12','vegetarian-medium','M',10,12,'V-M',139,80 UNION ALL
 SELECT 'homemade-lasagna-pasticho','Vegetarian · Large · Serves 15–20','vegetarian-large','L',15,20,'V-L',199,90 UNION ALL
 SELECT 'pasta-al-horno','Classic · Small · Serves 6–8','classic-small','S',6,8,'CL-S',75,10 UNION ALL
 SELECT 'pasta-al-horno','Classic · Medium · Serves 10–12','classic-medium','M',10,12,'CL-M',119,20 UNION ALL
 SELECT 'pasta-al-horno','Classic · Large · Serves 15–20','classic-large','L',15,20,'CL-L',169,30 UNION ALL
 SELECT 'pasta-al-horno','Creamy Chicken · Small · Serves 6–8','creamy-chicken-small','S',6,8,'CC-S',75,40 UNION ALL
 SELECT 'pasta-al-horno','Creamy Chicken · Medium · Serves 10–12','creamy-chicken-medium','M',10,12,'CC-M',119,50 UNION ALL
 SELECT 'pasta-al-horno','Creamy Chicken · Large · Serves 15–20','creamy-chicken-large','L',15,20,'CC-L',169,60 UNION ALL
 SELECT 'pasta-al-horno','Four Cheese · Small · Serves 6–8','four-cheese-small','S',6,8,'FC-S',75,70 UNION ALL
 SELECT 'pasta-al-horno','Four Cheese · Medium · Serves 10–12','four-cheese-medium','M',10,12,'FC-M',119,80 UNION ALL
 SELECT 'pasta-al-horno','Four Cheese · Large · Serves 15–20','four-cheese-large','L',15,20,'FC-L',169,90 UNION ALL
 SELECT 'venezuelan-christmas-dinner','Medium · Serves 8–10','medium-serves-8-10','M',8,10,'M',329,10 UNION ALL
 SELECT 'venezuelan-christmas-dinner','Large · Serves 14–16','large-serves-14-16','L',14,16,'L',489,20
) s ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.ps
ON DUPLICATE KEY UPDATE name=VALUES(name),size_code=VALUES(size_code),guests_min=VALUES(guests_min),guests_max=VALUES(guests_max),lead_hours=24,sku=VALUES(sku),price=VALUES(price),stock_quantity=999,sort_order=VALUES(sort_order),status='ACTIVE',updated_at=NOW();

INSERT INTO store_attributes (id_owner,name,slug,status,created_at)
SELECT 2,'Flavor','flavor','ACTIVE',NOW() WHERE NOT EXISTS (SELECT 1 FROM store_attributes WHERE id_owner=2 AND slug='flavor');
INSERT INTO store_attributes (id_owner,name,slug,status,created_at)
SELECT 2,'Size','size','ACTIVE',NOW() WHERE NOT EXISTS (SELECT 1 FROM store_attributes WHERE id_owner=2 AND slug='size');

INSERT INTO store_attribute_values (id_owner,id_attribute,value,slug,sort_order,status)
SELECT 2,a.id,s.value,s.slug,s.ord,'ACTIVE' FROM store_attributes a JOIN (
 SELECT 'flavor' a_slug,'Beef' value,'beef' slug,10 ord UNION ALL SELECT 'flavor','Chicken','chicken',20 UNION ALL SELECT 'flavor','Vegetarian','vegetarian',30 UNION ALL
 SELECT 'flavor','Classic','classic',40 UNION ALL SELECT 'flavor','Creamy Chicken','creamy-chicken',50 UNION ALL SELECT 'flavor','Four Cheese','four-cheese',60 UNION ALL
 SELECT 'size','Small','small',10 UNION ALL SELECT 'size','Medium','medium',20 UNION ALL SELECT 'size','Large','large',30
) s ON a.id_owner=2 AND a.slug=s.a_slug
WHERE NOT EXISTS (SELECT 1 FROM store_attribute_values av WHERE av.id_attribute=a.id AND av.slug=s.slug);

DELETE spvv FROM store_product_variation_values spvv JOIN store_product_variations v ON v.id=spvv.id_variation JOIN store_products p ON p.id=v.id_product
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('homemade-lasagna-pasticho','pasta-al-horno');
INSERT INTO store_product_variation_values (id_owner,id_variation,id_attribute,id_attribute_value)
SELECT 2,v.id,a.id,av.id FROM store_product_variations v JOIN store_products p ON p.id=v.id_product
JOIN store_attributes a ON a.id_owner=2 AND a.slug='flavor'
JOIN store_attribute_values av ON av.id_attribute=a.id AND av.slug=CASE WHEN v.slug LIKE 'creamy-chicken-%' THEN 'creamy-chicken' WHEN v.slug LIKE 'four-cheese-%' THEN 'four-cheese' ELSE SUBSTRING_INDEX(v.slug,'-',1) END
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('homemade-lasagna-pasticho','pasta-al-horno') AND v.status='ACTIVE';
INSERT INTO store_product_variation_values (id_owner,id_variation,id_attribute,id_attribute_value)
SELECT 2,v.id,a.id,av.id FROM store_product_variations v JOIN store_products p ON p.id=v.id_product
JOIN store_attributes a ON a.id_owner=2 AND a.slug='size'
JOIN store_attribute_values av ON av.id_attribute=a.id AND av.slug=CASE v.size_code WHEN 'S' THEN 'small' WHEN 'M' THEN 'medium' ELSE 'large' END
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('homemade-lasagna-pasticho','pasta-al-horno') AND v.status='ACTIVE';

INSERT INTO store_product_food_profiles (id_owner,site_key,id_product,minimum_servings,included_servings,maximum_servings,additional_person_price,cooking_preferences_json,whats_included_html,lead_time_note,preparation_instructions,reheating_instructions,serving_instructions,plating_instructions,presentation_instructions)
SELECT 2,'vnvevents',p.id,6,6,20,0,'[]',s.included,'Order at least 24 hours ahead.',s.preparation,s.reheating,s.serving,NULL,NULL
FROM store_products p JOIN (
 SELECT 'pasta-al-horno' slug,'<ul><li>Small (serves 6–8): approximately 6 lb · 1 deep half pan</li><li>Medium (serves 10–12): approximately 9 lb · 2 deep half pans</li><li>Large (serves 15–20): approximately 15 lb · 3 deep half pans</li><li>Garlic bread, salad and dessert are not included unless added.</li></ul>' included,'Keep refrigerated until ready to reheat. Reheat to an internal temperature of 165 °F.' preparation,'350 °F, covered with the foil lid, 25–30 min. Uncover for the last 5–10 min so the cheese browns.' reheating,'Let it rest 5 min, then serve straight from the tray.' serving UNION ALL
 SELECT 'homemade-lasagna-pasticho','<ul><li>Small (serves 6–8): approximately 6 lb · 1 deep half pan</li><li>Medium (serves 10–12): approximately 9 lb · 2 deep half pans</li><li>Large (serves 15–20): approximately 15 lb · 3 deep half pans</li><li>Garlic bread, salad and dessert are not included unless added.</li></ul>','Keep refrigerated until ready to reheat. Reheat to an internal temperature of 165 °F.','350 °F, covered, for 25–30 min. Uncover for the last 5–10 min to brown the cheese.','Let rest 5 min, then serve straight from the tray.' UNION ALL
 SELECT 'venezuelan-christmas-dinner','<ul><li>Medium serves 8–10 guests.</li><li>Large serves 14–16 guests.</li><li>Hallacas, pernil, ensalada de gallina and pan de jamón.</li></ul>','Keep refrigerated until ready to reheat. Reheat hot items to an internal temperature of 165 °F.','Reheat covered at 350 °F until hot; keep chilled components refrigerated until serving.','Arrange the included holiday dishes together and serve.'
) s ON p.id_owner=2 AND p.site_key='vnvevents' AND p.slug=s.slug
ON DUPLICATE KEY UPDATE minimum_servings=VALUES(minimum_servings),included_servings=VALUES(included_servings),maximum_servings=VALUES(maximum_servings),whats_included_html=VALUES(whats_included_html),lead_time_note=VALUES(lead_time_note),preparation_instructions=VALUES(preparation_instructions),reheating_instructions=VALUES(reheating_instructions),serving_instructions=VALUES(serving_instructions),plating_instructions=NULL,presentation_instructions=NULL,updated_at=NOW();

UPDATE store_product_food_profiles f JOIN store_products p ON p.id=f.id_product
SET f.lead_time_note='Order at least 24 hours ahead.',f.preparation_instructions='Keep refrigerated until serving.',f.reheating_instructions='No reheating required. Serve chilled or at the recommended serving temperature.',f.serving_instructions='Serve directly from the provided box or platter.',f.plating_instructions=NULL,f.presentation_instructions=NULL,f.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('charcuterie-to-go','signature-appetizer-box','dessert-box','latin-bites-box');
UPDATE store_product_food_profiles f JOIN store_products p ON p.id=f.id_product SET f.lead_time_note='Order at least 24 hours ahead.',f.updated_at=NOW() WHERE p.id_owner=2 AND p.site_key='vnvevents';

INSERT INTO store_product_relationships (id_owner,site_key,id_product,id_related_product,relationship_type,included_quantity,quantity_multiplier,size_match,sort_order,status)
SELECT 2,'vnvevents',parent.id,child.id,'ADD_ON',0,1,CASE WHEN child.slug IN ('salad-add-on','dessert-box') THEN 1 ELSE 0 END,s.ord,'ACTIVE'
FROM (SELECT 'garlic-bread' slug,10 ord UNION ALL SELECT 'salad-add-on',20 UNION ALL SELECT 'dessert-box',30 UNION ALL SELECT 'chafing-kit',40 UNION ALL SELECT 'plates-napkins',50) s
JOIN store_products parent ON parent.id_owner=2 AND parent.site_key='vnvevents' AND parent.slug='pasta-al-horno'
JOIN store_products child ON child.id_owner=2 AND child.site_key='vnvevents' AND child.slug=s.slug
ON DUPLICATE KEY UPDATE quantity_multiplier=1,size_match=VALUES(size_match),sort_order=VALUES(sort_order),status='ACTIVE';

INSERT INTO store_product_media (id_owner,site_key,id_product,slot,image_url,alt_text,focal_x,focal_y,width_px,height_px,is_real_photo,status,sort_order)
SELECT 2,'vnvevents',p.id,'HERO','/assets/images/vnv-gourmet-to-go/pasta-al-horno.webp','Pasta al Horno',50,50,NULL,NULL,0,'ACTIVE',10
FROM store_products p WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug='pasta-al-horno'
ON DUPLICATE KEY UPDATE image_url=VALUES(image_url),alt_text=VALUES(alt_text),focal_x=50,focal_y=50,is_real_photo=0,status='ACTIVE',sort_order=10,updated_at=NOW();

COMMIT;
SELECT 'Gourmet To Go unified and Pasta al Horno ready' AS result;
