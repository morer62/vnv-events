# Ophyra multisite unified CMS contract

Read this before any location-page database or CMS action.

## Canonical model

Resolve the active brand before any query or write:

- `C:\xampp\htdocs\vnv-events` -> `vnvevents` -> `https://vnvevents.com`
- `C:\xampp\htdocs\miami-tech-lab` -> `miamitechlab` -> `https://miamitechlab.com`
- `C:\xampp\htdocs\vnv-gourmet` -> `avomeal` -> `https://thepastastation.net`

New location pages belong to the unified CMS:

- `cms_contents`: `content_type = 'location'`, with the resolved active `site_key`.
- `cms_routes`: canonical route, normally `/locations/{slug}/`.
- `cms_categories` and `cms_templates`: resolve current active records from production.
- `site_visibility`: let the normal publication flow maintain visibility for the resolved active site key.

Do not insert new pages into legacy `cms_location_pages`.

## Route safety

Check physical/application routes, `cms_routes`, store routes, and legacy/reserved paths before reserving a URL. A physical or reserved route wins. Never shadow or overwrite it.

## SQL invariants

- Inspect the live schema immediately before generating SQL.
- Verify owner `2` and the resolved active site key in the target database.
- Scope every content, route, product, visibility, duplicate and collision query by that site key.
- Resolve category, template, content and route IDs; never guess IDs.
- Wrap each part in a transaction with duplicate preflight checks.
- Preserve foreign keys and treat content plus route as one atomic unit.
- For an approved location cycle, write the seed and derived pages with the canonical published/public status and required `site_visibility` rows. Do not leave a completed valid cycle in `DRAFT` merely to await another approval.
- Never use `DROP`, `TRUNCATE`, broad `DELETE`/`UPDATE`, disabled foreign keys or table recreation.

## Content invariants

- Use English unless Jonathan requests otherwise.
- Keep facts consistent with the active brand's approved service page and current same-site store.
- Prefer live product links for changing prices.
- Do not invent offices, staff, reviews, statistics, availability, permits or guarantees.
- Use `Service` schema with `areaServed`, never fake city-specific `LocalBusiness` schema.
- Keep canonical URLs, titles and metadata unique.
- An explicit base URL from Jonathan is approval for that cycle. Publish each page automatically after it passes the location skill's complete validation gate; Jonathan may unpublish it later.

## Production image invariants

- Every cycle-owned production image must use a permanent `https://res.cloudinary.com/...` URL from the active brand's canonical Cloudinary folder recorded in `growth_sites`.
- Reuse an approved asset or upload the reviewed source with a stable descriptive public ID before SQL generation.
- Use only verified Cloudinary secure URLs in featured/thumbnail fields, hero and preload markup, secondary images, social metadata and schema.
- Reject local asset paths, `asset_for(...)`, `localhost`, filesystem paths, HTTP URLs, empty sources and temporary provider URLs.
- Verify every distinct URL returns HTTP 200 and an `image/*` content type, then audit the completely assembled decoded SQL and all 62 rendered DOMs for zero forbidden references.
- HTTP 200 for a page is not a rendering check. Require resolved Twig, clean visible copy, working images and readable desktop/mobile contrast on all 62 pages.
