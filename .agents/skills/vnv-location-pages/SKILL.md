---
name: vnv-location-pages
description: Create isolated service-location page cycles for VNV Events, Miami Tech Lab, or The Pasta Station across the approved South Florida cities. Resolve the active project and site key before service discovery, seed creation, SQL generation or publication. Do not use for blog posts, store products, or non-location pages.
---

# VNV Location Pages

## Mandatory multisite routing — takes precedence

This workflow now serves three brands. Any later VNV-specific wording applies only when the resolved active site is `vnvevents`.

| Project root | Site key | Brand | Public origin | Cloudinary folder |
|---|---|---|---|---|
| `C:\xampp\htdocs\vnv-events` | `vnvevents` | VNV Events | `https://vnvevents.com` | `ophyra-growth-hub/vnvevents` |
| `C:\xampp\htdocs\miami-tech-lab` | `miamitechlab` | Miami Tech Lab | `https://miamitechlab.com` | `ophyra-growth-hub/miamitechlab` |
| `C:\xampp\htdocs\vnv-gourmet` | `avomeal` | The Pasta Station | `https://thepastastation.net` | `ophyra-growth-hub/avomeal` |

Before selecting or accepting a service:

1. Resolve and cross-check the working directory, `SiteContext`/environment and active `growth_sites` row. Stop if they disagree.
2. Inspect only the active brand's homepage, navigation, sitemap, physical public pages, public services/landing pages, store, blog and existing locations.
3. Query only rows with `id_owner=2 AND site_key=<active site key>` for CMS content/routes, products, visibility and prior location cycles.
4. Build the service facts, internal-link inventory, brand voice, CTA and image inventory exclusively from that evidence.
5. Reject a seed or cycle that imports a sister brand's services, products, URLs, pricing, copy, schema identity or Cloudinary folder.
6. Persist every seed, derived content row, route and visibility row under the resolved site key; generate canonical URLs from the resolved public origin.
7. Run duplicate and collision checks per site key. The same slug may legitimately exist on another brand.
8. Give the editorial provider a closed same-brand evidence packet and explicitly prohibit sister-brand context.
9. Validate every internal link against the active origin and every image against the active brand's canonical Cloudinary folder before SQL assembly.

An explicit URL approves the service only when its origin matches the resolved brand. A VNV URL cannot seed Miami Tech Lab or The Pasta Station, and vice versa.

Operate the VNV Events location-page workflow from service selection through publication and SEO verification. Public content is English unless Jonathan explicitly requests otherwise. Build and validate the complete cycle against the local `ophyra` database by default. Valid pages are published immediately; Jonathan will unpublish them later if desired. Do not write directly to production; deliver one reviewed production SQL artifact after local verification unless Jonathan explicitly authorizes a different deployment method.

## Fixed production scope

- Public site: `https://vnvevents.com`
- Business owner ID: `2`
- Canonical site key: `vnvevents`
- Canonical content type: `location`
- Canonical route shape: `/locations/{slug}/`
- Production database currently identified as `ophyra_vnv_venue`; verify its name before every run.
- Use signed-in VNV Events, Claude, cPanel and phpMyAdmin sessions. Never ask Jonathan to paste credentials or secrets into chat.

Read [references/cms-contract.md](references/cms-contract.md) before inspecting or writing CMS records. Read [references/city-registry.md](references/city-registry.md) before selecting cities or creating batches.

## Session and permission preflight

Jonathan does not provide a session checklist. The agent owns this preflight:

1. Inspect the capabilities of the current Codex session and browser tabs already open.
2. Test whether computer/browser control works.
3. Validate only the services required for the current phase: local VNV CMS and `ophyra`, Claude for editorial work, and cPanel/phpMyAdmin only immediately before production export or deployment.
4. Report which requirements are already ready.
5. Ask Jonathan to sign in manually only to the specific missing service. Never request credentials or ask him to reopen a session that already works.
6. After Jonathan confirms `listo`, validate that session and continue without asking him to restate the task.

If browser control itself is unavailable, stop immediately and tell Jonathan to enable **Full access / computer control** for the Codex conversation. Do not begin a partial run that cannot reach Claude or the CMS.

## One-time context bootstrap

At the beginning of every new conversation, before proposing a service or generating editorial content:

1. Recursively enumerate and read every Markdown file under `docs/` in full. Treat newer, service-specific documentation and the live local schema as authoritative when older documents conflict; report any material contradiction instead of silently guessing.
2. Verify that the local `ophyra` database is reachable and inventory its complete schema: tables, columns, keys and relevant relationships.
3. Inspect the row-level business data needed to understand VNV: site/brand configuration, canonical CMS content and routes, active templates and categories, store products and categories, public-visibility rules, SEO/sitemap configuration, and existing service/location cycles.
4. Build a concise internal working map of VNV's brands, services, offers, public URLs, CMS contract and existing location coverage. Reuse that map for the rest of the conversation; do not reread the entire database before every city.
5. Do not enumerate credentials, authentication records, clients, team members, orders, contracts, payments or other personal/transactional rows merely for background context. Query those areas only if a later explicit task genuinely requires them, and never expose secrets or personal data in chat or generated artifacts.

This bootstrap is read-only. It does not authorize database changes, production access or creation of summary/cache files in the repository.

## Mandatory checkpoints

Do not skip or merge these checkpoints:

1. Obtain Jonathan's approval of the service and base page; an explicit supplied URL carries this approval.
2. Resolve the ordered 62-city registry and reuse its recorded approval when the cycle is already approved.
3. Create, validate and publish one seed location page through the Level 1 form.
4. Use that canonical seed to generate the remaining 61 pages through deterministic SQL, in the frozen 15/15/15/17 validation groups.
5. Execute and verify the local SQL without pausing for approval between groups unless an actual validation problem appears.
6. Keep every valid page in the current cycle public and verify that it appears in `/locations`.

Stop on a genuinely unapproved service, schema discrepancy, route collision, city-count mismatch, failed database write, invalid Claude output, or missing required production export. Do not stop merely because a valid page has not received an additional per-page approval.

### Approval carried by an explicit base URL

When Jonathan invokes this skill with a specific localhost or VNV Events service-page URL and says to base the cycle on it, treat that URL as the approved and tested base page. The URL itself satisfies checkpoint 1; do not look for a previous repository artifact, prior chat record or separate approval marker. Open the supplied URL, resolve its canonical CMS record and Twig/template implementation from the local application and database, and continue with the city-registry and seed workflow.

If Jonathan also says to continue an already approved named cycle, preserve the frozen city registry recorded by this skill. Do not restart discovery merely because no generated SQL or seed artifact exists yet; absence of an artifact means the next deliverable still needs to be created, not that approval is missing.

## 1. Propose or accept the service

When Jonathan supplies a service URL, accept it as the approved base, inspect its canonical Twig/public implementation, service offer, target customer, store products and internal links. Search physical pages, `cms_routes`, location content, the store and sitemap for an equivalent cycle to prevent duplication. Report the resolved base service, seed city, route pattern and angle, then proceed without asking Jonathan to approve the same URL again. Stop only if the URL fails locally, resolves to the wrong service, or collides with an existing incompatible cycle.

When Jonathan asks for a proposal, inventory public service pages, store offers, sitemap coverage and existing location cycles. Propose one commercially important service lacking a complete cycle and wait for approval.

Never invent an offer, price, address, testimonial, city office or guarantee.

## 2. Confirm the local working database

1. Verify that the active local database is `ophyra` and that its CMS schema is compatible with production.
2. Perform all seed, batch and rendering tests locally.
3. Do not create redundant local database copies when Jonathan has confirmed the local database is available.

## Production safety before deployment

Before the final SQL is executed in production:

1. Export the complete production database in cPanel/phpMyAdmin, not only CMS tables.
2. Prefer compressed SQL named `ophyra_vnv_venue_before_locations_YYYYMMDD_HHMMSS.sql.gz`.
3. Download it outside the repository and all public web directories.
4. Verify the file exists, is non-empty and has a plausible size.
5. Record its local path and size without exposing secrets.

Create only this one pre-deployment export. Do not create repeated copies unless Jonathan explicitly requests them. Generating the final SQL locally does not itself require production access.

## 3. Freeze the city registry

Resolve the authoritative ordered list using [references/city-registry.md](references/city-registry.md). Present discrepancies to Jonathan. After approval, freeze it:

- Part 1: cities 1-15
- Part 2: cities 16-30
- Part 3: cities 31-45
- Part 4: cities 46-62

Check every route and label it `new`, `existing-compatible`, or `collision`. Never overwrite a collision.

## 4. Create the seed page

1. Use the Level 1 unified CMS page creator, not legacy tables.
2. Select `location`, the correct active category and appropriate active template.
3. Create one seed for a representative approved city as `PUBLISHED` using the Level 1 form. Confirm that its canonical route renders and that its card appears in `/locations` before deriving SQL.
4. Claude is the primary editorial builder of the page body, as in the VNV weekly-article workflow. Give Claude the complete source Twig/body, verified service facts, city/county, relevant VNV URLs, researched local context and this skill's CMS/SEO constraints. Do not reduce its role to token replacement or a light proofreading pass. Allow it to restructure and rewrite the body substantially when that improves usefulness, local relevance, conversion and SEO.
5. Ask Claude to add genuinely useful, verifiable local information: venue and event context, municipality or county resources, logistical considerations and authoritative external references when relevant. Do not ask it to pad the page with generic city facts.
6. Require valid production-ready HTML/Twig-compatible body content, not markdown, SQL commentary or an explanation of its work.
7. Independently fact-check and correct unsupported claims, broken links, weak headings, duplication, route errors and malformed markup before accepting Claude's body.
8. Include a responsive city-reference map near the local service-area or planning section. Embed the municipality itself with a public no-token map URL such as `https://www.google.com/maps?q={URL_ENCODED_CITY_STATE}&output=embed`; never point it to a VNV office, invented address or precise customer location. Give the iframe a descriptive city-specific `title`, `loading="lazy"`, an appropriate `referrerpolicy`, and responsive CSS/container behavior. Do not require or expose a Google Maps API key.
9. Production-facing images must be durable HTTPS Cloudinary assets owned by the canonical VNV site folder. A file existing under local `public/assets`, an `asset_for(...)` expression, `/vnv-events/assets/...`, `localhost`, a filesystem path or a temporary/provider URL is not a production image URL.
   - Reuse an appropriate approved Cloudinary asset when one already exists. Otherwise upload the reviewed local source to the VNV Cloudinary folder with a stable descriptive public ID before generating SQL.
   - Record and use the returned `https://res.cloudinary.com/...` secure URL exclusively in `featured_image_url`, thumbnail/card data, hero image, hero preload, inline and secondary images, Open Graph/social metadata, schema JSON and every other image-bearing field.
   - Inventory every distinct image URL used by the cycle and verify each exact URL returns HTTP 200 with an `image/*` content type before accepting it.
10. Preview and validate the published seed. Continue automatically when it passes; ask Jonathan only when a substantive editorial choice or validation failure cannot be resolved safely.

The seed must be useful for that city. Use only verifiable local context; never imply VNV has a physical office in every city.

## 5. Derive production-safe batches

After the published seed passes validation:

1. Inspect the exact local schema and export the published seed's related canonical CMS rows.
2. Build deterministic CMS, route and SQL data from the published seed, but do not clone one generic body by replacing only the city name. The form is used only for the first seed; pages 2-62 are created through SQL.
3. Send each city body through Claude with its city/county research packet. Claude must create or materially adapt the editorial body and metadata for that market. Codex must fact-check, validate and normalize every result before it enters a batch.
   - Treat provider responses as untrusted envelopes. Extract only the explicitly requested field payloads; reject reasoning banners, elapsed-time text, search notices, XML wrappers, CDATA markers, UI glyphs, markdown fences and provider commentary.
   - Validate every decoded destination field independently before hex encoding or SQL assembly. At minimum scan `title`, `excerpt`, `content_json`, `body_html`, all metadata fields, canonical URL, schema JSON and featured image URL for foreign prefixes/suffixes and control characters.
   - Never recover a malformed response by copying the visually plausible tail into SQL without rerunning the complete field validation.
   - Reject any provider response that places reasoning, search narration, tool output, XML/CDATA, markdown fences or other non-page material inside a title, excerpt, body, schema or metadata field. Do not patch polluted fields after bulk insertion; fix the canonical generation input before deriving the cycle.
4. Generate, execute and test local SQL in the frozen four-part sequence, then assemble one complete production SQL artifact containing all 62 approved cities.
5. Make every part transactional with duplicate preflight checks.
6. Resolve all IDs from the current database; never guess them.
7. Insert the canonical content and route relationship atomically.
8. Insert valid records with the canonical published/public status and all visibility rows required by the current CMS. Never leave a completed cycle in `DRAFT` merely to await review.
9. Do not use destructive statements, broad updates, table recreation, disabled foreign keys or legacy `cms_location_pages`.

After each part, run a compact validation report with clickable routes and confirm the pages appear in `/locations`. Continue automatically to the next part when validation passes. Do not create 61 pages manually through the CMS form.

## 6. Validate all pages

Verify unique title, slug, route, meta title and meta description; correct service/city/county; a useful Claude-built body with meaningful local differentiation; valid VNV internal links; selective authoritative external references; no invented prices or facts; valid shared images and city-appropriate alt text; one responsive no-token map embed targeting the correct city rather than a VNV address; `Service` JSON-LD with `areaServed`; no fake city-specific `LocalBusiness`; no route collision; canonical published/public status; required visibility rows; a working public route; and inclusion in `/locations`.

For every page, not merely a sample:

1. Decode the exact SQL values and compare them byte-for-byte with the reviewed canonical fields before execution.
2. Compile the stored Twig body through the same `TemplateResponse::renderString` path used publicly. A database insert and HTTP 200 do not prove the body rendered.
3. Fetch the public route and fail if the response exposes unresolved `{% ... %}` / `{{ ... }}` tokens, encoded template directives, provider reasoning text, CDATA/XML wrappers, broken asset expressions or unexpected fallback markup.
4. Verify that hero and CTA backgrounds have explicit fallback colors and that hero copy, dark-card copy, CTA headings/copy, buttons and links retain readable colors without depending on inheritance or the background image loading.
5. Run an automated WCAG contrast check on the rendered desktop and mobile DOM for normal text and interactive controls, followed by a visual screenshot review at representative desktop and mobile widths. Treat contrast below AA, clipped headings, raw template code, missing images and unreadable hover/focus states as blocking failures.
6. Record a 62-row validation matrix covering field sanitation, Twig compilation, route rendering, unresolved-token scan, asset loading and contrast. Do not assemble or label the production artifact reviewed until all rows pass.
7. The matrix must explicitly record, per city: thumbnail/card image, featured image, hero/preload, every secondary image, Twig compilation, unresolved-code scan, desktop contrast, mobile contrast and final HTTP result.
8. Scan the fully assembled, decoded SQL and every rendered DOM for local or nonportable image references. Fail on `/vnv-events/assets/`, `assets/images/`, `localhost`, Windows/drive/filesystem paths, unresolved `asset_for(...)`, empty `src`, non-HTTPS URLs, temporary provider URLs or any image response that is not HTTP 200 with an `image/*` MIME type. Require Cloudinary for every cycle-owned production image and require the final scan count for forbidden references to equal zero.
9. Render and inspect all 62 routes through the public application path. Reject the complete cycle on any broken image, empty source, unresolved Twig, provider/code fragment in visible text, unreadable contrast or city whose rendered DOM differs materially from its validated data. HTTP 200 alone is never sufficient.
10. Fix contrast and asset behavior in the canonical seed before deriving the remaining pages. Do not rely on an unreviewed cycle-wide string replacement or repeated CSS guard injection that can corrupt markup or append styles multiple times.

## 7. Publish and finish

1. Publish and test the local records through the normal public routes. Deliver the complete production SQL after the whole local cycle passes validation; its inserted records must also be public by default.
2. Require the single production database export immediately before Jonathan executes the SQL in production.
3. After deployment, render and verify all 62 public routes and their image responses. A smaller visual screenshot sample is acceptable only after the automated 62-route DOM, asset and contrast checks all pass.
4. Regenerate the VNV sitemap and SEO artifacts once after final approved publication.
5. Confirm new routes appear and return successful responses.
6. Report the service/base URL, export path and size, city-registry source, created/skipped/collided/published counts, batch results, seed/sample URLs, `/locations` inclusion, sitemap result and manual decisions.

Never report completion while any required page, approval, production check or SEO verification remains.
