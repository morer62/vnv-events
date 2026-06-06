# VNV Events Unified CMS

## Purpose

Pages, blog posts, blog categories, CMS categories and location pages must be managed from `panel/cms`. SEO generation and AI content review are linked from the same CMS workspace so editorial work is not scattered across panel navigation.

## Origin Rules

- Local VNV Events records use `site_key=vnvevents`.
- Local records should carry `content_origin=vnv_events` when the database migration has been applied.
- `created_by` and `updated_by` store the real session user when available; `id_owner` remains the business owner scope, not the author identity.
- Ophyra Growth Hub remains the first public content source for `vnvevents`; local CMS records are fallback during migration.

## Public Routes

- Blog index: `/blog/`
- Blog posts: `/blog/{slug}/`
- Location pages: `/locations/{slug}/`
- CMS pages: `/{slug}/`

## Database Migration

Apply `db/migrations/20260606_vnv_events_cms_origin_metadata.sql` to add origin and author metadata to:

- `cms_contents`
- `cms_routes`
- `cms_location_pages`
- `blog_categories`
- `cms_categories`

The code checks for these columns before writing them, so existing databases continue to work before the migration is applied.
