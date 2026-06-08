# VNV Events Unified CMS

## Purpose

Pages, blog posts, categories and templates must be managed from the Ophyra Growth Hub CMS model. The VNV Events `panel/cms` area is a local consumer/admin surface for that model, not a separate CMS source.

Legacy local generators for AI content and location pages are historical compatibility only. They should not be counted as active CMS inventory and should not be used for new public publishing.

## Origin Rules

- Ophyra CMS records for VNV Events use `site_key=vnvevents`.
- New records should carry `content_origin=ophyra_growth_hub` when they originate in Ophyra.
- `created_by` and `updated_by` store the real session user when available; `id_owner` remains the business owner scope, not the author identity.
- Ophyra Growth Hub is the canonical public content source for `vnvevents`.
- Templates and categories are part of the same Ophyra CMS contract. A VNV Events template or category should not be listed while editing Avomeal or Jonnys Media content unless it is explicitly marked shared.

## Public Routes

- Blog index: `/blog/`
- Pages: `/{slug}/`
- Locations: `/locations/{slug}/`
- Blog articles: `/blog/{slug}/`

Do not create new public pages in `cms_location_pages`. If location content is needed, create it in `cms_contents` with `content_type = location`, a CMS category, a template and a public route in `cms_routes`.

## Templates

Templates should be treated as reusable style/layout systems. A page can be duplicated or generated from a template, but the resulting content record must still be scoped to the active `site_key`.

Categories should be treated as one CMS taxonomy, not as separate generator-specific buckets:

- `cms_contents.content_type` should be limited to `page`, `location` and `blog`.
- Landing pages are `content_type = page` with a landing-style template such as `service-landing`.
- `cms_categories` is the active taxonomy for pages, location pages and blog articles.
- Legacy `blog_categories` rows may remain for old blog records, but they should not be shown as a separate active CMS area.
- Category records must be scoped by `site_key`.

Preferred direction:

- `cms_templates` stores brand-scoped style/layout definitions.
- `cms_contents.id_template` links a page or post to the template used to create it.
- `cms_templates.css_text` stores the real visual skin used by admin previews and public Growth Hub rendering when the payload includes template data.
- `cms_templates.metadata_json` stores template-level metadata such as versioning or allowed block intent.
- The generated page can become a concrete `.twig` view or a Twig-rendered CMS payload.
- Duplication copies content into a new record, generates a new slug, resets status to draft and preserves origin metadata.

See `docs/OPHYRA_GROWTH_HUB_TEMPLATE_INTEGRATION.md` and `docs/GROWTH_HUB_PUBLIC_RENDERING_REPORT.md` for the Ophyra-facing model and public consumer contract.

## Database Migration

When importing SQL manually, use the root `db/*.sql` files, not only `db/migrations`.

Recommended order:

1. `db/20260606_ophyra_growth_hub_level1_required.sql`
2. `db/20260606_vnv_events_cms_origin_metadata.sql`
3. `db/20260606_seo_files_logs_required.sql`
4. `db/20260606_event_requests_required.sql`
5. `db/20260606_user_workspace_preferences.sql`
6. `db/20260606_orders_menu_closure_alignment.sql`

`db/20260606_vnv_events_cms_origin_metadata.sql` creates missing local CMS compatibility tables and adds origin/author metadata to:

- `cms_templates`
- `cms_contents`
- `cms_routes`
- `cms_categories`

It also preserves legacy compatibility for `cms_location_pages` and `blog_categories`, but those tables are not active CMS inventory under the Ophyra Growth Hub model.

The SQL is designed to preserve Ophyra Growth Hub columns and add VNV Events local CMS columns alongside them.

`cms_templates` is expected to include:

- `id_owner`
- `site_key`
- `template_key`
- `type`
- `preview_html`
- `template_structure_json`
- `css_text`
- `metadata_json`
- `status`

The Level 1 template library filters templates by the active `site_key` plus explicitly shared scopes (`shared`, `global`, `all_sites`). The local preview replaces the standard placeholders (`{{ title }}`, `{{ body_html|raw }}`, `{{ body|raw }}`) and applies `css_text` before rendering.
