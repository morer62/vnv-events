# Growth Hub Public Rendering Report

## Context

This report documents the VNV Events public rendering issue found on the Growth Hub page:

```text
site_key = vnvevents
route = /corporate-catering-services/
content_id = 23
template_key = editorial-guide
```

The page existed in the shared Ophyra database and was published, but the VNV Events public site first returned a 404, then rendered only the title and a "no body content" fallback.

The root issue was not that the page was missing. It was a contract mismatch between Ophyra Growth Hub as the source and the public brand repositories as consumers.

## CMS Source Of Truth

Ophyra Growth Hub is the source of truth for public CMS content.

The active CMS inventory for a brand should come from:

```text
cms_contents
cms_routes
cms_categories
cms_templates
cms_content_blocks
cms_media
```

Legacy generator tables such as `cms_location_pages` and old AI draft review flows should not be counted as active CMS inventory. They may remain in the database for compatibility or migration history, but new VNV Events, Avomeal and Jonnys Media public pages should be created and published through the Ophyra CMS contract above.

This matters because a brand may appear to have fewer records in Ophyra than in a local CMS dashboard if the dashboard is still counting legacy location pages or AI-generated records. Public consumers should align their counts, navigation and publishing UI with Ophyra Growth Hub, not with legacy local generators.

## What Happened

The public route initially failed because the local fallback looked for:

```text
/corporate-catering-services/
```

while the database route was stored as:

```text
/corporate-catering-services
```

After the route was normalized, VNV Events rendered the local `cms-content` view. That view expected body content in:

```text
cms_contents.body_html
cms_contents.content_json
```

but the Growth Hub page stored its public HTML in:

```text
cms_contents.body
```

The page also had structured block data in:

```text
cms_content_blocks.data_json
```

So the page was found, but the renderer was reading the wrong content fields for a Growth Hub record.

## Current VNV Events Fix

VNV Events now supports the Growth Hub content shape in the local CMS fallback:

- It renders `cms_contents.body` when `body_html` is empty.
- It applies the linked template CSS from `cms_templates.css_text`.
- It keeps `body_html` and `content_json` support for older local CMS records.
- It uses the app route helper for local navigation links such as the breadcrumb home link, so local development paths like `http://localhost/vnv-events/` do not collapse to `http://localhost/`.

This makes the public page render instead of showing the empty-body fallback.

## Admin CMS Alignment

The local VNV Events admin should mirror the Ophyra Growth Hub model:

- The primary inventory is `Content`, not separate local modules for pages, blog posts and location pages.
- `cms_contents.type = page` is the active Growth Hub content inventory for VNV Events in this local consumer.
- `cms_contents.content_type` is the editorial/public content type filter, using the final Growth Hub set:

```text
page
location
blog
```

- `cms_categories` is shared taxonomy for that content.
- `cms_templates` is the shared template registry.
- Landing pages are no longer a separate `content_type`; they are `content_type = page` with a landing-oriented template such as `service-landing`.
- Products belong to Store/catalog, not Growth Hub CMS.
- Legacy `blog_categories`, `cms_location_pages` and AI draft review screens should not appear as separate active CMS modules.

In VNV Events, `/panel/cms/content` is an alias for the unified content inventory. `/panel/cms/pages` remains as a compatibility route because older local code and links already point there.

Admin "Open" links must also use the app route helper:

```twig
{{ path(item.main_route.route|trim('/')) }}
```

Without that, a stored route such as `/corporate-event-planner-miami/` opens as:

```text
http://localhost/corporate-event-planner-miami/
```

instead of:

```text
http://localhost/vnv-events/corporate-event-planner-miami/
```

That Apache 404 is not an Ophyra content problem. It is a consumer-side local base URL problem.

## Link And Base URL Handling

The tested page also exposed a smaller navigation issue: the breadcrumb Home link was hardcoded as:

```html
<a href="/">Home</a>
```

In local XAMPP development, VNV Events runs under:

```text
http://localhost/vnv-events/
```

So `href="/"` sent the browser to:

```text
http://localhost/
```

This was not coming from Ophyra. It was a public consumer rendering issue.

Public brand repositories should separate three URL concepts:

- App/local route base: used for navigation inside the current running app.
- Public canonical base: used for SEO canonical URLs, JSON-LD and sitemap output.
- Ophyra API base: used only to fetch Growth Hub content from Ophyra.

Example VNV Events local development config:

```text
APP_URL=http://localhost/vnv-events
PUBLIC_BASE_URL=https://vnvevents.com
OPHYRA_BASE_URL=https://ophyra.com
```

In Twig views, internal navigation should use the local route helper:

```twig
{{ path('') }}
{{ path('blog') }}
{{ path('locations') }}
```

Canonical URLs and JSON-LD should use the public brand domain:

```text
https://vnvevents.com
https://avomeal.com
https://jonnys.media
```

Do not hardcode `href="/"` in Growth Hub consumer views unless the site is guaranteed to run at the webserver root in every environment.

## Recommendations For Ophyra

### 1. Normalize The Public Publishing Contract

Ophyra should publish every Growth Hub page with a complete public contract, not only internal drafting data.

Minimum required fields:

```text
cms_contents.site_key
cms_contents.id_owner or id_user_business
cms_contents.content_type
cms_contents.type
cms_contents.title
cms_contents.slug
cms_contents.status = PUBLISHED
cms_contents.approval_status = APPROVED or PUBLISHED
cms_contents.body or body_html
cms_contents.meta_title
cms_contents.meta_description
cms_contents.canonical_url
cms_contents.robots
cms_contents.schema_json
cms_contents.id_template

cms_routes.site_key
cms_routes.id_content
cms_routes.route
cms_routes.status = ACTIVE
cms_routes.language

cms_templates.site_key
cms_templates.template_key
cms_templates.css_text
cms_templates.status = ACTIVE
```

Recommended final type/routing model:

```text
content_type = page      -> /{slug}/
content_type = location  -> /locations/{slug}/
content_type = blog      -> /blog/{slug}/
```

Templates describe the presentation or content shape:

```text
service-landing
local-location-page
editorial-guide
faq-resource
```

Old values should be normalized by consumers:

```text
landing, service, custom -> page
guide, faq_page, comparison, case_study -> blog
product -> Store/catalog, not CMS Growth Hub
category -> taxonomy, not publication
```

Ophyra should also expose categories and templates as first-class CMS records for the selected brand:

```text
cms_categories.site_key = active site key
cms_templates.site_key = active site key
```

Templates and categories should be filtered the same way as content. Do not show VNV Events categories/templates while editing Avomeal or Jonnys Media unless those records are explicitly shared. Legacy `blog_categories` rows may remain for old content, but they should not be presented as a separate active Growth Hub taxonomy.

Route normalization should be consistent everywhere. Recommended canonical storage:

```text
/slug/
/blog/slug/
/locations/slug/
```

If Ophyra stores routes without trailing slash, public consumers must support both variants. The better long-term fix is for Ophyra to normalize route storage at publish time.

### 2. Generate Public SEO Data, Not Only Research Metadata

The tested VNV Events page had:

```text
meta_title = NULL
meta_description = "corporate catering services focused on corporate catering services"
schema_json = []
metadata_json = internal research/planning payload
```

This is enough to render a page, but it is not enough for a production SEO page.

Ophyra should generate and store:

- Human-written `meta_title`.
- Human-written `meta_description`.
- Clean canonical URL for the target brand.
- Indexing rules in `robots`.
- Rich `schema_json` when available.
- FAQ schema when `cms_content_blocks` contains FAQ items.
- Featured image URL or media attachment for Open Graph.

`metadata_json` can keep internal research, prompts, GSC notes and generation history, but public consumers should not have to parse it to create basic SEO output.

## Public Consumer Contract

Avomeal, Jonnys Media and any future public brand site should consume Growth Hub pages using this order.

### Route Resolution

1. Read the active site key from env or config:

```text
OPHYRA_GROWTH_SITE_KEY=avomeal
OPHYRA_GROWTH_SITE_KEY=jonnysmedia
```

2. Resolve the request path against `cms_routes`.
3. Match both trailing-slash variants until all historical routes are normalized.
4. Require:

```text
cms_routes.site_key = active site key
cms_routes.status = ACTIVE
cms_contents.site_key = active site key
cms_contents.status = PUBLISHED
cms_contents.approval_status IN (APPROVED, PUBLISHED)
```

5. Do not show records by `id_owner` alone. Owner scope is operational. Public brand scope is `site_key`.

### Content Rendering

Consumers should support all current Growth Hub body shapes:

```text
1. cms_contents.body_html
2. cms_contents.body
3. cms_contents.content_json
4. cms_content_blocks.data_json
```

Recommended rendering priority:

1. Render `body_html` if present.
2. Else render `body` if present.
3. Else render `content_json` hero/blocks if present.
4. Else render `cms_content_blocks` ordered by `sort_order`.
5. Else return a clear draft/incomplete message only in admin preview, not public production.

### Template Rendering

Consumers should join the selected template:

```text
cms_contents.id_template -> cms_templates.id
```

Then apply:

```text
cms_templates.template_key
cms_templates.css_text
cms_templates.preview_html only for preview, not as the only public body source
```

The public page should add a template class such as:

```html
<div class="cms-preview-template-editorial-guide">
```

and include the template CSS once on the page.

### SEO Rendering

Consumers should build public SEO from stored fields first:

```text
meta_title
meta_description
canonical_url
robots
schema_json
featured_image_url
```

If `schema_json` is empty, the consumer can generate a fallback graph:

- Organization
- WebSite
- WebPage
- BreadcrumbList
- Service, Article or WebPage depending on content type and template

If `cms_content_blocks` has a `faq` block, generate `FAQPage` schema from its `items`.

## Brief For Avomeal And Jonnys Media

Use this implementation brief when updating Avomeal or Jonnys Media.

```text
Implement Growth Hub public page consumption for this brand.

This site shares the Ophyra database. Do not read public CMS pages by owner alone. Use the brand site key:

site_key = avomeal
or
site_key = jonnysmedia

Routes come from cms_routes and content comes from cms_contents.

Required filters:
- cms_routes.site_key = active site_key
- cms_routes.status = ACTIVE
- cms_contents.site_key = active site_key
- cms_contents.status = PUBLISHED
- cms_contents.approval_status is APPROVED or PUBLISHED when the column exists

Route matching must support both /slug and /slug/ until all legacy rows are normalized.

Rendering must support these Growth Hub fields:
- cms_contents.body_html
- cms_contents.body
- cms_contents.content_json
- cms_content_blocks.data_json ordered by sort_order

Templates:
- Join cms_templates through cms_contents.id_template.
- Apply cms_templates.css_text.
- Add a wrapper class using cms_templates.template_key.

SEO:
- Use meta_title, meta_description, canonical_url, robots and featured_image_url when present.
- Render schema_json when present.
- If schema_json is empty, generate fallback JSON-LD: Organization, WebSite, WebPage, BreadcrumbList and the relevant entity type.
- If a cms_content_blocks FAQ block exists, generate FAQPage schema from its items.

Navigation and URLs:
- Use the local app route helper for in-page navigation links and breadcrumbs.
- Use the public domain only for canonical, sitemap, Open Graph URL and JSON-LD URL fields.
- Do not hardcode href="/" because local/dev apps may run under a subfolder.

Never render VNV Events content in Avomeal, never render Avomeal content in Jonnys Media, and never treat id_owner = 2 as automatic permission to show a row publicly.
```

## Verification Checklist

For each brand repo, test at least one Growth Hub page and confirm:

- The route returns `200`, not `404`.
- The rendered page contains body content, not only title.
- Template CSS is present.
- The page uses the correct public brand domain in canonical URLs.
- Breadcrumb and in-page navigation use the current app base URL in local/dev.
- The page does not show another brand's content.
- JSON-LD exists.
- FAQ schema appears when the content has FAQ blocks.
- Open Graph title, description and image are populated.

## VNV Events Reference Result

The tested VNV Events URL now renders successfully:

```text
http://localhost/vnv-events/corporate-catering-services/
```

The page currently has fallback-generated JSON-LD but weak stored metadata. This confirms the consumer-side renderer is working, while Ophyra still needs to improve the public SEO publishing contract.
