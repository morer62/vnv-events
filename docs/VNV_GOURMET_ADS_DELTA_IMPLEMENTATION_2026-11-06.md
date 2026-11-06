# VNV Gourmet Ads delta — implementation report

Date: November 6, 2026

Scope: VNV Events (`site_key=vnvevents`, owner 2)

Status: implemented and tested locally; no Google Ads changes and no real conversion export.

## Verified Ophyra states

The existing data model already provides the states needed for the first offline-conversion cycle:

- `lead_intake.availability_status` and the lead payload identify a qualified lead.
- `lead_intake.converted_order_id` links the lead to a real order.
- The existing invoice/order snapshot provides order total, net paid amount and payment time.
- A booked conversion is emitted only when the linked order has positive net payment and is not draft, cancelled or voided.

No new commercial stage was invented. Proposal-sent or proposal-approved conversion actions remain out of scope because they were not required for this delta and were not verified as a stable lead state.

## Implemented delta

- Completed the comparison page Ads mode with service selector, short form, Call, configured WhatsApp, `GA-STATIONS-*`, attribution fields and mobile CTA bar.
- Reorganized Corporate around the four campaign stations, with the 25–49 regular-station alternative and meal boxes as a separate secondary format.
- Separated 90-day attribution cookies from the session-only Ads presentation cookie.
- Removed product, hub, catalog and comparison distractions from individual Ads landings; menu buttons remain internal anchors.
- Replaced ZIP prefixes with an explicit dominant-county table derived from the [2020 Census ZCTA-to-County relationship file](https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt). Unknown ZIPs remain `unknown` and do not block submission.
- Enforced a strictly future event date in `America/New_York`.
- Added shared tenant/IP rate limiting: 5 submissions per 10 minutes and 20 per day, with trusted-proxy handling, HTTP 429, Call and configured WhatsApp alternatives, and hashed-IP logs.
- Removed global self-published `AggregateRating` and `Review` JSON-LD.
- Added `vnv_test=1` propagation to Ophyra and hard exclusion from offline exports.
- Added a dry-run-first purge command that deletes only verified `TEST` leads whose payload also contains `is_test=true`; `--apply` requires an explicit confirmation phrase.
- Added idempotent dry-run-first CSV export for qualified leads and paid booked events. Deduplication is `conversion_action + order_id`.

## Database delta

`db/20261106_gourmet_ads_delta.sql` adds:

- tenant configuration `corporate_meal_box_price`;
- `gourmet_lead_rate_events`;
- `gourmet_conversion_exports`.

The previously published VNV Events meal-box value is seeded only for owner 2 / `vnvevents`. Runtime copy omits the meal-box price when tenant configuration is absent.

## Test evidence

- PHP contract: 66 assertions passed.
- Integration: sixth shared-IP request blocked; dry-run export count unchanged.
- Playwright: 72 pages passed — six routes × four modes × three exact viewports (`360×740`, `390×844`, `1440×900`).
- Covered organic, `gclid`, `utm_source=google`, and `gbraid`.
- Covered same-session Ads persistence and new-session normal navigation with retained hidden attribution and `GA-*` reference.
- Dynamic WhatsApp content verified for service, visible price, date, guests, ZIP and reference.
- Test lead persisted as `TEST|gourmet_landing|QUALIFIED|test`.
- Offline exporter dry-run after the test lead: 0 rows.
- Mobile performance sample: LCP 628 ms; CLS 0.0000.
- Migration runner: current after local apply.
- `git diff --check`: no whitespace errors.

## Price grep

The audited campaign Twig/PHP presentation files contain no literal occurrences of `$19`, `$21.77`, `$17` or `$12.50`. Visible values are resolved from Store/tenant configuration. The migration contains `12.50` only to move the already approved published value into owner/site-scoped configuration.

## Files changed

- `bin/apply-gourmet-landing-migration.php`
- `bin/export-gourmet-offline-conversions.php`
- `bin/purge-gourmet-test-leads.php`
- `db/20261106_gourmet_ads_delta.sql`
- `src/Services/CateringLandingProductService.php`
- `src/Services/GourmetLandingAttributionService.php`
- `src/Services/GourmetLeadRateLimiter.php`
- `src/Services/GourmetOfflineConversionExporter.php`
- `src/Services/InvoiceService.php`
- `src/Services/PublicSeoService.php`
- `src/views/api/public/gourmet-lead/index.php`
- `src/views/api/public/gourmet-track/index.php`
- comparison and Corporate route/templates
- shared catering landing template
- FAQ schema source
- three Gourmet contract/integration/browser tests

## Pending external configuration

- Create or confirm the exact Google Ads conversion action names, then set `GOOGLE_ADS_QUALIFIED_LEAD_ACTION` and `GOOGLE_ADS_BOOKED_EVENT_ACTION` if they differ from the defaults.
- Review a dry-run CSV before any `--apply` export.
- Configure `TRUSTED_PROXIES` only when the production reverse-proxy ranges are known.
- Deploy code and run the migration runner in production through the normal VNV Events Git pull workflow.

## Risk and rollback

- Code rollback: revert the delta commit. The new tables/nullable setting are additive and can remain safely unused.
- Conversion rollback: no real conversion was exported. Export records are written only with explicit `--apply`.
- Rate-limit storage failure is fail-open while the existing session limiter remains active, avoiding a public-form outage.
