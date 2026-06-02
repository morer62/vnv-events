# VNV Events SEO / AI Control Center

> Avomeal web note: this SEO Center reference is useful for public SEO files, but Avomeal public SEO should describe meal preps, holiday menus, party boxes, subscriptions and the `$65 minimum order`.

## Purpose

The SEO / AI Control Center provides manual regeneration for public SEO and AI context files:

- `/sitemap.xml`
- `/robots.txt`
- `/llms.txt`
- `/llms-full.txt`

No cron job is enabled in this phase. Files update only when an authorized admin clicks a regeneration button.

## Admin Access

The panel is available at:

- `/panel/seo-center`

Access is intended for Level 1 and Level 6 users.

## Public Content Sources

The generator includes only public, published, indexable URLs from:

- Static public VNV Events pages
- CMS pages and blog posts from `cms_contents` + `cms_routes`
- Location pages from `cms_location_pages`
- Store categories and products
- Published public forum threads

It excludes panel, API, login, signup, private storage, drafts, unpublished content and noindex CMS content.

## Logs

Manual generation attempts are logged in `seo_files_logs` after running migration:

```bash
vendor/bin/phinx migrate
```

If the log table is not present yet, the generator still writes public files and skips DB logging.

## Search Console

Use this sitemap URL in Google Search Console:

```text
https://vnvevents.com/sitemap.xml
```
