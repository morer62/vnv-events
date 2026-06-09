# Ophyra CMS Route Contract Review - 2026-06-09

## Purpose

This document records the routing review performed after VNV Events returned 404 for Ophyra-created public URLs such as:

```text
/blog/how-to-choose-the-perfect-wedding-dj-for-your-celebration/
/locations/catering-services-in-weston-fl-usa-what-to-know-before-you-book/
```

It defines the contract that Ophyra, VNV Events, Avomeal, Jonnys Media and future agents must follow when publishing Growth Hub CMS content.

## Hard Rule

Existing pages have priority.

Ophyra, VNV Events, Avomeal, Jonnys Media and future agents must not delete, overwrite, silently replace or redirect existing public pages when creating new Growth Hub content.

If a route already exists, the new content must be sent to review, assigned a different slug, or intentionally mapped by a human.

## Finding

Ophyra created valid records in the SQL dump:

```text
cms_routes.route = /blog/how-to-choose-the-perfect-wedding-dj-for-your-celebration/
cms_routes.route_type = blog
cms_contents.content_type = blog
cms_contents.type = ''
cms_contents.status = PUBLISHED
cms_contents.approval_status = APPROVED
cms_contents.site_key = vnvevents
```

```text
cms_routes.route = /locations/catering-services-in-weston-fl-usa-what-to-know-before-you-book/
cms_routes.route_type = location
cms_contents.content_type = location
cms_contents.type = ''
cms_contents.status = PUBLISHED
cms_contents.approval_status = APPROVED
cms_contents.site_key = vnvevents
```

The 404 was caused by a compatibility mismatch in VNV Events. The public router expected legacy values in `cms_contents.type`:

```text
page
post
```

Ophyra is now publishing the newer Growth Hub shape:

```text
cms_contents.content_type = page | location | blog
cms_routes.route_type = page | location | blog
```

VNV Events has been adjusted to accept the new contract while keeping compatibility with older records.

## Changes Made In VNV Events

VNV Events now resolves public CMS routes using this priority:

```text
cms_contents.content_type
cms_routes.route_type
cms_contents.type
fallback page
```

The following files were adjusted:

```text
src/Kernel.php
src/Repositories/CmsRoutesRepository.php
src/views/public/pages/blog-post/index.php
src/views/public/pages/cms-content/index.php
```

Confirmed local result:

```text
GET /blog/how-to-choose-the-perfect-wedding-dj-for-your-celebration/ -> 200
GET /locations/catering-services-in-weston-fl-usa-what-to-know-before-you-book/ -> 200
```

VNV also already includes support for category routes:

```text
/category/blog/{slug}/
/category/page/{slug}/
/category/location/{slug}/
```

Admin category screens now show clickable public category paths.

## Current Public Route Priority

The route resolver must preserve this priority:

```text
1. Existing physical/static repository pages
2. Existing local CMS routes and pages
3. Published Ophyra Growth Hub routes for the active site_key
4. Category and product dynamic routes
5. 404
```

This protects pages that were manually created or already published before Growth Hub generated new content.

## Sitemap Role

The sitemap is useful, but it is not the source of truth for collision protection.

The sitemap can miss:

```text
noindex pages
draft or pending pages
admin-created routes not yet indexed
physical routes that are intentionally not in sitemap
redirect-only routes
reserved application routes
routes omitted because of a sitemap bug
```

Ophyra may use the sitemap as a secondary signal, but it must check the route inventory first.

## Required Ophyra Route Check

Before creating or publishing any public route, Ophyra should call a route availability check in the target site or use the equivalent SQL in its own database.

Recommended endpoint:

```text
GET /api/growth-hub/route-check?site_key=vnvevents&route=/blog/example/
```

Recommended response:

```json
{
  "available": false,
  "reason": "existing_cms_route",
  "matched_route": "/blog/example/",
  "site_key": "vnvevents",
  "priority": "protected_existing_page"
}
```

If `available = false`, Ophyra must not publish over that route.

## Route Check Sources

The route check must evaluate all relevant public route sources, not only `cms_routes`.

Minimum sources:

```text
physical public views in the repository
reserved router prefixes
cms_routes.route
cms_contents.slug with generated route
cms_location_pages.slug
blog_categories.slug category URLs
cms_categories.slug category URLs
store product/category routes
redirect routes
sitemap URLs as secondary confirmation
HTTP 200/301/302 probe as final confirmation
```

Reserved prefixes include at least:

```text
panel
api
login
logout
signup
register
blog
locations
category
product
product-category
store
forums
forum
pages
content
auth
commerce
system
app
r
```

## SQL For Diagnosis

Use this query to inspect a route and its content type contract:

```sql
SELECT
  r.id AS route_id,
  r.route,
  r.route_type,
  r.status AS route_status,
  r.site_key AS route_site_key,
  c.id AS content_id,
  c.title,
  c.slug,
  c.type,
  c.content_type,
  c.status AS content_status,
  c.approval_status,
  c.language,
  c.site_key AS content_site_key
FROM cms_routes r
JOIN cms_contents c ON c.id = r.id_content
WHERE r.site_key = 'vnvevents'
  AND r.route IN (
    '/blog/how-to-choose-the-perfect-wedding-dj-for-your-celebration/',
    '/locations/catering-services-in-weston-fl-usa-what-to-know-before-you-book/'
  );
```

Use this query before publishing a new route:

```sql
SELECT
  r.id,
  r.route,
  r.route_type,
  r.status,
  r.site_key,
  c.id AS content_id,
  c.title,
  c.status AS content_status,
  c.approval_status
FROM cms_routes r
LEFT JOIN cms_contents c ON c.id = r.id_content
WHERE r.site_key = :site_key
  AND r.route = :route
LIMIT 1;
```

Use this query to catch legacy location-page collisions:

```sql
SELECT
  id,
  slug,
  title,
  status,
  site_key
FROM cms_location_pages
WHERE site_key = :site_key
  AND slug = :slug
LIMIT 1;
```

## Recommended Ophyra SQL Adjustments

Ophyra should keep the new Growth Hub model:

```text
cms_contents.content_type = page | location | blog
cms_routes.route_type = page | location | blog
```

For compatibility with older consumers, Ophyra should also backfill `cms_contents.type` when it is empty.

Recommended compatibility migration:

```sql
UPDATE cms_contents
SET type = CASE
  WHEN content_type = 'blog' THEN 'post'
  WHEN content_type IN ('page', 'location') THEN 'page'
  ELSE type
END
WHERE (type IS NULL OR type = '')
  AND content_type IN ('page', 'location', 'blog');
```

Recommended route type backfill:

```sql
UPDATE cms_routes r
JOIN cms_contents c ON c.id = r.id_content
SET r.route_type = CASE
  WHEN c.content_type = 'blog' THEN 'blog'
  WHEN c.content_type = 'location' THEN 'location'
  ELSE 'page'
END
WHERE (r.route_type IS NULL OR r.route_type = '')
  AND c.content_type IN ('page', 'location', 'blog');
```

Recommended uniqueness protection:

```sql
ALTER TABLE cms_routes
  ADD UNIQUE KEY uq_cms_routes_site_route_language (site_key, route, language);
```

Before adding the unique key, Ophyra must audit duplicates:

```sql
SELECT
  site_key,
  route,
  language,
  COUNT(*) AS total
FROM cms_routes
GROUP BY site_key, route, language
HAVING total > 1;
```

Recommended indexes:

```sql
CREATE INDEX idx_cms_contents_public_lookup
  ON cms_contents (site_key, content_type, status, approval_status, language, slug);

CREATE INDEX idx_cms_routes_public_lookup
  ON cms_routes (site_key, route, language, status, route_type);
```

Adjust index syntax for the active MySQL/MariaDB version and skip indexes that already exist.

Recommended category-image column for CMS categories:

```sql
ALTER TABLE cms_categories
  ADD COLUMN featured_image_url VARCHAR(500) NULL AFTER description;
```

If the target database does not already have category images for blog categories, add the same field there too:

```sql
ALTER TABLE blog_categories
  ADD COLUMN featured_image_url VARCHAR(500) NULL AFTER description;
```

Optional alt text column:

```sql
ALTER TABLE cms_categories
  ADD COLUMN featured_image_alt VARCHAR(255) NULL AFTER featured_image_url;
```

```sql
ALTER TABLE blog_categories
  ADD COLUMN featured_image_alt VARCHAR(255) NULL AFTER featured_image_url;
```

Recommended image audit:

```sql
SELECT
  id,
  site_key,
  name,
  slug
FROM cms_categories
WHERE site_key IN ('vnvevents', 'avomeal', 'jonnysmedia')
  AND (featured_image_url IS NULL OR featured_image_url = '')
ORDER BY site_key, name;
```

```sql
SELECT
  id,
  site_key,
  name,
  slug
FROM blog_categories
WHERE site_key IN ('vnvevents', 'avomeal', 'jonnysmedia')
  AND (featured_image_url IS NULL OR featured_image_url = '')
ORDER BY site_key, name;
```

```sql
SELECT
  id,
  site_key,
  content_type,
  title,
  slug
FROM cms_contents
WHERE site_key IN ('vnvevents', 'avomeal', 'jonnysmedia')
  AND status = 'PUBLISHED'
  AND content_type IN ('page', 'location', 'blog')
  AND (featured_image_url IS NULL OR featured_image_url = '')
ORDER BY site_key, content_type, title;
```

## Required Ophyra API Fix

The remote Growth Hub API must expose published records for the target `site_key`.

During this review, these calls returned empty results:

```text
GET https://ophyra.com/api/growth-hub/routes?site_key=vnvevents
GET https://ophyra.com/api/growth-hub/content?site_key=vnvevents&route=/blog/how-to-choose-the-perfect-wedding-dj-for-your-celebration/
GET https://ophyra.com/api/growth-hub/content?site_key=vnvevents&route=/locations/catering-services-in-weston-fl-usa-what-to-know-before-you-book/
```

Ophyra should return records when:

```text
cms_contents.site_key = requested site_key
cms_routes.site_key = requested site_key
cms_contents.status = PUBLISHED
cms_contents.approval_status IN (APPROVED, PUBLISHED)
cms_routes.status = ACTIVE
```

The content endpoint should support route matching both with and without trailing slash.

The route list endpoint should return active public routes for the requested site key.

## List Pages, Images And Filters

The public list pages must be treated as real editorial indexes, not only route dumps.

Required list pages:

```text
/blog/
/locations/
/category/blog/{slug}/
/category/page/{slug}/
/category/location/{slug}/
```

Each list page should support:

```text
search by title, excerpt, location and category
category filters
cards with a featured image
category label under the image
read-more link
fallback reference image when content or category image is missing
```

VNV Events now uses:

```text
public/assets/images/cms-image-needed.svg
```

as the reference placeholder when a record has no usable image. Seeing this image in public means the Ophyra record needs a real featured image.

Category result pages use the category featured image in the hero and as a card fallback when an individual content item has no image.

## Image Contract For Ophyra

Ophyra should send image fields in both content and category payloads.

For content cards:

```text
cms_contents.featured_image_url
cms_contents.featured_image_alt or image_alt_text when available
media[0].secure_url as fallback
seo.og_image as fallback
```

For categories:

```text
blog_categories.featured_image_url
cms_categories.featured_image_url
```

For API list responses, Ophyra should include at least:

```json
{
  "id": 29,
  "site_key": "vnvevents",
  "content_type": "blog",
  "title": "How to Choose the Perfect Wedding DJ for Your Celebration",
  "slug": "how-to-choose-the-perfect-wedding-dj-for-your-celebration",
  "route": "/blog/how-to-choose-the-perfect-wedding-dj-for-your-celebration/",
  "excerpt": "Navigating the wedding DJ selection process can be overwhelming.",
  "featured_image_url": "https://res.cloudinary.com/.../image.jpg",
  "category_name": "Wedding Planning",
  "category_slug": "wedding-planning",
  "category_image_url": "https://res.cloudinary.com/.../category.jpg"
}
```

## Breadcrumb Contract

Ophyra-generated schema and visual breadcrumbs must include the parent index route.

Blog detail pages:

```text
Home -> Blog -> {Category} -> {Article}
```

Location detail pages:

```text
Home -> Locations -> {Category or City} -> {Location Page}
```

Category result pages:

```text
Home -> Categories -> {Type Label} -> {Category}
```

The parent URLs must use the public brand domain:

```text
https://vnvevents.com/blog/
https://vnvevents.com/locations/
https://vnvevents.com/category/blog/{category-slug}/
https://vnvevents.com/category/location/{category-slug}/
```

Never emit local development URLs such as:

```text
http://localhost/vnv-events/...
```

inside production canonical URLs, schema JSON or breadcrumbs.

## Brand Contract

Every brand must use its own `site_key`.

```text
VNV Events    -> vnvevents
Avomeal       -> avomeal
Jonnys Media  -> jonnysmedia
```

Each brand consumer must set:

```text
SITE_KEY={brand_site_key}
OPHYRA_GROWTH_SITE_KEY={brand_site_key}
PUBLIC_BASE_URL=https://{brand_domain}
SITE_PUBLIC_BASE_URL=https://{brand_domain}
```

VNV Events must never render Avomeal or Jonnys Media content.

Avomeal must never render VNV Events or Jonnys Media content.

Jonnys Media must never render VNV Events or Avomeal content.

Shared records must be explicitly marked as shared using documented scopes:

```text
shared
global
all_sites
```

## Avomeal Application

Avomeal should adopt the same CMS contract, but with food and meal-prep brand data.

Expected routes:

```text
page      -> /{slug}/
location  -> /locations/{slug}/
blog      -> /blog/{slug}/
category  -> /category/{page|location|blog}/{slug}/
```

Avomeal must protect existing store/catalog routes:

```text
/store/
/product/{slug}/
/product-category/{slug}/
/checkout/
/cart/
/subscriptions/
```

Growth Hub CMS must not create products or product categories. Those belong to Store/catalog.

## Jonnys Media Application

Jonnys Media should use the same CMS contract with:

```text
site_key = jonnysmedia
```

Expected content examples:

```text
page      -> /brand-identity-services/
location  -> /locations/video-production-miami/
blog      -> /blog/how-to-plan-a-brand-shoot/
category  -> /category/blog/production-guides/
```

Jonnys Media must protect portfolio, service, contact and brand landing pages that already exist.

## Guidance For Future Agents

When debugging public CMS 404s, check the route contract before changing templates or deleting data.

Use this order:

```text
1. Confirm requested URL and normalized route.
2. Check physical public views.
3. Check cms_routes by exact route and site_key.
4. Join cms_contents and inspect type, content_type, status and approval_status.
5. Check legacy cms_location_pages if the route is under /locations/.
6. Check Ophyra API response for the same site_key and route.
7. Check sitemap only as secondary evidence.
8. Preserve existing pages and add compatibility only where needed.
```

Do not assume a 404 means the page does not exist.

Do not assume sitemap absence means the route is available.

Do not overwrite `cms_contents.type` blindly. If Ophyra is using `content_type`, keep it as the canonical Growth Hub field and backfill `type` only for compatibility.

## Publishing Checklist

Before Ophyra marks a page public:

```text
site_key is correct
content_type is page, location or blog
route_type matches content_type
route follows the content type rule
route does not collide with protected existing pages
status is PUBLISHED
approval_status is APPROVED or PUBLISHED
canonical_url uses the public brand domain
schema_json uses the public brand domain
API returns the content by route
API route list includes the route
sitemap includes the route only when it is indexable
content has featured_image_url or an intentionally accepted placeholder state
category has featured_image_url for public filters and category result pages
breadcrumbs include /blog/ or /locations/ parent pages when applicable
```

## Related Documentation

```text
docs/GROWTH_HUB_PUBLIC_CONSUMPTION.md
docs/GROWTH_HUB_CMS_REPLICATION_GUIDE.md
docs/OPHYRA_GROWTH_HUB_TEMPLATE_INTEGRATION.md
docs/SITE_SCOPE_MODEL.md
docs/SITE_SCOPE_AND_VISIBILITY.md
docs/AVOMEAL_OPHYRA_INTEGRATION_MODEL.md
docs/JONNYS_MEDIA_PROJECT_CONTEXT.md
```
