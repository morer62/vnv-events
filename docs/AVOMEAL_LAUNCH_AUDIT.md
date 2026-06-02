# Avomeal Launch Audit

Date: 2026-06-01

This audit tracks the current sprint to make Avomeal launch-ready as a Store/food brand under VNV Events.

## Confirmed Context

```text
Avomeal = Store / food brand / food operation
VNV Events = parent business / owner operation
id_user_business = 2
id_owner = 2
minimum order = $65
```

## Repository Map

- Public Avomeal landing: `src/views/public/pages/avomeal/`
- Legacy landing template still lives in `src/views/public/pages/vnv-gourmet/` and is reused by the Avomeal route for compatibility.
- Public Store cart: `src/views/public/commerce/store/cart/`
- Public Store checkout: `src/views/public/commerce/store/checkout/`
- Public product page: `src/views/public/pages/product/`
- Public product category page: `src/views/public/pages/product-category/`
- Meal plans: `src/views/public/commerce/meal-plans/`
- Store admin products: `src/views/panel/level1/planner-hub/store/products/`
- Store admin categories: `src/views/panel/level1/planner-hub/store/categories/`
- Store admin attributes: `src/views/panel/level1/planner-hub/store/attributes/`
- Store admin attribute values: `src/views/panel/level1/planner-hub/store/attribute-values/`
- Store orders admin: `src/views/panel/level1/planner-hub/store/orders/home/`
- Store subscriptions admin: `src/views/panel/level1/planner-hub/store/subscriptions/home/`
- Customer orders: `src/views/panel/level5/store/orders/home/`
- Customer subscriptions: `src/views/panel/level5/store/subscriptions/home/`
- Customer nutrition tools: `src/views/panel/level5/store/nutrition-advisor/`
- Team store workspace: `src/views/panel/level4/planner-hub/team/store/`
- SEO files generator: `src/Services/SeoFilesGeneratorService.php`
- Shared Avomeal config: `src/Utils/AvomealContext.php`

## Corrections Applied

- Centralized Avomeal owner/minimum configuration in `AvomealContext`.
- Public products are now read with owner scope where Avomeal routes call them.
- Public categories are now read with owner scope where Avomeal routes call them.
- Admin product list/create/edit/delete uses owner scope.
- Admin category list/create/edit/delete uses owner scope.
- Admin attributes and attribute values use owner scope.
- Product relation sync now verifies category and attribute owner before attaching.
- Cart creation uses owner `2` and item rows store `id_owner`.
- Checkout cart recovery/session reads are owner-scoped.
- Checkout enforces `$65` minimum before payment.
- Checkout payment provider lookup uses owner `2`.
- Level 5 order/subscription dashboard reads are owner-scoped.
- Level 1 Store order status/payment/delivery actions now verify the order belongs to owner `2`.
- Level 4 Store team workspace now resolves the Avomeal/VNV owner institution before showing Store role context.
- SEO generator Store URLs are owner-scoped and LLM copy now describes Avomeal.
- Public Avomeal copy now describes meal preps, holiday menus, party boxes, subscriptions and `$65` minimum.
- Docs were updated to make Avomeal the active repo context.

## Remaining High-Priority Gaps

- Store order admin should still be browser-tested with real status/payment/assignment actions.
- Level 4 team Store kitchen/delivery subviews should be audited for assignment scope and location permissions.
- Level 5 order/subscription views should be browser-tested with a real Level 5 account.
- Subscription renewal cron already uses subscription owner, but should be dry-run tested before production.
- Payment provider credentials should be confirmed in DB for owner `2`.
- Product visibility is currently owner + public status. If VNV Events and Avomeal need separate storefronts under the same owner, add a dedicated storefront visibility field or pivot.
- Existing legacy categories with `id_owner = NULL` should be reviewed and either assigned to owner `2` or left hidden from Avomeal.

## SQL Checks To Run Manually

```sql
SELECT id, email, level, id_owner FROM users WHERE id = 2;
SELECT * FROM institution_profile WHERE id_owner = 2;

SELECT COUNT(*) AS public_products_owner_2
FROM store_products
WHERE id_owner = 2 AND status = 'ACTIVE' AND is_public = 1;

SELECT id, name, slug, status, id_owner
FROM store_categories
WHERE id_owner IS NULL OR id_owner <> 2
ORDER BY id DESC
LIMIT 50;

SELECT id, provider_type, provider_name, environment, currency, is_active, is_default
FROM payment_providers_credentials
WHERE id_owner = 2
ORDER BY is_default DESC, is_active DESC, id DESC;
```

## Launch Readiness Status

Avomeal is closer to launch-ready after the owner-scope fixes, but it should not be called 100% complete until Store order admin, team workspace, Level 5 views, subscriptions, active payment provider and real checkout are QA-tested against production data.
