-- Align the Shawarma Station website and estimate-service description with
-- the approved client-facing menu. Classification, pricing and variations
-- are intentionally unchanged.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE orders_services
SET description='Freshly carved chicken and beef shawarma with warm pita, shredded lettuce, diced tomatoes and cucumbers, red onions, garlic toum, tahini, classic hummus, Mediterranean rice and tabbouleh salad. Includes two hours of live service, freshly prepared shawarma, a professional chef and serving staff, Mediterranean-style station setup, disposable plates, napkins and cutlery, plus a fresh toppings, bread and sauce display. Minimum 25 guests. Final selections, dietary accommodations, delivery, venue requirements and the final total are confirmed in the written proposal.'
WHERE id_owner=2
  AND is_archived=0
  AND LOWER(name) IN ('shawarma station','shawarma station (per guest)');

UPDATE store_products
SET short_description='Freshly carved chicken and beef shawarma with warm pita, fresh toppings, signature sauces, Mediterranean rice and tabbouleh. Includes two hours of live service, a professional chef and serving staff, station setup and disposables. $19 per guest, 25-guest minimum.',
    description='<h2>Shawarma Station</h2><p><strong>Freshly Carved &bull; Mediterranean Flavors &bull; Live Cooking Experience</strong></p><h3>1. Proteins</h3><ul><li><strong>Chicken Shawarma</strong> &ndash; Marinated chicken with Mediterranean spices.</li><li><strong>Beef Shawarma</strong> &ndash; Tender beef marinated and slow-roasted.</li></ul><h3>2. Bread</h3><ul><li>Warm pita bread</li></ul><h3>3. Fresh Toppings</h3><ul><li>Shredded lettuce</li><li>Diced tomatoes and cucumbers</li><li>Red onions</li></ul><h3>4. Signature Sauces</h3><ul><li>Garlic toum sauce</li><li>Tahini sauce</li><li>Classic hummus</li></ul><h3>5. Sides</h3><ul><li>Mediterranean rice</li><li>Tabbouleh salad</li></ul><h3>6. Live Station Experience</h3><ul><li>Two hours of live service</li><li>Freshly prepared shawarma</li><li>Professional chef and serving staff</li><li>Mediterranean-style station setup</li><li>Disposable plates, napkins and cutlery</li><li>Fresh toppings, bread and sauce display</li></ul><h3>Pricing</h3><p>$19 per guest with a 25-guest minimum.</p><p>Final selections, dietary accommodations, delivery, taxes, venue rules and the final total are confirmed in your written proposal.</p><p><a href="/shawarma-station-catering-south-florida/">Learn more about Shawarma Station catering</a></p>',
    updated_at=NOW()
WHERE id_owner=2
  AND site_key='vnvevents'
  AND slug='shawarma-station';

COMMIT;
