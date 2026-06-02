# Avomeal Launch Checklist

Use this checklist before public launch.

## Site Scope

- [x] Production dump was imported into a temporary audit database.
- [ ] Live production database connection has been checked after deployment.
- [ ] `brand_sites` has `site_key = avomeal`.
- [ ] `brand_sites.public_base_url` matches the real Avomeal public domain.
- [ ] Runtime env has `SITE_KEY=avomeal`.
- [ ] Runtime env has `SITE_BUSINESS_USER_ID=2`.
- [ ] `site_visibility` rows exist for products/categories/content that need review.
- [ ] Products approved for public display are `VISIBLE`.
- [ ] Categories approved for public display are `VISIBLE`.
- [ ] Avomeal store categories have `site_key = avomeal` and matching `site_visibility` rows.
- [ ] CMS/blog/location/forum records are not public unless approved.
- [ ] Owner `2` records that belong to VNV Events remain hidden or pending.

## Storefront

- [ ] Home/Avomeal landing page uses Avomeal branding.
- [ ] Public product lists show only visible Avomeal products.
- [ ] `PENDING_REVIEW` products do not appear publicly.
- [ ] Hidden products do not appear publicly.
- [ ] Public category pages require visible Avomeal categories.
- [ ] Public product pages require visible Avomeal products.
- [ ] No generic VNV Events products appear in Avomeal by owner alone.

## Cart And Checkout

- [ ] Customer can add a visible product to cart.
- [ ] Cart rejects products not visible for Avomeal.
- [ ] Cart totals calculate correctly.
- [ ] `minimum_order_amount` is loaded from `brand_site_settings` when present.
- [ ] Public Avomeal copy does not hardcode the minimum order amount.
- [ ] Checkout blocks payment below the configured minimum.
- [ ] Checkout creates `store_orders.id_owner = 2`.
- [ ] Checkout creates `store_orders.site_key = avomeal` when the column exists.
- [ ] Order items/payment rows carry `site_key = avomeal` when the columns exist.

## Payment

- [ ] `brand_site_settings.active_payment_provider_id` is set if multiple providers exist.
- [ ] The selected provider belongs to `id_owner = 2`.
- [ ] The selected provider has `site_key = avomeal`.
- [ ] The selected provider is active.
- [ ] Payment button uses the site-scoped provider.

## SMTP

- [ ] `brand_site_settings.active_smtp_id` is set if multiple SMTP configs exist.
- [ ] The selected SMTP belongs to `id_owner = 2`.
- [ ] The selected SMTP has `site_key = avomeal`.
- [ ] The selected SMTP is active.
- [ ] SMTP sender name uses Avomeal branding, not VNV Gourmet or mixed legacy branding.
- [ ] Test email uses Avomeal sender/branding.

## Level 1

- [ ] Level 1 can access Site Scope settings.
- [ ] Level 1 can approve/hide/archive visibility records.
- [ ] Level 1 can set minimum order amount.
- [ ] Level 1 can set active payment provider ID.
- [ ] Level 1 can set active SMTP ID.
- [ ] Level 1 can see store orders.
- [ ] Level 1 can see order details, products, payment and customer info.
- [ ] Level 1 can assign orders to team members where workflow exists.

## Level 4

- [ ] Team member dashboard loads.
- [ ] Assigned orders view loads.
- [ ] Team member only sees allowed/assigned work.
- [ ] Kitchen/team store workspace loads where enabled.
- [ ] Mobile layout is usable for order work.

## Level 5

- [ ] Customer signup/login creates or uses Level 5 user.
- [ ] Customer dashboard loads.
- [ ] Customer can see own orders.
- [ ] Customer can see own subscriptions.
- [ ] Customer cannot see Level 1/Level 4 internal tools.
- [ ] Nutrition/wellness tools load if enabled.

## UI

- [ ] Level 1 store views use shared panel cards/tables/buttons.
- [ ] Level 4 store/team views are readable on mobile.
- [ ] Level 5 order/subscription views are simple and customer-safe.
- [ ] Empty states are clear.
- [ ] Tables do not overflow badly on mobile.

## SEO And Public Files

- [ ] SEO Center regenerates sitemap manually.
- [ ] `sitemap.xml` uses the Avomeal public base URL.
- [ ] Sitemap excludes hidden/pending content.
- [ ] `robots.txt` points to the correct sitemap.
- [ ] `llms.txt` and `llms-full.txt` describe Avomeal, not VNV Gourmet.

## Documentation

- [ ] `docs/AGENTS.md` says Avomeal is the current repo context.
- [ ] `docs/SITE_SCOPE_AND_VISIBILITY.md` is current.
- [ ] `docs/PAYMENT_AND_SMTP_SCOPE.md` is current.
- [ ] `docs/NEXT_AGENT_HANDOFF.md` reflects this final sprint.
- [ ] No active docs describe VNV Gourmet as the public brand.

## Residual Launch Risks

- [ ] Confirm real production domain before final sitemap/robots generation.
- [ ] Review every `PENDING_REVIEW` product/category before launch.
- [ ] Confirm active payment provider with a real small transaction.
- [ ] Confirm active SMTP with a real test email.
- [ ] Review currently visible products and hide event/rental/media items that do not belong in Avomeal.
- [ ] Clean orphan Store order items/payments that reference missing `store_orders` rows.
- [ ] Confirm subscription renewal cron is intended for Avomeal before enabling production automation.
