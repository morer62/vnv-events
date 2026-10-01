-- Assigns purpose-built catalog photography only to the VNV Gourmet To Go launch products.
-- Existing products outside this exact owner/site/slug scope are untouched. Safe to repeat.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE store_products p
JOIN (
  SELECT 'homemade-lasagna-pasticho' slug, '/assets/images/vnv-gourmet-to-go/homemade-lasagna-pasticho.webp' image_path
  UNION ALL SELECT 'latin-bites-box', '/assets/images/vnv-gourmet-to-go/latin-bites-box.webp'
  UNION ALL SELECT 'signature-appetizer-box', '/assets/images/vnv-gourmet-to-go/signature-appetizer-box.webp'
  UNION ALL SELECT 'charcuterie-to-go', '/assets/images/vnv-gourmet-to-go/charcuterie-to-go.webp'
  UNION ALL SELECT 'chicken-chorizo-paella', '/assets/images/vnv-gourmet-to-go/chicken-chorizo-paella.webp'
  UNION ALL SELECT 'seafood-paella', '/assets/images/vnv-gourmet-to-go/seafood-paella.webp'
  UNION ALL SELECT 'dessert-box', '/assets/images/vnv-gourmet-to-go/dessert-box.webp'
  UNION ALL SELECT 'ensaimada-box', '/assets/images/vnv-gourmet-to-go/ensaimada-box.webp'
  UNION ALL SELECT 'garlic-bread', '/assets/images/vnv-gourmet-to-go/garlic-bread.webp'
  UNION ALL SELECT 'salad-add-on', '/assets/images/vnv-gourmet-to-go/salad-add-on.webp'
  UNION ALL SELECT 'extra-dessert-add-on', '/assets/images/vnv-gourmet-to-go/extra-dessert-add-on.webp'
  UNION ALL SELECT 'thanksgiving-feast', '/assets/images/vnv-gourmet-to-go/thanksgiving-feast.webp'
  UNION ALL SELECT 'thanksgiving-dinner-to-go', '/assets/images/vnv-gourmet-to-go/thanksgiving-dinner-to-go.webp'
  UNION ALL SELECT 'venezuelan-christmas-dinner', '/assets/images/vnv-gourmet-to-go/venezuelan-christmas-dinner.webp'
  UNION ALL SELECT 'latin-holiday-dinner', '/assets/images/vnv-gourmet-to-go/latin-holiday-dinner.webp'
  UNION ALL SELECT 'extra-hallaca', '/assets/images/vnv-gourmet-to-go/extra-hallaca.webp'
  UNION ALL SELECT 'pan-de-jamon', '/assets/images/vnv-gourmet-to-go/pan-de-jamon.webp'
  UNION ALL SELECT 'pernil-by-the-pound', '/assets/images/vnv-gourmet-to-go/pernil-by-the-pound.webp'
) images ON images.slug=p.slug
SET p.main_image=images.image_path,
    p.updated_at=NOW()
WHERE p.id_owner=2
  AND p.site_key='vnvevents';

COMMIT;
SELECT 'VNV Gourmet To Go product images ready' AS result;
