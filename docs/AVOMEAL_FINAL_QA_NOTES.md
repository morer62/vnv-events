# Avomeal Final QA Notes

Date: 2026-06-01

## Scope Implemented

Avomeal now uses:

```text
id_user_business = 2
id_owner = 2
site_key = avomeal
```

Public visibility uses:

```text
site_visibility.is_visible = 1
site_visibility.visibility_status = VISIBLE
```

Site settings use:

```text
brand_site_settings
```

## Code QA Completed

PHP syntax was checked with `php -l` across modified PHP files. No syntax errors were detected.

Initial local live-DB QA was blocked because the configured `.env` database name returned:

```text
SQLSTATE[HY000] [1049] Unknown database 'vnv-events'
```

The production dump `ophyra_vnv_venue (25).sql` was later imported into a temporary local audit database and reviewed for launch scope.

## Production Dump QA Findings

- `brand_sites` has `site_key = avomeal`, `site_name = Avomeal`, `public_base_url = https://avomeal.com`, `id_user_business = 2`, `status = ACTIVE`.
- `brand_site_settings` has `minimum_order_amount = 65.00`, `storefront_enabled = 1`, `active_payment_provider_id = 2`, and `active_smtp_id = 1`.
- Active payment provider `2` belongs to owner `2`, has `site_key = avomeal`, uses Square production, is active and verified.
- Active SMTP `1` belongs to owner `2`, has `site_key = avomeal`, is active and verified, but still has mixed branding: `from_name = VNV Events and Gourmet`.
- `site_visibility` has 45 Avomeal product rows: 28 `VISIBLE` and 17 `PENDING_REVIEW`.
- All 45 owner `2` Avomeal products have `site_visibility` rows.
- There are no visible Avomeal store categories. Current store categories have `site_key = NULL` and no `site_visibility` rows.
- The dump has zero `store_orders`, but has 5 `store_order_items` and 1 `store_payment` pointing to `id_store_order = 1`; that order row is missing. Treat those as orphan/test records before launch.
- Several products currently marked `VISIBLE` are event/rental/media items rather than Avomeal food/store products. Review them in Site Scope before launch.

Covered areas:

- Store products/categories repositories.
- Store carts/orders/order-items/payments/subscriptions repositories.
- CMS/location/forum repositories.
- Payment provider and SMTP repositories.
- Email service factory.
- SEO files generator.
- Public Store cart and checkout entrypoints.
- API files already modified in previous sprint work.
- Site Scope panel route.

## Functional Review Completed

Reviewed and adjusted:

- Public product visibility.
- Public category visibility.
- Public CMS sitemap visibility.
- Public location/forum visibility.
- Cart product validation against public product visibility.
- Checkout minimum order reading from `brand_site_settings` through `AvomealContext`.
- Public Avomeal-facing text no longer hardcodes `$65.00`; the active minimum must come from `brand_site_settings.minimum_order_amount`.
- Checkout/order/payment records carrying `site_key` when the DB column exists.
- Payment provider selection through `active_payment_provider_id` when configured.
- SMTP selection through `active_smtp_id` when configured.
- Level 1 Site Scope panel for approval/hide/archive.

## Required Manual QA Before Launch

- Review the production dump findings above against the live database.
- Open `/panel/planner-hub/settings/site-scope`.
- Confirm pending products/categories.
- Approve only true Avomeal records.
- Set `minimum_order_amount`.
- Set `active_payment_provider_id`.
- Set `active_smtp_id`.
- Update SMTP `from_name` to Avomeal or Avomeal by VNV Events.
- Approve/create Avomeal category visibility rows or confirm that category pages should remain disabled.
- Hide or return non-food/event-rental products to `PENDING_REVIEW` if they do not belong in the Avomeal storefront.
- Remove or archive orphan Store order items/payments that reference missing order rows.
- Place a low-value test order below the minimum and confirm checkout blocks it.
- Place a valid test order with the configured payment provider.
- Confirm the Store order, order items and payment rows have `site_key = avomeal`.
- Send a test email and confirm sender/from name match Avomeal.
- Regenerate SEO files after visibility approval.

## Residual Risks

- `StorePaymentsRepository::getFilteredByOwner()` appears to contain legacy filters for fields that look like order fields. It is currently not referenced by the repo search. Do not use it for launch reporting without reviewing the schema.
- Subscription renewal cron uses owner/provider flow. Confirm it is intended for Avomeal production before enabling automated charges.
- Public base URL must match the real launch domain. Current default is `https://avomeal.com`; override `SITE_PUBLIC_BASE_URL` if the launch URL differs.
- Launch cannot be marked fully verified until the real DB confirms approved products/categories, active payment provider, active SMTP and a successful checkout test.
