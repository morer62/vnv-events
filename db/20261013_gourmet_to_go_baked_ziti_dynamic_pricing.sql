-- Rename Pasta al Horno to Baked Ziti and retain all product/order relationships.
-- Idempotent; run after 20261012_gourmet_to_go_unification_pasta_al_horno.sql.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE store_products SET
 name='Baked Ziti',slug='baked-ziti',sku='VNV-GTG-BZ',
 short_description='Our house baked ziti: rich sauce, melted mozzarella, golden on top.',
 description='<p>A house specialty from the VNV kitchen. Ziti baked in a rich sauce under a layer of melted mozzarella, delivered in the tray and ready for the oven. Choose classic beef ragù, creamy chicken or four cheese.</p>',
 main_image='/assets/images/vnv-gourmet-to-go/baked-ziti.webp',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug IN ('pasta-al-horno','baked-ziti');

UPDATE store_product_variations v JOIN store_products p ON p.id=v.id_product SET
 v.name=CASE
   WHEN v.slug LIKE 'classic-%' THEN REPLACE(v.name,'Classic ·','Classic Beef Ragù ·')
   WHEN v.slug LIKE 'four-cheese-%' THEN REPLACE(v.name,'Four Cheese ·','Four Cheese (Vegetarian) ·')
   ELSE v.name END,
 v.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug='baked-ziti';

UPDATE store_attribute_values av JOIN store_attributes a ON a.id=av.id_attribute SET
 av.value=CASE av.slug WHEN 'classic' THEN 'Classic Beef Ragù' WHEN 'four-cheese' THEN 'Four Cheese (Vegetarian)' ELSE av.value END
WHERE a.id_owner=2 AND a.slug='flavor' AND av.slug IN ('classic','four-cheese');

UPDATE store_product_media m JOIN store_products p ON p.id=m.id_product SET
 m.image_url='/assets/images/vnv-gourmet-to-go/baked-ziti.webp',m.alt_text='Baked Ziti',m.updated_at=NOW()
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug='baked-ziti';

COMMIT;
SELECT 'Baked Ziti rename ready' AS result;
