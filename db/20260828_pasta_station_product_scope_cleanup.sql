-- Remove two inherited VNV event-service products from The Pasta Station.
-- Safe to run repeatedly. Product ownership stays unchanged; only the public
-- site scope is corrected so VNV can continue serving these products.
SET NAMES utf8mb4;

UPDATE store_products
SET site_key = 'vnvevents'
WHERE id_owner = 2
  AND site_key = 'avomeal'
  AND slug IN ('streaming-services', 'fog-and-bubble-machine');

SELECT id, name, slug, site_key, is_public, status
FROM store_products
WHERE id_owner = 2
  AND slug IN ('streaming-services', 'fog-and-bubble-machine')
ORDER BY id;
