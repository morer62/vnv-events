-- Local/development demo catalog for VNV Gourmet To Go.
-- Every row is unmistakably marked DEMO and can be disabled before production.
SET NAMES utf8mb4;

-- Repair duplicate To Go categories created before their natural key was enforced.
UPDATE store_products_categories spc
JOIN store_categories duplicate_category ON duplicate_category.id=spc.id_category
JOIN (
  SELECT slug,MIN(id) keep_id FROM store_categories
  WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'to-go-%' GROUP BY slug
) canonical ON canonical.slug=duplicate_category.slug
SET spc.id_category=canonical.keep_id
WHERE duplicate_category.id_owner=2 AND duplicate_category.site_key='vnvevents' AND duplicate_category.id<>canonical.keep_id;

DELETE duplicate_link FROM store_products_categories duplicate_link
JOIN store_products_categories canonical_link
  ON canonical_link.id_product=duplicate_link.id_product
 AND canonical_link.id_category=duplicate_link.id_category
 AND canonical_link.id<duplicate_link.id;

DELETE visibility FROM site_visibility visibility
JOIN store_categories duplicate_category ON duplicate_category.id=visibility.entity_id
JOIN (
  SELECT slug,MIN(id) keep_id FROM store_categories
  WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'to-go-%' GROUP BY slug
) canonical ON canonical.slug=duplicate_category.slug
WHERE visibility.entity_type='store_category' AND duplicate_category.id<>canonical.keep_id;

DELETE duplicate_category FROM store_categories duplicate_category
JOIN (
  SELECT slug,MIN(id) keep_id FROM store_categories
  WHERE id_owner=2 AND site_key='vnvevents' AND slug LIKE 'to-go-%' GROUP BY slug
) canonical ON canonical.slug=duplicate_category.slug
WHERE duplicate_category.id_owner=2 AND duplicate_category.site_key='vnvevents' AND duplicate_category.id<>canonical.keep_id;

ALTER TABLE store_categories ADD UNIQUE KEY IF NOT EXISTS uq_store_categories_scope_slug (id_owner,site_key,slug);

INSERT INTO store_categories (id_owner,site_key,name,slug,description,icon,meta_title,meta_description,status,created_at,updated_at)
SELECT seed.* FROM (
  SELECT 2 id_owner,'vnvevents' site_key,'Seasonal' name,'to-go-seasonal' slug,'Limited menus designed for holidays and seasonal gatherings.' description,'fa-snowflake' icon,'Seasonal To Go | VNV Gourmet' meta_title,'Seasonal VNV Gourmet dishes delivered in South Florida.' meta_description,'ACTIVE' status,NOW() created_at,NOW() updated_at
) seed WHERE NOT EXISTS (SELECT 1 FROM store_categories c WHERE c.id_owner=seed.id_owner AND c.site_key=seed.site_key AND c.slug=seed.slug);

INSERT INTO store_products
  (id_owner,site_key,name,slug,sku,brand_name,short_description,description,product_type,purchase_mode,allow_immediate_payment,allow_recurring_purchase,fulfillment_type,price,currency_code,main_image,stock_quantity,min_purchase_qty,max_purchase_qty,is_featured,is_public,status,created_at,updated_at)
SELECT seed.* FROM (
  SELECT 2 id_owner,'vnvevents' site_key,'[DEMO] Beef Tenderloin Dinner' name,'demo-gourmet-to-go-beef-tenderloin' slug,'DEMO-GTG-MAIN-01' sku,'VNV Gourmet To Go' brand_name,'Medium-rare tenderloin package with polished sides.' short_description,'Development product. Chef-prepared beef tenderloin package with heating, plating and presentation guidance.' description,'FIXED' product_type,'DIRECT' purchase_mode,1 allow_immediate_payment,1 allow_recurring_purchase,'DELIVERY' fulfillment_type,180.00 price,'USD' currency_code,'https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp' main_image,100 stock_quantity,1 min_purchase_qty,10 max_purchase_qty,1 is_featured,1 is_public,'ACTIVE' status,NOW() created_at,NOW() updated_at
  UNION ALL SELECT 2,'vnvevents','[DEMO] Herb-Roasted Salmon Supper','demo-gourmet-to-go-herb-salmon','DEMO-GTG-MAIN-02','VNV Gourmet To Go','Herb-roasted salmon with seasonal vegetables.','Development product. Salmon supper prepared for delivery and easy finishing at home.','FIXED','DIRECT',1,1,'DELIVERY',156.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926831/ophyra-growth-hub/vnvevents/service-photos/pasta-station-2-lite.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
  UNION ALL SELECT 2,'vnvevents','[DEMO] Burrata & Tomato Board','demo-gourmet-to-go-burrata-board','DEMO-GTG-APP-01','VNV Gourmet To Go','Burrata, tomatoes, herbs and crostini for effortless hosting.','Development product. Chilled appetizer board with assembly instructions.','FIXED','DIRECT',1,1,'DELIVERY',72.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
  UNION ALL SELECT 2,'vnvevents','[DEMO] Mini Croquette Collection','demo-gourmet-to-go-croquettes','DEMO-GTG-APP-02','VNV Gourmet To Go','A party-ready assortment of crisp croquettes.','Development product. Reheatable appetizer collection with sauce and presentation guide.','FIXED','DIRECT',1,1,'DELIVERY',64.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926831/ophyra-growth-hub/vnvevents/service-photos/pasta-station-2-lite.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
  UNION ALL SELECT 2,'vnvevents','[DEMO] Classic Tiramisu Tray','demo-gourmet-to-go-tiramisu','DEMO-GTG-DES-01','VNV Gourmet To Go','A chilled tiramisu tray finished with cocoa.','Development product. Celebration-size tiramisu with serving and presentation notes.','FIXED','DIRECT',1,1,'DELIVERY',58.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
  UNION ALL SELECT 2,'vnvevents','[DEMO] Cannoli Celebration Box','demo-gourmet-to-go-cannoli-box','DEMO-GTG-DES-02','VNV Gourmet To Go','Crisp cannoli prepared for a polished dessert display.','Development product. Cannoli box with serving and display instructions.','FIXED','DIRECT',1,1,'DELIVERY',54.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926831/ophyra-growth-hub/vnvevents/service-photos/pasta-station-2-lite.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
  UNION ALL SELECT 2,'vnvevents','[DEMO] Holiday Roast Package','demo-gourmet-to-go-holiday-roast','DEMO-GTG-SEA-01','VNV Gourmet To Go','A seasonal roast dinner designed for holiday hosting.','Development product. Limited seasonal package with preparation timeline.','FIXED','DIRECT',1,1,'DELIVERY',210.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926830/ophyra-growth-hub/vnvevents/service-photos/pasta-station-1.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
  UNION ALL SELECT 2,'vnvevents','[DEMO] Seasonal Brunch Table','demo-gourmet-to-go-seasonal-brunch','DEMO-GTG-SEA-02','VNV Gourmet To Go','A seasonal brunch spread ready to heat and present.','Development product. Brunch package with delivery-day setup guide.','FIXED','DIRECT',1,1,'DELIVERY',145.00,'USD','https://res.cloudinary.com/djlzi8bdv/image/upload/v1787926831/ophyra-growth-hub/vnvevents/service-photos/pasta-station-2-lite.webp',100,1,10,0,1,'ACTIVE',NOW(),NOW()
) seed
WHERE NOT EXISTS (SELECT 1 FROM store_products product WHERE product.id_owner=seed.id_owner AND product.site_key=seed.site_key AND product.slug=seed.slug);

INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT 2,p.id,c.id FROM store_products p
JOIN store_categories c ON c.id_owner=2 AND c.site_key='vnvevents' AND c.slug=CASE
  WHEN p.slug IN ('demo-gourmet-to-go-beef-tenderloin','demo-gourmet-to-go-herb-salmon') THEN 'to-go-main-courses'
  WHEN p.slug IN ('demo-gourmet-to-go-burrata-board','demo-gourmet-to-go-croquettes') THEN 'to-go-appetizers'
  WHEN p.slug IN ('demo-gourmet-to-go-tiramisu','demo-gourmet-to-go-cannoli-box') THEN 'to-go-desserts'
  ELSE 'to-go-seasonal' END
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug LIKE 'demo-gourmet-to-go-%'
AND NOT EXISTS (SELECT 1 FROM store_products_categories pc WHERE pc.id_product=p.id AND pc.id_category=c.id);

INSERT INTO store_product_food_profiles
  (id_owner,site_key,id_product,minimum_servings,included_servings,maximum_servings,additional_person_price,cooking_preferences_json,preparation_instructions,reheating_instructions,serving_instructions,plating_instructions,presentation_instructions)
SELECT 2,'vnvevents',p.id,6,8,20,
  CASE WHEN p.slug='demo-gourmet-to-go-beef-tenderloin' THEN 22.00 ELSE 14.00 END,
  CASE WHEN p.slug='demo-gourmet-to-go-beef-tenderloin' THEN JSON_ARRAY('Rare','Medium Rare','Medium','Medium Well','Well Done') ELSE NULL END,
  'Keep refrigerated until the preparation window shown in your private order guide.',
  'Follow the temperature and timing printed for this specific dish. Verify food-safe temperature before serving.',
  'Allow the dish to rest briefly, then portion using the serving guide included with the order.',
  'Transfer to a warmed serving platter and finish with the packaged garnish immediately before service.',
  'Use a clean neutral platter, keep sauces separate, and replenish in smaller portions for the best presentation.'
FROM store_products p
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug LIKE 'demo-gourmet-to-go-%'
ON DUPLICATE KEY UPDATE
  minimum_servings=VALUES(minimum_servings),
  included_servings=VALUES(included_servings),
  maximum_servings=VALUES(maximum_servings),
  additional_person_price=VALUES(additional_person_price),
  cooking_preferences_json=VALUES(cooking_preferences_json),
  preparation_instructions=VALUES(preparation_instructions),
  reheating_instructions=VALUES(reheating_instructions),
  serving_instructions=VALUES(serving_instructions),
  plating_instructions=VALUES(plating_instructions),
  presentation_instructions=VALUES(presentation_instructions),
  updated_at=CURRENT_TIMESTAMP;

SELECT p.id,p.name,p.slug,c.name category,fp.included_servings,fp.additional_person_price
FROM store_products p
JOIN store_products_categories pc ON pc.id_product=p.id
JOIN store_categories c ON c.id=pc.id_category
JOIN store_product_food_profiles fp ON fp.id_product=p.id
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug LIKE 'demo-gourmet-to-go-%'
ORDER BY c.name,p.name;
