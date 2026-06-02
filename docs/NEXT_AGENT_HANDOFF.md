# Next Agent Handoff

## Current Context

This repo should now be read as Avomeal Web for the current sprint.

## Naming Correction

Avomeal is the real launch/public brand. VNV Gourmet was only an early temporary codename. Do not reintroduce VNV Gourmet as public-facing copy, SEO naming or documentation identity; keep any `vnv-gourmet` names only where they are legacy technical route/asset/repository references.

Confirmed scope:

```text
id_user_business = 2
id_owner = 2
site_key = avomeal
```

Avomeal is the food/store brand under VNV Events, with public branding focused on meal preps, holiday menus and party boxes.

## What Was Clarified

- Avomeal does not use a separate DB in this direction.
- It shares the VNV Events database universe.
- Owner `2` is confirmed for Avomeal ownership scope.
- `site_key = avomeal` plus `site_visibility` controls what appears publicly.
- `brand_site_settings` controls site-level configuration such as minimum order, payment provider and SMTP.
- The store must support products, subscriptions, orders, customers and payments.
- Minimum order is `$65`.
- Ophyra remains parent/reference architecture and future integration target.

## Final Sprint Changes

- Public Store products/categories now require explicit `site_visibility = VISIBLE` when `site_visibility` exists.
- Public CMS, location pages and forums use site visibility for public exposure.
- Operational Store rows now default to `site_key = avomeal` when supported by the DB.
- `/panel/planner-hub/settings/site-scope` lets Level 1 seed pending visibility rows, approve, hide, archive and edit site settings.
- `minimum_order_amount` reads from `brand_site_settings` before env/default fallback.
- Payment provider selection can use `brand_site_settings.active_payment_provider_id`.
- SMTP selection can use `brand_site_settings.active_smtp_id`.
- `docs/AVOMEAL_LAUNCH_CHECKLIST.md` tracks final launch QA.
- AI Content Assistant first phase added for blog posts and location pages only. Review panel is `/panel/ai-content/review`; cron is `src/cron/ai-content-daily.php`; proposed SQL is `db/ai_content_assistant_required.sql`.
- VNV Events is now the default AI content profile (`AI_CONTENT_SITE_KEY=vnv_events`). Avomeal remains portable by switching `AI_CONTENT_SITE_KEY=avomeal`.
- If the AI Content SQL was installed before the VNV default seed was added, apply `db/ai_content_assistant_vnv_events_seed.sql`.
- AI content cron creates `NEEDS_REVIEW` drafts only. Publishing is manual and requires `APPROVED` status.
- Do not allow AI-generated content to publish without human approval.

## Next Technical Audit

Review:

- Team-member contracts: apply `db/team_member_contracts_required.sql`, then browser-test Level 1 assignment/validation and Level 4 signature/clock-in blocking.
- AI Content Assistant: confirm `db/ai_content_assistant_required.sql` is installed, apply `db/ai_content_assistant_vnv_events_seed.sql` if VNV settings are missing, set `OPENAI_TOKEN` and `AI_CONTENT_REVIEW_PASSWORD`, then browser-test `/panel/ai-content/review` and dry-run `php src/cron/ai-content-daily.php`.
- DB connection and env usage.
- Store repositories for missing `id_owner` filters.
- Store/CMS/forum/location queries for missing `site_key` or `site_visibility`.
- Product/category queries for global reads.
- Cart and checkout minimum order enforcement.
- Store order creation owner assignment.
- Customer creation and client association.
- Subscription behavior and renewal risks.
- Public Avomeal landing pages and SEO metadata.
- Mobile endpoints used by Avomeal app.

## High-Risk Areas

- `store_categories` with `id_owner = NULL`.
- any Store query without owner scope.
- any public query without site visibility.
- checkout flows that create orders without `id_owner = 2`.
- checkout flows that create orders without `site_key = avomeal` when the column exists.
- client signup that creates Level 2/3 business owners instead of Level 5 customers.
- subscription automation before payment-token behavior is tested.
- payment provider or SMTP fallback when multiple Avomeal configs are active.
