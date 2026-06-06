# Ophyra Growth Hub Template Integration

## Purpose

Ophyra Base should be the source where multi-brand CMS content is created, reviewed and routed. VNV Events, Avomeal, Jonnys Media and future brands should consume only the records assigned to their own `site_key`.

The goal is not only to share articles or pages. The goal is to share a repeatable publishing model:

- Brand-scoped categories
- Brand-scoped templates
- Pages generated from those templates
- Public routes scoped to the active brand
- Clear origin and author metadata

## Brand Scope

Every content record that can become public must carry a site identity:

```text
site_key = vnvevents | avomeal | jonnysmedia | ...
origin_site_key = same brand that owns the public destination
content_origin = ophyra_growth_hub | vnv_events | avomeal | jonnysmedia
```

VNV Events must never render Avomeal or Jonnys Media content. Avomeal must never render VNV Events content. Ophyra can manage all of it, but public consumers must filter by `site_key`.

## Template Direction

Templates should be treated as reusable style/layout systems, not as one-off pages.

Recommended model:

- A template belongs to one brand or is explicitly shared.
- A template stores layout/style intent, block schema and optional CSS/JS.
- A page references a template when it is created.
- The generated page becomes a concrete `.twig` view or Twig-rendered CMS payload.
- The page stores its own content, metadata and route after generation.

This lets Ophyra duplicate a successful page structure without duplicating brand-unsafe content.

## Tables

Recommended table responsibilities:

### `cms_templates`

Stores reusable templates.

Important fields:

- `site_key`: owner brand for the template.
- `template_key`: stable machine key.
- `name`: human label.
- `type`: page, blog, location, landing, custom.
- `preview_html`: optional preview.
- `template_structure_json`: block schema, slots, style tokens.
- `status`: ACTIVE or INACTIVE.

Future improvement: add `is_shared` and `allowed_site_keys` if Ophyra needs cross-brand template reuse.

### `cms_categories` and `blog_categories`

Categories must also be brand-scoped:

```text
site_key = vnvevents
```

This avoids one brand editing or publishing into another brand's navigation/category tree.

### `cms_contents`

Stores generated pages/posts.

Important local VNV fields:

- `site_key`
- `id_template`
- `id_blog_category`
- `type`
- `title`
- `slug`
- `language`
- `content_json`
- `body_html`
- `meta_title`
- `meta_description`
- `robots`
- `schema_json`
- `featured_image_url`
- `status`
- `content_origin`
- `created_by`
- `updated_by`

### `cms_routes`

Stores the public URL for the generated page.

Important fields:

- `site_key`
- `id_content`
- `route`
- `is_main`
- `language`
- `status`
- `redirect_to`

## Page Generation Flow

1. User chooses a brand workspace in Ophyra, for example `vnvevents`.
2. User chooses a content type: page, blog, location or landing.
3. Ophyra shows only categories and templates where `site_key = vnvevents` or explicitly shared templates.
4. User selects a template.
5. Ophyra creates a content record in `cms_contents`.
6. Ophyra creates or updates a route in `cms_routes`.
7. If file generation is enabled, Ophyra exports a `.twig` page using the selected template styles and content blocks.
8. VNV Events consumes only the generated VNV Events route/content.

## Duplicate Page Strategy

Duplicating existing pages is a valid and useful workflow, but duplication must be scoped.

When duplicating a page:

- Keep the same `site_key` unless the user explicitly changes brand.
- Generate a new slug.
- Keep `id_template` so the new page remains tied to the original style system.
- Copy content blocks into the new page record.
- Reset approval/publication fields to draft.
- Preserve `origin_metadata_json` with source page id and source site key.

Suggested metadata:

```json
{
  "duplicated_from_content_id": 123,
  "duplicated_from_site_key": "vnvevents",
  "duplicated_at": "2026-06-06T00:00:00-04:00"
}
```

## Template Style Extraction

The preferred future direction is:

- Template controls style groups and layout slots.
- Page controls real content.
- Generated `.twig` embeds or imports the selected template style group.

Example conceptual shape:

```twig
{% extends 'templates/base.cms.twig' %}
{% set template_key = cms_page.template_key %}

{% block style %}
  {{ cms_template.styles|raw }}
{% endblock %}

{% block body %}
  {{ cms_page.body_html|raw }}
{% endblock %}
```

If Ophyra exports physical `.twig` files, the export should still include origin metadata in the database so VNV Events can regenerate sitemaps and know who created or updated the page.

## Avoiding Broken Integrations

Do not assume every VNV Events database has every Ophyra table already. SQL files in this repo should be standalone where possible:

- Create missing tables with `CREATE TABLE IF NOT EXISTS`.
- Add columns with `ADD COLUMN IF NOT EXISTS`.
- Never drop or rename Ophyra columns from VNV Events.
- Never remove Growth Hub columns such as `content_type`, `body`, `metadata_json`.
- Add VNV local columns alongside Ophyra columns when needed.

This is why `db/20260606_vnv_events_cms_origin_metadata.sql` creates local CMS compatibility columns while preserving Ophyra Growth Hub columns.

## SQL Order For Manual Import

When importing manually through phpMyAdmin, use this order:

1. `db/20260606_ophyra_growth_hub_level1_required.sql`
2. `db/20260606_vnv_events_cms_origin_metadata.sql`
3. `db/20260606_seo_files_logs_required.sql`
4. `db/20260606_event_requests_required.sql`
5. `db/20260606_user_workspace_preferences.sql`
6. `db/20260606_orders_menu_closure_alignment.sql`

The first file creates Ophyra Growth Hub base tables. The second file makes VNV Events local CMS compatible and brand-scoped.

## Current VNV Events Rules

- CMS panel lives in `panel/cms`.
- CMS, SEO Center and multimedia are resolved through Level 1.
- Level 6 views were removed; fallback routing sends missing panel views to Level 1.
- Public content from Ophyra must match `OPHYRA_GROWTH_SITE_KEY=vnvevents`.
- Sitemap generation must include only VNV Events public content.

## Next Ophyra Work

Ophyra should add or confirm:

- `site_key` on templates.
- Brand-scoped template listing.
- Page duplication flow.
- Template-to-page generation flow.
- Optional `.twig` export per generated page.
- Origin metadata on duplicated/generated content.
- Sitemap feed per `site_key`.
