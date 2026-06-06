# VNV Events Unified CMS

## Purpose

Pages, blog posts, blog categories, CMS categories and location pages must be managed from `panel/cms`. SEO generation and AI content review are linked from the same CMS workspace so editorial work is not scattered across panel navigation.

## Origin Rules

- Local VNV Events records use `site_key=vnvevents`.
- Local records should carry `content_origin=vnv_events` when the database migration has been applied.
- `created_by` and `updated_by` store the real session user when available; `id_owner` remains the business owner scope, not the author identity.
- Ophyra Growth Hub remains the first public content source for `vnvevents`; local CMS records are fallback during migration.
- Templates and categories must also be scoped by brand/site. A VNV Events template or category should not be listed while editing Avomeal or Jonnys Media content unless it is explicitly marked shared.

## Public Routes

- Blog index: `/blog/`
- Blog posts: `/blog/{slug}/`
- Location pages: `/locations/{slug}/`
- CMS pages: `/{slug}/`

## Templates

Templates should be treated as reusable style/layout systems. A page can be duplicated or generated from a template, but the resulting content record must still be scoped to the active `site_key`.

Preferred direction:

- `cms_templates` stores brand-scoped style/layout definitions.
- `cms_contents.id_template` links a page or post to the template used to create it.
- The generated page can become a concrete `.twig` view or a Twig-rendered CMS payload.
- Duplication copies content into a new record, generates a new slug, resets status to draft and preserves origin metadata.

See `docs/OPHYRA_GROWTH_HUB_TEMPLATE_INTEGRATION.md` for the Ophyra-facing model.

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

- `cms_contents`
- `cms_routes`
- `cms_location_pages`
- `blog_categories`
- `cms_categories`

The SQL is designed to preserve Ophyra Growth Hub columns and add VNV Events local CMS columns alongside them.
