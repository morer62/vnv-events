-- Clarifies the public VNV Gourmet Express catalog without changing prices or order history.
-- Idempotent. Scoped to owner 2 / vnvevents.
SET NAMES utf8mb4;
START TRANSACTION;

-- Retain the historical bundle category row, but remove it from the active catalog taxonomy.
UPDATE store_categories
SET status='INACTIVE',updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='express-bundles';

-- Keep the stable slug and relationships while presenting the category in customer language.
UPDATE store_categories
SET name='Appetizers & Boards',
    description='Party-ready appetizer boxes, Latin bites and charcuterie boards for sharing.',
    status='ACTIVE',updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='party-boxes';

UPDATE store_products SET
 short_description='Beef, chicken or vegetarian lasagna/pasticho in a disposable catering tray.',
 description='<p>Choose traditional beef, chicken or vegetarian lasagna/pasticho. Each order includes one chef-prepared disposable catering tray in the selected size: Small serves 6–8, Medium serves 10–12, and Large serves 15–20. Garlic bread, salad, dessert, setup and serving staff are not included unless added separately.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='homemade-lasagna-pasticho';

UPDATE store_products SET
 short_description='Tequeños, mini empanadas, croquetas, mini arepitas and signature sauces.',
 description='<p>A mixed Latin appetizer box with tequeños, mini empanadas, croquetas, mini arepitas and signature sauces. Small includes 60 total pieces and serves 6–8; Medium includes 100 pieces and serves 10–12; Large includes 150 pieces and serves 15–20. Piece counts cover the complete assortment; the quantity of each individual bite may vary.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='latin-bites-box';

UPDATE store_products SET
 short_description='A chef-selected assortment of premium bite-size appetizers for effortless hosting.',
 description='<p>A ready-to-serve assortment of premium bite-size appetizers selected for meetings, showers and celebrations. Available in Medium for 10–12 guests and Large for 15–20 guests; a Small size is not offered. The exact appetizer mix may vary with availability and is confirmed for the order. Setup, serving staff and disposable place settings are not included.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='signature-appetizer-box';

UPDATE store_products SET
 short_description='Cheeses, cured meats, seasonal fruit, nuts, crackers and accompaniments.',
 description='<p>A ready-to-serve charcuterie board with assorted cheeses, cured meats, seasonal fruit, nuts, crackers and complementary accompaniments. Small serves 6–8, Medium serves 10–12, and Large serves 15–20. Selection may vary seasonally; setup, serving staff and disposable place settings are not included.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='charcuterie-to-go';

UPDATE store_products SET
 short_description='Chicken, chorizo, saffron rice and vegetables in a disposable catering tray.',
 description='<p>Chicken and chorizo cooked with saffron rice, vegetables and traditional paella seasoning. Delivered in one premium disposable catering tray: Small serves 6–8, Medium serves 10–12, and Large serves 15–20. No pan or equipment return is required. Salad, dessert, chafing equipment, setup and serving staff are not included unless added separately.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='chicken-chorizo-paella';

UPDATE store_products SET
 short_description='Shrimp, mussels and seasonal seafood with saffron rice in a catering tray.',
 description='<p>Seafood paella with shrimp, mussels, seasonal seafood, saffron rice and vegetables. Delivered in one premium disposable catering tray: Small serves 6–8, Medium serves 10–12, and Large serves 15–20. The seasonal seafood selection may vary. No pan return is required; salad, dessert, chafing equipment, setup and serving staff are not included unless added separately.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='seafood-paella';

UPDATE store_products SET
 short_description='A rotating chef-selected assortment of shareable VNV desserts.',
 description='<p>A shareable box of chef-selected VNV desserts. Small serves 6–8, Medium serves 10–12, and Large serves 15–20. The exact dessert assortment and individual piece mix vary with availability and are confirmed for the order. Candles, cake cutting, display setup and serving staff are not included.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='dessert-box';

UPDATE store_products SET
 short_description='Lechón asado, moros y cristianos, yuca con mojo, maduros and salad.',
 description='<p>A complete Nochebuena dinner with lechón asado, moros y cristianos, yuca con mojo, sweet maduros and salad. Choose the package serving 8–10 or 14–16 guests. Pan de jamón, desserts, chafing equipment, setup and serving staff are not included unless added separately. This holiday menu requires at least 48 hours notice.</p>',
 updated_at=NOW()
WHERE id_owner=2 AND site_key='vnvevents' AND slug='nochebuena-dinner';

COMMIT;
SELECT 'VNV Gourmet Express catalog clarity ready' AS result;
