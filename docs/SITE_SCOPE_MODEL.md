# Site Scope Model

This repo is currently operating the Avomeal public web/store experience under the VNV Events shared database.

## Confirmed Avomeal Scope

```text
site_key = avomeal
site_name = Avomeal
id_user_business = 2
id_owner = 2
minimum_order_amount = 65.00
```

Avomeal was created by VNV Events. `VNV Gourmet` was an early codename only and should not be used as the public brand name.

## Ownership vs Public Visibility

`id_owner` / `id_user_business` answers: who owns or operates the record.

`site_key` answers: which public site or brand is allowed to show or use the record.

These are not the same thing. A row owned by business `2` should not automatically appear on Avomeal unless it is assigned to:

```text
site_key = avomeal
site_key = shared
site_key = global
site_key = all_sites
```

This matters because Avomeal, VNV Events, Ophyra and future sibling brands can share one database universe.

## Tables That Need Site Scope

The first launch-safe site scope applies to:

- `store_products`
- `store_categories`
- `store_attributes`
- `store_attribute_values`
- `store_coupons`
- `store_carts`
- `store_orders`
- `store_order_items`
- `store_payments`
- `store_subscriptions`
- `cms_contents`
- `cms_routes`
- `cms_location_pages`
- `forum_categories`
- `forum_topics`
- `payment_providers_credentials`
- `smtp_credentials`

The code now detects `site_key` before filtering, so it stays compatible until the manual SQL is run.

## Current Code Behavior

When the `site_key` column exists, admin screens can review records assigned to the active site key.

Public Store, CMS sitemap, location pages and forum topics use stricter visibility when `site_visibility` exists:

```text
site_key = avomeal
site_visibility.is_visible = 1
site_visibility.visibility_status = VISIBLE
```

This correction exists because an early migration may have assigned too many owner `2` rows to `site_key = avomeal`. Those rows should not become public unless Level 1 confirms them.

Default runtime context:

```text
SITE_KEY=avomeal
SITE_NAME=Avomeal
SITE_PUBLIC_BASE_URL=https://avomeal.com
SITE_BUSINESS_USER_ID=2
```

New records created through patched repositories default to the active `SITE_KEY` when the table supports the column.

## Settings By Site

Site-level configuration belongs in `brand_site_settings`, not hardcoded code.

Supported settings include:

- `minimum_order_amount`
- `active_payment_provider_id`
- `active_smtp_id`
- `storefront_enabled`
- `brand_logo`
- `brand_primary_color`
- `brand_secondary_color`
- `seo_title`
- `seo_description`

The initial Level 1 route is:

```text
/panel/planner-hub/settings/site-scope
```

This panel shows visibility metrics, pending-review rows and editable site settings.

## Manual SQL Required

Run the corrective site-scope SQL provided in the handoff/chat before expecting strict separation in production. Until the SQL is run, the guards keep old behavior so the app does not break.

## Rule For Future Work

Do not solve public visibility by hardcoding product IDs, category IDs, forum IDs or CMS IDs.

Use:

```text
id_owner = business ownership
site_key = public brand/site visibility
site_visibility = future per-record override or cross-site sharing
```
