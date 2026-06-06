# Growth Hub Public Consumption

VNV Events public pages, blog posts and location pages are created in Ophyra Growth Hub and consumed here through the public Growth Hub API.

## Scope

This repo must consume only:

```text
site_key = vnvevents
```

Do not render `avomeal`, `jonnysmedia`, Ophyra platform or shared owner-only content as VNV Events public content.

## Required Env

```text
OPHYRA_BASE_URL=https://ophyra.com
OPHYRA_GROWTH_SITE_KEY=vnvevents
PUBLIC_BASE_URL=https://vnvevents.com
```

Local example:

```text
OPHYRA_BASE_URL=http://localhost/ophyra
OPHYRA_GROWTH_SITE_KEY=vnvevents
PUBLIC_BASE_URL=http://localhost/vnv-events
```

## Public Routes

The router resolves published Growth Hub content for:

- `/blog`
- `/blog/{slug}`
- `/locations/{slug}`
- `/{slug}` for Growth Hub pages after local static files are checked

The old local CMS/location repositories remain as fallback compatibility, but new public CMS content should come from Ophyra.

## Sitemap

`SeoFilesGeneratorService` and `public/sitemap.xml.php` include published Growth Hub content for VNV Events. Growth Hub content is included only when Ophyra returns approved published records.

## Rules

- Public rendering requires `status = PUBLISHED`.
- Public rendering requires `approval_status = APPROVED` or `PUBLISHED`.
- The returned `site_key` must match `vnvevents`.
- Drafts and content from other brands are treated as not found.
