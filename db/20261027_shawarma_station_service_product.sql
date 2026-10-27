-- Shawarma Station: estimate service and public store product.
-- Idempotent and deliberately aligned with the current Taco Station base
-- structure: $19 per guest, 25-guest minimum and two hours of live chefs.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO orders_services
    (name, description, price, requirements, id_owner, is_archived, is_variable)
SELECT
    'Shawarma Station (per guest)',
    'Live Shawarma Station with chicken and beef shawarma, warm pita, hummus, tabbouleh, cucumber-tomato salad, pickled vegetables, garlic sauce and tahini. Includes an elegant buffet presentation and two hours of live-chef service. Minimum 25 guests. Final selections, dietary accommodations, delivery, venue requirements and the final total are confirmed in the written proposal.',
    19.00,
    'Plan for a covered service and cooking area, venue-approved power or fuel source, safe load-in access, vertical rotisserie and carving tools, hot and cold food holding, service table, serving utensils, guest-queue clearance and a weather contingency. Confirm equipment, venue rules, selections and service timing in the written proposal.',
    2,
    0,
    'NO'
WHERE NOT EXISTS (
    SELECT 1 FROM orders_services
    WHERE id_owner=2 AND is_archived=0 AND LOWER(name) IN ('shawarma station','shawarma station (per guest)')
);

UPDATE orders_services
SET name='Shawarma Station (per guest)',
    description='Live Shawarma Station with chicken and beef shawarma, warm pita, hummus, tabbouleh, cucumber-tomato salad, pickled vegetables, garlic sauce and tahini. Includes an elegant buffet presentation and two hours of live-chef service. Minimum 25 guests. Final selections, dietary accommodations, delivery, venue requirements and the final total are confirmed in the written proposal.',
    price=19.00,
    requirements='Plan for a covered service and cooking area, venue-approved power or fuel source, safe load-in access, vertical rotisserie and carving tools, hot and cold food holding, service table, serving utensils, guest-queue clearance and a weather contingency. Confirm equipment, venue rules, selections and service timing in the written proposal.',
    is_variable='NO'
WHERE id_owner=2 AND is_archived=0 AND LOWER(name) IN ('shawarma station','shawarma station (per guest)');

INSERT INTO store_products
    (id_owner,site_key,name,slug,sku,brand_name,short_description,description,main_image,product_type,purchase_mode,allow_immediate_payment,allow_recurring_purchase,fulfillment_type,product_role,is_addon_only,lead_hours,price,currency_code,stock_quantity,min_purchase_qty,is_featured,is_public,status,created_at,updated_at)
VALUES
    (2,'vnvevents','Shawarma Station $19.00 Per Guest (Min 25)','shawarma-station','VNV-SHAW-1','VNV Events',
     'Live-chef Shawarma Station with chicken and beef shawarma, warm pita, hummus, tabbouleh, cucumber-tomato salad, pickled vegetables, garlic sauce and tahini. $19 per guest, 25-guest minimum, two hours of service.',
     '<h2>Shawarma Station</h2><p>A live-chef station for South Florida events, served by VNV Events with an elegant buffet presentation.</p><h3>What is included</h3><ul><li>Chicken shawarma and beef shawarma</li><li>Warm pita</li><li>Hummus, tabbouleh and cucumber-tomato salad</li><li>Pickled vegetables, garlic sauce and tahini</li><li>Two hours of live-chef service</li></ul><h3>Pricing</h3><p>$19 per guest with a 25-guest minimum.</p><p>Final selections, dietary accommodations, delivery, taxes, venue rules and the final total are confirmed in your written proposal.</p><p><a href="/shawarma-station-catering-south-florida/">Learn more about Shawarma Station catering</a></p>',
     '/assets/images/services/shawarma-station.webp','VARIABLE','REQUEST',0,0,'SERVICE','MAIN',0,24,0,'USD',0,1,0,1,'ACTIVE',NOW(),NOW())
ON DUPLICATE KEY UPDATE
    name=VALUES(name),sku=VALUES(sku),brand_name=VALUES(brand_name),short_description=VALUES(short_description),description=VALUES(description),main_image=VALUES(main_image),product_type='VARIABLE',purchase_mode='REQUEST',allow_immediate_payment=0,allow_recurring_purchase=0,fulfillment_type='SERVICE',product_role='MAIN',is_addon_only=0,lead_hours=24,price=0,currency_code='USD',stock_quantity=0,min_purchase_qty=1,is_public=1,status='ACTIVE',updated_at=NOW();

SET @shawarma_product_id := (
    SELECT id FROM store_products WHERE id_owner=2 AND site_key='vnvevents' AND slug='shawarma-station' LIMIT 1
);

DELETE FROM store_products_categories
WHERE id_owner=2 AND id_product=@shawarma_product_id;
INSERT INTO store_products_categories (id_owner,id_product,id_category)
SELECT 2,@shawarma_product_id,id
FROM store_categories
WHERE id_owner=2 AND site_key='vnvevents' AND slug='catering-services' AND status='ACTIVE'
LIMIT 1;

DELETE FROM store_product_variations
WHERE id_owner=2 AND id_product=@shawarma_product_id;
INSERT INTO store_product_variations
    (id_owner,id_product,name,slug,size_code,guests_min,guests_max,piece_count,lead_hours,sku,price,promo_price,currency_code,stock_quantity,min_purchase_qty,sort_order,status,created_at,updated_at)
VALUES
    (2,@shawarma_product_id,'Shawarma Station','pack-1',NULL,NULL,NULL,NULL,NULL,'VNV-SHAW-1-1',19.00,19.00,'USD',10000,1,0,'ACTIVE',NOW(),NOW()),
    (2,@shawarma_product_id,'Shawarma Station + 5 Appetizers p/p','pack-2',NULL,NULL,NULL,NULL,NULL,'VNV-SHAW-1-2',35.00,35.00,'USD',10000,1,1,'ACTIVE',NOW(),NOW()),
    (2,@shawarma_product_id,'Shawarma Station + Dessert Table (5 p/p)','pack-3',NULL,NULL,NULL,NULL,NULL,'VNV-SHAW-1-3',35.00,35.00,'USD',10000,1,2,'ACTIVE',NOW(),NOW()),
    (2,@shawarma_product_id,'Shawarma Station + 5 Appetizers p/p + Dessert Table','pack-4',NULL,NULL,NULL,NULL,NULL,'VNV-SHAW-1-4',42.00,42.00,'USD',10000,1,3,'ACTIVE',NOW(),NOW());

COMMIT;
SELECT @shawarma_product_id AS shawarma_product_id;
