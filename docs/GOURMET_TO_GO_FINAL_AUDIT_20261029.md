# Gourmet To Go final public audit — 2026-10-29

All HTTP checks below were performed without cookies against `https://vnvevents.com` after the production `git pull` and SQL migration.

## Dessert Box cause and correction

- Production did **not** contain two simultaneously reachable `dessert-box` rows. The canonical product is ID **238** and the duplicate-slug query returned **0** duplicates.
- The database already has the unique index `uniq_store_products_owner_slug`, so an owner cannot keep two products with the same slug.
- `/product/{slug}` calls `StoreProductsRepository::getPublicBySlug()` and only accepts the matching owner/site row when `status='ACTIVE'` and `is_public=1`; it does not intentionally select an inactive product.
- The actual fault was an incomplete earlier upsert: the existing row ID 238 was reused for the new product, while legacy variations, food profile, relationships and recommendations remained attached. This made the public row render old Dessert Box content even though the catalog card carried newer data.
- Migration `db/20261029_gourmet_to_go_final_closure.sql` canonicalizes ID 238, retires any unexpected legacy row under a versioned `dessert-box-legacy-{id}` slug, removes legacy variations/profile/relationships, removes every Ensaimada merchandising path, installs the six correct choices, and sets the verified phone/WhatsApp values.
- Public result: one Dessert Box, 24 pieces/$49 and 48 pieces/$89, six dessert choices, no Ensaimada add-on.

## First 40 lines of logged-out HTML

```html
<!DOCTYPE html>
<html lang="en">
<head>


    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

            
    <title>Dessert Box | VNV Gourmet To Go</title>


<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-T9SFK2QR');</script>
<!-- End Google Tag Manager -->

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-LKJ6553K3Q"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-LKJ6553K3Q');
</script>

                               
    
    
    
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="googlebot" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="bingbot" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

        <meta name="description" content="Build a refrigerated dessert box with your favorite family-recipe sweets.">
    <meta name="keywords" content="party appetizer boxes, appetizer trays Sunrise FL, party food pickup Broward, empanaditas, profiteroles, dessert box, VNV Gourmet To Go">
```

The literal first 40 lines end before the body. The logged-out body separately contains these selector proofs:

```html
<h1 class="pp-title">Dessert Box</h1>
<strong>24 pieces · serves 8–12</strong><span>$49.00</span>
<strong>48 pieces · serves 16–24</strong><span>$89.00</span>
<strong>Cream profiteroles</strong>
<strong>Pastry cream &amp; fresh fruit tartlets</strong>
<strong>Chocolate ganache tartlets</strong>
<strong>Dulce de leche tartlets</strong>
<strong>Guava &amp; cream cheese tartlets</strong>
<strong>Key lime tartlets</strong>
```

## Public photo audit

Every URL returned HTTP 200, every downloaded production file matched the committed MD5, and no two active references share an MD5. “Shows” was recorded after visual inspection of contact sheets, not inferred from filenames.

| Item | Public file | HTTP | Dimensions | Bytes | MD5 | What it actually shows |
|---|---|---:|---:|---:|---|---|
| Appetizer Box — All Day | `/assets/images/gourmet-to-go/gtg-appetizer-box-white-v1.webp` | 200 | 1122×1402 | 277930 | `b78b3d1a50924dd79723f58e95da1e49` | White four-section box: beef empanaditas, mushroom tartlets, bacon-wrapped dates and caprese skewers. |
| Appetizer Box — Breakfast & Brunch | `/assets/images/gourmet-to-go/gtg-breakfast-box.webp` | 200 | 1792×2240 | 549738 | `59c1f4bfa73a2aeb522ec21783549bef` | White breakfast box with croissant sandwiches, parfaits, cheese empanaditas and egg-avocado sliders. |
| Dessert Box | `/assets/images/gourmet-to-go/gtg-dessert-box.webp` | 200 | 1792×2240 | 557976 | `2d3ef4a62e51aae6aba82e4c1383ca42` | White dessert box with profiteroles and three distinct tartlet sections. |
| Brazo Gitano | `/assets/images/gourmet-to-go/gtg-brazo-gitano.webp` | 200 | 1792×2240 | 304702 | `f22ef87f956ee6d2a09ae4351b498160` | Whole cream roll with two spiral slices, halved strawberries and powdered sugar. |
| Argentine beef empanaditas | `/assets/images/gourmet-to-go/choice-beef-empanaditas.webp` | 200 | 800×600 | 41840 | `b5f600a8e4e067cfe64a4d2962b9fe22` | Golden baked mini beef empanadas. |
| Argentine cheese empanaditas | `/assets/images/gourmet-to-go/choice-cheese-empanaditas-v2.webp` | 200 | 1254×1254 | 146232 | `a258b6da6e7ac4cea017fab682c3a2a3` | Golden mini empanadas, one open with melted cheese. |
| Mushroom cream tartlets | `/assets/images/gourmet-to-go/choice-mushroom-tartlets.webp` | 200 | 800×600 | 37334 | `f6a0f3b0e33e6b5ba941baa96ef766b7` | Round tartlets filled with creamy mushrooms. |
| Mini quiche Lorraine | `/assets/images/gourmet-to-go/choice-quiche-lorraine.webp` | 200 | 800×600 | 45118 | `359e3c8eee360d3f9f33b7c5cce6b2af` | Mini quiches with visible bacon. |
| Bacon-wrapped dates with goat cheese | `/assets/images/gourmet-to-go/choice-bacon-dates.webp` | 200 | 800×800 | 60820 | `d20da693424ac4ba78b68023009a840b` | Bacon-wrapped dates filled with goat cheese. |
| Caprese skewers | `/assets/images/gourmet-to-go/choice-caprese.webp` | 200 | 800×800 | 46680 | `10fe20b968d986e74f98e85b34aabb71` | Tomato, mozzarella and basil skewers. |
| Antipasto skewers | `/assets/images/gourmet-to-go/choice-antipasto.webp` | 200 | 800×800 | 50508 | `3b1e9a33b2855d36f8b24816815cd231` | Salami, olive, cheese and pepper skewers. |
| Anchovy cream tartlets | `/assets/images/gourmet-to-go/choice-anchovy-tartlets-v2.webp` | 200 | 1254×1254 | 124722 | `6fe2e51f951a06d705889e7a4e3f2671` | Pale anchovy-cream tartlets with small anchovy and herb garnish. |
| Ham croquetas | `/assets/images/gourmet-to-go/choice-ham-croquetas-v2.webp` | 200 | 1254×1254 | 212714 | `203b050477de68b686e57f4f45a71567` | Golden breaded cylindrical ham croquetas. |
| Cheese tequeños | `/assets/images/gourmet-to-go/choice-tequenos-v2.webp` | 200 | 1254×1254 | 165368 | `8f8ee05419275916a09b905da9347ea4` | Golden dough-wrapped cheese sticks, one open with white cheese. |
| Mini cachitos | `/assets/images/gourmet-to-go/choice-cachitos-v2.webp` | 200 | 1254×1254 | 158104 | `57c0a2137377f03a2b1ec4ce522f2730` | Small glossy crescent-shaped ham rolls. |
| Mini croissant sandwiches — ham & cheese | `/assets/images/gourmet-to-go/choice-croissant-sandwiches-v2.webp` | 200 | 1254×1254 | 184432 | `d286b4db2ca0d73e66da867111a97c50` | Mini croissants visibly filled with ham and cheese. |
| Mini wraps — turkey & cheese | `/assets/images/gourmet-to-go/choice-mini-wraps-v2.webp` | 200 | 1254×1254 | 145472 | `a9631e557ad1140322d9baeb18d4ef63` | Turkey-and-cheese wraps cut into halves. |
| Chicken & avocado mini sliders | `/assets/images/gourmet-to-go/choice-slider-chicken-avocado-v3.webp` | 200 | 1254×1254 | 171980 | `e5d50ed14c44f4800d7f794952748c67` | Brioche sliders with chicken, avocado cream and lettuce. |
| Turkey & Swiss mini sliders | `/assets/images/gourmet-to-go/choice-slider-turkey-swiss-v3.webp` | 200 | 1254×1254 | 174584 | `aa545842335599285ad22efc127bb801` | Brioche sliders with turkey, Swiss, lettuce and tomato. |
| Breakfast brioche sliders, egg & avocado | `/assets/images/gourmet-to-go/choice-slider-breakfast-v3.webp` | 200 | 1254×1254 | 170662 | `f73262ee0d6f77dba2c904020f553464` | Brioche breakfast sliders with egg and avocado. |
| Yogurt & granola parfait cups with fresh fruit | `/assets/images/gourmet-to-go/choice-parfait-v3.webp` | 200 | 1254×1254 | 192056 | `e07bdc2988bdc014b0b3ecd8534d6d9c` | Clear cups layered with yogurt, granola, berries and pineapple. |
| Cream profiteroles | `/assets/images/gourmet-to-go/choice-profiteroles-v2.webp` | 200 | 1254×1254 | 147522 | `5a5b9499c12b21f2dea798e1b10e5e3e` | Cream-filled profiteroles with chocolate on top and no nuts. |
| Pastry cream & fresh fruit tartlets | `/assets/images/gourmet-to-go/choice-tartlet-fruit-v2.webp` | 200 | 1254×1254 | 169822 | `666875fff679efbdb7f6ff59d55dcaf7` | Pastry-cream tartlets topped only with fresh fruit. |
| Chocolate ganache tartlets | `/assets/images/gourmet-to-go/choice-tartlet-chocolate-v2.webp` | 200 | 1254×1254 | 131244 | `915da8bcd14ba3f313302efbdda18272` | Tartlets with glossy chocolate ganache. |
| Dulce de leche tartlets | `/assets/images/gourmet-to-go/choice-tartlet-dulce-de-leche-v2.webp` | 200 | 1254×1254 | 116102 | `f91756732d33e950627d1d97f1ff6bb9` | Tartlets filled only with dulce de leche. |
| Guava & cream cheese tartlets | `/assets/images/gourmet-to-go/choice-tartlet-guava-v2.webp` | 200 | 1254×1254 | 130570 | `49b9aa8977585b6e0517f7bbe62c03f5` | Tartlets with cream cheese and guava. |
| Key lime tartlets | `/assets/images/gourmet-to-go/choice-tartlet-key-lime-v2.webp` | 200 | 1254×1254 | 148498 | `44ed3c55e5e1a73101c113fb55515034` | Tartlets with pale-green key-lime filling. |

Regenerated after content inspection: cheese empanaditas, anchovy tartlets, ham croquetas, tequeños, cachitos, croissant sandwiches, mini wraps, all three sliders, parfait cups, profiteroles and all five tartlet choices (17 files). Versioned URLs prevent old browser/CDN objects from resurfacing.

## Redirect and removal proof

```text
https://vnvevents.com/product/ensaimada-family-recipe
HTTP/1.1 301 Moved Permanently
Location: /gourmet-to-go#desserts

https://vnvevents.com/product/cream-profiteroles
HTTP/1.1 301 Moved Permanently
Location: /product/dessert-box

https://vnvevents.com/product/pastry-cream-tartlets
HTTP/1.1 301 Moved Permanently
Location: /product/dessert-box

https://vnvevents.com/product/boca-abierta
HTTP/1.1 404 Not Found
```

`boca-abierta` is absent from `sitemap-store.xml`. Gourmet To Go meta keywords contain no `ensaimada`.

## Full logged-out sweep

| URL | HTTP | New header | Call | WhatsApp | Pause banner | Old drop-off/chafing copy | Old seasonal meta | Correct main image |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `/gourmet-to-go` | 200 | yes | (305) 204-5427 | 305-376-1210 | yes | absent | absent | yes |
| `/product/appetizer-box` | 200 | yes | (305) 204-5427 | 305-376-1210 | yes | absent | absent | yes |
| `/product/appetizer-box-breakfast-brunch` | 200 | yes | (305) 204-5427 | 305-376-1210 | yes | absent | absent | yes |
| `/product/dessert-box` | 200 | yes | (305) 204-5427 | 305-376-1210 | yes | absent | absent | yes |
| `/product/brazo-gitano` | 200 | yes | (305) 204-5427 | 305-376-1210 | yes | absent | absent | yes |

No separate CDN purge endpoint is configured in this project/account. The changed choice images use new versioned URLs, providing deterministic cache invalidation; the external no-cookie downloads returned all 27 current files and their MD5 hashes matched the committed assets exactly.

## Unresolved items

None in the requested scope.
