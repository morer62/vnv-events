# Growth Hub CMS Replication Guide

## Purpose

Use this guide to replicate the VNV Events `/panel/cms/` admin surface in Avomeal, Jonnys Media and future brand repositories.

The goal is not to create a new CMS per brand. Each brand should expose a local admin/consumer surface for the same Ophyra Growth Hub CMS contract.

For the route-protection contract, API expectations and SQL compatibility rules discovered during the 2026-06-09 review, see:

```text
docs/OPHYRA_CMS_ROUTE_CONTRACT_REVIEW_2026_06_09.md
```

## Source Of Truth

Ophyra Growth Hub is the source of truth.

Active CMS inventory must come from:

```text
cms_contents
cms_routes
cms_categories
cms_templates
cms_content_blocks
cms_media
```

Do not treat these legacy tables as active CMS modules:

```text
cms_location_pages
blog_categories
ai_content_drafts
old AI review/location/blog generators
```

Those legacy tables can remain for historical compatibility, but they should not appear as primary cards in `/panel/cms/`.

## Brand Scope

Every brand repository must use its own `site_key`.

```text
VNV Events    -> vnvevents
Avomeal       -> avomeal
Jonnys Media  -> jonnysmedia
```

Public and admin CMS queries must filter by `site_key`. Do not show records by `id_owner` alone.

`id_owner` is business ownership scope. `site_key` is brand/public content scope.

## Environment

Each brand repository should define:

```text
APP_URL=http://localhost/{local-folder}
PUBLIC_BASE_URL=https://{public-domain}
OPHYRA_BASE_URL=https://ophyra.com
OPHYRA_GROWTH_SITE_KEY={brand-site-key}
SITE_KEY={brand-site-key}
SITE_NAME={Brand Name}
SITE_PUBLIC_BASE_URL=https://{public-domain}
```

Examples:

```text
APP_URL=http://localhost/avomeal
PUBLIC_BASE_URL=https://avomeal.com
OPHYRA_GROWTH_SITE_KEY=avomeal
SITE_KEY=avomeal
SITE_NAME=Avomeal
```

```text
APP_URL=http://localhost/jonnys-media
PUBLIC_BASE_URL=https://jonnys.media
OPHYRA_GROWTH_SITE_KEY=jonnysmedia
SITE_KEY=jonnysmedia
SITE_NAME=Jonnys Media
```

## Final Content Model

Ophyra simplified the public CMS type model.

Allowed `cms_contents.content_type` values:

```text
page
location
blog
```

Routing rules:

```text
page      -> /{slug}/
location  -> /locations/{slug}/
blog      -> /blog/{slug}/
```

Landing pages are not a content type. A landing page is:

```text
content_type = page
template_key = service-landing
route = /{slug}/
```

Products are not Growth Hub CMS content. Products belong to Store/catalog.

## Template Model

Templates describe layout/presentation, not publication type.

Recommended template keys:

```text
service-landing
local-location-page
editorial-guide
faq-resource
```

Template `type` should also stay simple:

```text
page
location
blog
```

Legacy template/content type aliases should normalize this way:

```text
landing, service, custom -> page
guide, faq_page, comparison, case_study -> blog
post -> blog
product -> Store/catalog, not CMS Growth Hub
category -> cms_categories taxonomy, not publication
```

## Admin Navigation

The local `/panel/cms/` dashboard should expose only:

```text
Content
Categories
Templates
SEO Center
```

Do not expose active cards for:

```text
Blog Posts
Blog Categories
Location Pages
AI Content Review
```

Those routes may redirect for compatibility, but they should not be part of the active CMS workflow.

## Content Inventory

The Content screen should mirror Ophyra Growth Hub inventory:

Required filters:

```text
status
content_type
search
```

The `content_type` dropdown should contain only:

```text
Page
Location
Blog
```

Recommended columns:

```text
Content
Type
Template
SEO Target
Status
Public URL
Activity
Actions
```

The "Open" action must use the app route helper, not a raw route:

```twig
{{ path(item.main_route.route|trim('/')) }}
```

This prevents local dev URLs from opening at the webserver root.

Correct:

```text
http://localhost/avomeal/blog/example/
http://localhost/jonnys-media/locations/example/
```

Wrong:

```text
http://localhost/blog/example/
http://localhost/locations/example/
```

## Create/Edit Content

The create/edit form must include:

```text
Content Type: page, location, blog
Category: cms_categories
Template: cms_templates
Status
Title
Slug
Public Route
Body / content fields
SEO fields
Schema JSON
Featured image
```

If Public Route is empty, generate it from `content_type` and `slug`:

```text
page      + wedding-planner-services      -> /wedding-planner-services/
location  + wedding-planner-doral         -> /locations/wedding-planner-doral/
blog      + how-to-plan-corporate-event   -> /blog/how-to-plan-corporate-event/
```

Example records:

```text
Wedding Planner Services
content_type: page
category: Weddings
template_key: service-landing
route: /wedding-planner-services/
```

```text
Wedding Planner in Doral
content_type: location
category: Weddings
template_key: local-location-page
route: /locations/wedding-planner-doral/
```

```text
How to Plan a Corporate Event
content_type: blog
category: Corporate Events
template_key: editorial-guide
route: /blog/how-to-plan-a-corporate-event/
```

## Public Rendering

Public consumers must resolve by route first:

```text
cms_routes.site_key = active site_key
cms_routes.status = ACTIVE
cms_contents.site_key = active site_key
cms_contents.status = PUBLISHED
cms_contents.approval_status IN (APPROVED, PUBLISHED) when present
```

Existing public pages always have priority. A Growth Hub publisher must check route availability before creating a route and must not overwrite physical pages, existing CMS routes, store/product routes, legacy location pages or reserved application routes.

Content rendering priority:

```text
1. cms_contents.body_html
2. cms_contents.body
3. cms_contents.content_json
4. cms_content_blocks.data_json ordered by sort_order
```

Template rendering:

```text
cms_contents.id_template -> cms_templates.id
apply cms_templates.css_text
use cms_templates.template_key as wrapper class
```

SEO rendering:

```text
meta_title
meta_description
canonical_url
robots
schema_json
featured_image_url
```

If `schema_json` is empty, generate fallback JSON-LD appropriate to the normalized content type and template.

## Files To Replicate From VNV Events

Use VNV Events as the reference implementation for the local admin surface.

Core admin area:

```text
src/views/panel/level1/cms/index.php
src/views/panel/level1/cms/index.twig
src/views/panel/level1/cms/pages/index.php
src/views/panel/level1/cms/pages/index.twig
src/views/panel/level1/cms/pages/create/index.php
src/views/panel/level1/cms/pages/create/index.twig
src/views/panel/level1/cms/pages/edit/index.php
src/views/panel/level1/cms/pages/edit/index.twig
src/views/panel/level1/cms/pages/categories/
src/views/panel/level1/cms/templates/
```

Compatibility redirects:

```text
src/views/panel/level1/cms/categories/
src/views/panel/level1/cms/content/
src/views/panel/level1/cms/blog/blog-posts/
src/views/panel/level1/cms/blog/blog-categories/
```

Public rendering references:

```text
src/views/public/pages/cms-content/
src/views/public/pages/growth-content/
src/Services/OphyraGrowthHubClient.php
src/Kernel.php
```

Shared helpers/repositories to compare:

```text
src/Utils/SiteContext.php
src/Utils/LocationUtils.php
src/Repositories/CmsContentsRepository.php
src/Repositories/CmsRoutesRepository.php
src/Repositories/CmsCategoriesRepository.php
src/Repositories/CmsTemplatesRepository.php
```

## Brand-Specific Changes

When copying to Avomeal or Jonnys Media, update:

```text
SITE_KEY
SITE_NAME
PUBLIC_BASE_URL
OPHYRA_GROWTH_SITE_KEY
dashboard labels
default internal links
default public SEO organization data
brand-specific navigation
```

Do not copy VNV Events text, categories or templates unless the records are shared or intentionally recreated for the target brand.

## QA Checklist

Admin:

- `/panel/cms/` shows Content, Categories, Templates and SEO Center only.
- Content filter has only Page, Location and Blog.
- Templates filter/type has only Page, Location and Blog.
- Legacy Blog Posts, Blog Categories and Location Pages are not active CMS cards.
- Categories read from `cms_categories`, not `blog_categories`.
- Templates read from `cms_templates` scoped to the active `site_key`.
- Content counts match Ophyra for the same brand/site key.

Create/edit:

- Page without manual route generates `/{slug}/`.
- Location without manual route generates `/locations/{slug}/`.
- Blog without manual route generates `/blog/{slug}/`.
- `service-landing` creates a page, not a `landing` content type.
- Products cannot be created through Growth Hub CMS.

Public:

- Published Page route returns 200.
- Published Location route returns 200.
- Published Blog route returns 200.
- Draft content does not render publicly unless using preview/admin flow.
- Open buttons use local app base in development.
- Canonical URLs use the public brand domain.
- No other brand content appears.

SEO:

- Meta title and description render.
- Canonical URL renders.
- JSON-LD renders from stored schema or fallback.
- Template CSS is applied.

## Implementation Prompt

Use this prompt for Avomeal or Jonnys Media:

```text
Replicate the VNV Events /panel/cms/ Growth Hub admin surface in this brand repository.

Use Ophyra Growth Hub as the source of truth. Do not create a separate CMS.

Use this brand site key:
{site_key}

The active CMS tables are cms_contents, cms_routes, cms_categories, cms_templates, cms_content_blocks and cms_media.

The only active content types are:
- page
- location
- blog

Landing pages are content_type=page with template_key=service-landing.
Products belong to Store/catalog, not Growth Hub CMS.

The admin dashboard should show only:
- Content
- Categories
- Templates
- SEO Center

Legacy Blog Posts, Blog Categories, Location Pages and AI Review screens should be hidden from the active CMS dashboard or redirected for compatibility.

All CMS queries must be scoped by site_key, not id_owner alone.

Public Open links must use the app route helper so local development works under the project folder.

After implementation, verify the QA checklist in docs/GROWTH_HUB_CMS_REPLICATION_GUIDE.md.
```
