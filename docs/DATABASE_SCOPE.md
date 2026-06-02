# Database Scope

Avomeal uses the same database universe as VNV Events.

The confirmed business ID is:

```text
id_user_business = 2
```

In most Store tables reviewed from the current schema, the equivalent field is:

```text
id_owner = 2
```

Public site assignment and visibility are separate:

```text
site_key = avomeal
site_visibility.is_visible = 1
site_visibility.visibility_status = VISIBLE
```

Site-level configuration is stored in:

```text
brand_site_settings
```

## Why This Matters

The shared database contains VNV Events, Avomeal, Ophyra-related users, historical Planner Hub data, Store records, CMS content, clients, orders and mobile-related data.

Avomeal must not query or mutate shared data globally.

## Fields To Check

Depending on the table, ownership may appear as:

- `id_owner`
- `id_user_business`
- `business_id`
- `company_id`
- `id_user`
- relationship tables such as `clients_users` or `user_institutions`

Do not assume the field name. Confirm the schema before changing a query.

## Tables That Must Be Scoped

High-priority Avomeal tables:

- `store_products`
- `store_categories`
- `store_carts`
- `store_cart_items`
- `store_orders`
- `store_order_items`
- `store_payments`
- `store_subscriptions`
- `store_subscription_items`
- `store_delivery_zones`
- `store_order_workflow`
- `store_order_tasks`
- `store_delivery_location_logs`
- `users`
- `clients_users`
- `payment_providers_credentials`
- `smtp_credentials`
- `brand_sites`
- `site_visibility`
- `brand_site_settings`

## Observed Schema Notes

- `users.id = 2` is the confirmed VNV Events/Avomeal business owner.
- `institution_profile.id_owner = 2` identifies VNV Events LLC.
- `store_products.id_owner` exists and should be `2` for Avomeal products.
- `store_orders.id_owner`, `store_order_items.id_owner` and `store_payments.id_owner` exist.
- Some shared records such as categories may contain `id_owner = NULL`; these are a risk if used as global Avomeal categories.
- Some owner `2` records can belong to VNV Events rather than Avomeal. Owner scope alone is not public visibility.

## Risk Rules

- If a query lacks scope, treat it as unsafe until reviewed.
- If a table lacks an owner field, document the risk before changing behavior.
- Do not hardcode `2` across random files; centralize through env/config.
- Do not migrate or rewrite shared records without a data plan.
- Do not delete VNV Events data while cleaning Avomeal data.
- Do not publish records to Avomeal unless `site_visibility` approves them.

## Recommended Env

```env
AVOMEAL_BUSINESS_USER_ID=2
SITE_KEY=avomeal
SITE_BUSINESS_USER_ID=2
AVOMEAL_BRAND_NAME="Avomeal"
AVOMEAL_PARENT_BRAND="VNV Events"
VNV_EVENTS_OWNER_ID=2
STORE_MINIMUM_ORDER_AMOUNT=65.00
```
