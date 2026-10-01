-- VNV Gourmet Express consistent product copy, drop-off disclaimer and staff CTA.
-- Idempotent. Run after db/20261009_gourmet_express_catalog_precision.sql.
SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE store_gourmet_settings
 ADD COLUMN IF NOT EXISTS dropoff_disclaimer TEXT NULL AFTER pause_message,
 ADD COLUMN IF NOT EXISTS staff_phone VARCHAR(40) NULL AFTER dropoff_disclaimer,
 ADD COLUMN IF NOT EXISTS staff_whatsapp VARCHAR(40) NULL AFTER staff_phone,
 ADD COLUMN IF NOT EXISTS staff_service_url VARCHAR(255) NULL AFTER staff_whatsapp;

ALTER TABLE store_product_food_profiles
 ADD COLUMN IF NOT EXISTS whats_included_html LONGTEXT NULL AFTER cooking_preferences_json,
 ADD COLUMN IF NOT EXISTS lead_time_note VARCHAR(180) NULL AFTER whats_included_html;

UPDATE store_gourmet_settings SET
 dropoff_disclaimer='<strong>Drop-off only.</strong> Your order arrives ready to reheat and serve in premium disposable trays — no equipment to return. Setup, serving staff and serving equipment are not included. Chafing kits and plates & napkins kits can be added to your order.',
 staff_phone='3052042547',staff_whatsapp='13053761210',staff_service_url='/service/catering'
WHERE id_owner=2 AND site_key='vnvevents';

UPDATE store_products SET short_description='Saffron rice with shrimp, mussels and calamari.',description='<p>Our signature seafood paella, cooked to order and delivered ready to reheat. Generous on the seafood, made for the center of the table.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='seafood-paella';
UPDATE store_products SET short_description='Saffron rice with chicken, Spanish chorizo and vegetables.',description='<p>A crowd-pleasing paella with tender chicken and smoky chorizo, cooked to order and delivered ready to reheat.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='chicken-chorizo-paella';
UPDATE store_products SET short_description='Traditional beef, chicken or vegetarian.',description='<p>Layered, baked and ready for the oven. Choose your filling; every tray is made to order.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='homemade-lasagna-pasticho';
UPDATE store_products SET short_description='Cured meats, cheeses, fruit, nuts, olives and crackers.',description='<p>A ready-to-serve board with three cured meats and three cheeses, arranged and sealed for delivery. Just open and serve.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='charcuterie-to-go';
UPDATE store_products SET short_description='Caprese skewers, antipasto skewers, mini quiche and mini croissant sandwiches.',description='<p>Four crowd favorites in one box, packed in fitted compartments so everything arrives intact.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='signature-appetizer-box';
UPDATE store_products SET short_description='Tequeños, mini empanadas, croquetas and mini arepitas with signature sauces.',description='<p>The Latin party classics, ready to crisp up in the oven. Sauces come sealed on the side.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='latin-bites-box';
UPDATE store_products SET short_description='Brownie bites, mini alfajores, lemon bars, cheesecake squares and cookies.',description='<p>A sweet assortment chosen to travel well: firm, bite-size and packed in fitted compartments.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='dessert-box';
UPDATE store_products SET short_description='Lechón asado, moros y cristianos, yuca con mojo, maduros and cabbage & tomato salad.',description='<p>The complete Cuban Christmas Eve table, slow-roasted and ready to reheat. Mojo and dressing travel sealed on the side.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='nochebuena-dinner';
UPDATE store_products SET short_description='Hallacas, pernil, ensalada de gallina and pan de jamón.',description='<p>The traditional Venezuelan Christmas plate for the whole table: hallacas, roasted pernil, ensalada de gallina and a full loaf of pan de jamón.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='venezuelan-christmas-dinner';
UPDATE store_products SET short_description='Carved roast turkey, mashed potatoes, stuffing, green beans, gravy, cranberry sauce and rolls.',description='<p>The full Thanksgiving table, roasted, carved and ready to reheat. You set the table; we handle the cooking.</p>',updated_at=NOW() WHERE id_owner=2 AND site_key='vnvevents' AND slug='thanksgiving-dinner-to-go';

UPDATE store_product_food_profiles f JOIN store_products p ON p.id=f.id_product SET
 f.whats_included_html=CASE p.slug
 WHEN 'seafood-paella' THEN '<ul><li>Small (serves 6–8): 6 lb paella · 1 deep half pan · lemon wedges</li><li>Medium (serves 10–12): 9 lb · 2 deep half pans · lemon wedges</li><li>Large (serves 15–20): 15 lb · 3 deep half pans · lemon wedges</li><li>Approx. 3 shrimp and 3 mussels per guest</li></ul>'
 WHEN 'chicken-chorizo-paella' THEN '<ul><li>Small (serves 6–8): 6 lb · 1 deep half pan</li><li>Medium (serves 10–12): 9 lb · 2 deep half pans</li><li>Large (serves 15–20): 15 lb · 3 deep half pans</li></ul>'
 WHEN 'homemade-lasagna-pasticho' THEN '<ul><li>Small (serves 6–8): 8 portions · 1 half pan</li><li>Medium (serves 10–12): 12 portions · 1 full pan</li><li>Large (serves 15–20): 20 portions · 1 full pan + 1 half pan</li></ul>'
 WHEN 'charcuterie-to-go' THEN '<ul><li>Small (serves 6–8): 1.5 lb meats & cheeses · 14&quot; lidded platter · 1 sleeve of crackers</li><li>Medium (serves 10–12): 2.25 lb · 16&quot; lidded platter · 2 sleeves</li><li>Large (serves 15–20): 3.75 lb · 18&quot; lidded platter · 3 sleeves</li><li>Crackers packed separately so they stay crisp</li></ul>'
 WHEN 'signature-appetizer-box' THEN '<ul><li>Medium (serves 10–12): 80 pieces (20 of each)</li><li>Large (serves 15–20): 120 pieces (30 of each)</li></ul>'
 WHEN 'latin-bites-box' THEN '<ul><li>Small (serves 6–8): 60 pieces · 2 sauces (4 oz)</li><li>Medium (serves 10–12): 100 pieces · 3 sauces</li><li>Large (serves 15–20): 150 pieces · 4 sauces</li><li>Mix: 40% tequeños, 20% mini empanadas, 20% croquetas, 20% mini arepitas</li></ul>'
 WHEN 'dessert-box' THEN '<ul><li>Small (serves 6–8): 36 pieces — 8 brownie bites, 8 alfajores, 6 lemon bars, 6 cheesecake squares, 8 cookies</li><li>Medium (serves 10–12): 54 pieces — 12 / 12 / 9 / 9 / 12</li><li>Large (serves 15–20): 80 pieces — 16 of each</li></ul>'
 WHEN 'nochebuena-dinner' THEN '<ul><li>Serves 8–10: lechón asado 5 lb · moros 4 lb · yuca con mojo 4 lb · maduros 20 pcs · cabbage & tomato salad 2 lb · mojo 8 oz</li><li>Serves 14–16: lechón asado 8 lb · moros 6 lb · yuca 6 lb · maduros 32 pcs · salad 3 lb · mojo 16 oz</li></ul>'
 WHEN 'venezuelan-christmas-dinner' THEN '<ul><li>Serves 8–10: 10 hallacas · pernil 4 lb · ensalada de gallina 3 lb · 1 pan de jamón (approx. 2 lb)</li><li>Serves 14–16: 16 hallacas · pernil 6.5 lb · ensalada de gallina 5 lb · 2 panes de jamón</li></ul>'
 WHEN 'thanksgiving-dinner-to-go' THEN '<ul><li>Serves 8–10: carved turkey 5 lb · mashed potatoes 4 lb · stuffing 4 lb · green beans 3 lb · gravy 32 oz · cranberry sauce 16 oz · 12 dinner rolls</li><li>Serves 14–16: turkey 8 lb · mashed potatoes 6 lb · stuffing 6 lb · green beans 5 lb · gravy 48 oz · cranberry 24 oz · 20 rolls</li></ul>'
 ELSE f.whats_included_html END,
 f.lead_time_note=CASE WHEN p.slug IN ('nochebuena-dinner','venezuelan-christmas-dinner','thanksgiving-dinner-to-go') THEN 'Order at least 48 hours ahead' WHEN p.slug IN ('seafood-paella','chicken-chorizo-paella') THEN 'Order at least 24 hours ahead (Large: 48 hours)' ELSE 'Order at least 24 hours ahead' END,
 f.reheating_instructions=CASE
 WHEN p.slug IN ('seafood-paella','chicken-chorizo-paella') THEN '350 °F, covered, 20–25 min. Add 2–3 tbsp of water around the edges first.'
 WHEN p.slug='homemade-lasagna-pasticho' THEN '350 °F, covered, 35–45 min. Uncover the last 5 min.'
 WHEN p.slug='latin-bites-box' THEN '375 °F, uncovered on a baking sheet, 8–10 min until crisp.'
 WHEN p.slug='nochebuena-dinner' THEN 'Lechón: 325 °F, covered, with a little mojo, 20–25 min. Moros, yuca, maduros: 350 °F, covered, 15–20 min. Salad: serve cold.'
 WHEN p.slug='venezuelan-christmas-dinner' THEN 'Hallacas: in their wrapper, in simmering water or steamed, 15–20 min. Pernil: 325 °F, covered, 20–25 min. Ensalada de gallina and pan de jamón: serve at room temperature.'
 WHEN p.slug='thanksgiving-dinner-to-go' THEN 'Turkey: 325 °F, covered, with a little gravy, 20–25 min. Sides: 350 °F, covered, 15–20 min. Gravy: saucepan or microwave until hot.'
 WHEN p.slug IN ('charcuterie-to-go','signature-appetizer-box','dessert-box') THEN 'No reheating. Take out of the refrigerator 20–30 min before serving.'
 ELSE f.reheating_instructions END,
 f.preparation_instructions='Keep refrigerated until ready to reheat. Preheat the oven, keep the foil lid on unless noted, and reheat to an internal temperature of 165 °F.'
WHERE p.id_owner=2 AND p.site_key='vnvevents' AND p.slug IN ('seafood-paella','chicken-chorizo-paella','homemade-lasagna-pasticho','charcuterie-to-go','signature-appetizer-box','latin-bites-box','dessert-box','nochebuena-dinner','venezuelan-christmas-dinner','thanksgiving-dinner-to-go');

COMMIT;
SELECT 'VNV Gourmet Express product copy ready' AS result;
