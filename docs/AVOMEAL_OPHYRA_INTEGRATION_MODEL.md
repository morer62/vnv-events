# Avomeal Integration Model

> Current Avomeal note: Avomeal is now treated as the active food/store brand in this repo. It uses the same database universe as VNV Events and the confirmed business scope is `id_user_business = 2` / `id_owner = 2`.

## Purpose

This document defines how Avomeal should operate from the shared VNV Events/Ophyra-style database without becoming a separate unscoped system and without creating a parallel Store.

Avomeal is modeled as a food/store brand under the VNV Events business owner:

```text
VNV Events owner scope: 2
Avomeal food/store operation
Future Ophyra visibility/integration
jonnys.media Operations, future
```

Level 1 remains the business/admin owner. Avomeal and VNV Events share the confirmed business scope `2`; separation is handled by brand, Store structure, product/category organization and careful owner-scoped queries, not by a separate Avomeal database.

## Reference Sources

Reviewed:

```text
https://github.com/morer62/VNV_Gourmet
C:\Users\jonat\Downloads\ophyra_avomeal (5).sql
docs/STORE_COMMERCE_FLOW.md
docs/USER_COMPANY_ACCESS_MODEL.md
docs/TEAM_CHAT_DELIVERY_OPERATIONS.md
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

The legacy VNV_Gourmet repository name is treated as a historical/technical reference. The public brand direction is Avomeal, and the reference repo should not be copied blindly.

## What Avomeal Shows

Avomeal is already centered around a meal-prep Store experience:

* Level 1 home is focused on products, orders and people.
* Level 4 home is role-based for general support, kitchen/preparation and delivery.
* Level 4 can switch institution/company context.
* Level 4 uses store roles such as `general`, `kitchen` and `delivery`.
* Level 5 home focuses on orders, subscriptions, nutrition advisor, wellness advisor and ordering again.
* Store orders keep payment status separate from operational status.
* Subscriptions can be paused, resumed, edited and manually renewed.
* Manual subscription renewal creates a new Store order and Store payment.
* Checkout uses the business owner's active payment provider.
* Nutrition and wellness tools are customer-facing Avomeal-specific experiences.

Useful reference areas:

```text
src/views/panel/level1/home
src/views/panel/level4/home
src/views/panel/level5/home
src/views/panel/level1/planner-hub/store/subscriptions/home
src/views/panel/level5/store/subscriptions/home
src/views/panel/level5/store/orders/home
src/Repositories/StoreProductsRepository.php
src/Repositories/StoreOrdersRepository.php
```

## Avomeal Database Tables Analyzed

The Avomeal SQL contains Store and meal-prep structures that align strongly with Ophyra Store:

```text
store_products
store_products_nutrition
store_products_audiences
store_products_meal_styles
store_categories
store_products_categories
store_attributes
store_attribute_values
store_products_attributes
store_product_variations
store_product_variation_values
store_carts
store_cart_items
store_coupons
store_coupon_customers
store_coupon_redemptions
store_orders
store_order_items
store_order_workflow
store_payments
store_subscriptions
store_subscription_items
store_user_roles
payment_providers_credentials
users
clients_users
institution_profile
user_institutions
payroll_hours
chat_threads
chat_messages
```

These tables confirm that Avomeal should not be modeled as a separate commerce system. It should use Ophyra Store + Delivery with nutrition and meal-prep extensions.

## Core Decision

Avomeal should be modeled as:

```text
Store + Delivery
+ Nutrition fields
+ Meal-prep product filters
+ Weekly menus
+ Meal subscriptions
+ Delivery zones
```

It should not be:

* another Level 1 account,
* a separate tenant architecture,
* a duplicated checkout,
* a duplicated product/order schema,
* a separate payment engine.

## Business Operation Separation

Avomeal and VNV Events stay separated by company/owner scope.

Current Ophyra company model:

```text
users.id = business owner / operation owner
institution_profile.id_owner = business profile for that operation
id_owner = operational scope on Store, orders, payments, payroll and tasks
```

Recommended setup:

* VNV Events has the confirmed owner ID `2` and `institution_profile`.
* Avomeal operates under that same confirmed owner scope in this structure.
* Level 1 can access both contexts.
* Reports must filter by `id_owner`.
* Products, Store orders, payments, subscriptions and delivery data must never be queried globally without owner scope.

No historical Avomeal data should be migrated automatically until an import plan is reviewed.

## Level 1 Experience

Level 1 should see three clear areas:

### Ophyra Global Admin

```text
Users
Companies
Memberships
Modules
Payments
Affiliates / Referrals
Custom Domains
Manual Activations
Global Reports
Settings
```

### VNV Events Operations

```text
CRM
Clients
Orders
Contracts
Team
Payroll
Inventory
Tickets / RSVP
Business Profile
Reports
```

### Avomeal Operations

```text
Products / Meals
Nutrition
Meal Plans
Weekly Menu
Store Orders
Subscriptions
Delivery
Customers
Payments
Coupons
Reports
Business Profile
```

Technical routes can remain under existing Store and panel paths. The visible navigation should make the operation context obvious.

Implemented central-operation behavior:

* `src/Services/CentralOperationsContextService.php` resolves the active Level 1 operation context.
* `panel/home?operation=vnv_events` keeps Level 1 in VNV Events Operations.
* `panel/home?operation=avomeal` switches Level 1 to Avomeal Operations.
* Store admin routes keep their existing paths and accept the operation context through query string, for example:

```text
panel/planner-hub/store/products/home?operation=avomeal
panel/planner-hub/store/categories/home?operation=avomeal
panel/planner-hub/store/orders/home?operation=avomeal
panel/planner-hub/store/subscriptions/home?operation=avomeal
panel/planner-hub/store/payments/home?operation=avomeal
panel/planner-hub/store/coupons/home?operation=avomeal
```

The context should not mutate the login session owner. It should resolve the Avomeal business owner as `2` from configuration and pass that owner ID into Store repositories/controllers explicitly. This keeps Avomeal from becoming a separate Level 1 account and prevents global Store reads.

## Product Mapping

| Avomeal concept | Ophyra table/model |
| --- | --- |
| Meal/product | `store_products` |
| Product category | `store_categories`, `store_products_categories` |
| Nutrition facts | `store_products_nutrition` |
| Audience filters | `store_products_audiences` |
| Meal style filters | `store_products_meal_styles` |
| Flexible options | `store_attributes`, `store_attribute_values` |
| Product variations | `store_product_variations`, `store_product_variation_values` |
| Cart | `store_carts`, `store_cart_items` |
| Coupon | `store_coupons`, `store_coupon_customers`, `store_coupon_redemptions` |
| Store order | `store_orders`, `store_order_items` |
| Store payment | `store_payments` |
| Fulfillment workflow | `store_order_workflow`, `store_order_tasks` |
| Delivery tracking | `store_delivery_location_logs` |
| Meal subscription | `store_subscriptions`, `store_subscription_items` |
| Team store role | `store_user_roles` |
| Business payment provider | `payment_providers_credentials` |

## Nutrition Fields

Avomeal needs data that a generic Store does not require.

Base nutrition fields:

```text
calories
protein
carbohydrates
fat
fiber
sugar
sodium
serving_size
ingredients
```

Additional meal-prep fields prepared by `db/avomeal_ophyra_integration.sql`:

```text
allergens
diet_tags
portion_size
meal_type
spice_level
heating_instructions
storage_instructions
shelf_life
nutrition_notes
```

Use direct nutrition fields for facts that must be shown consistently on meal cards. Use attributes for flexible options such as sauce, side, size, spice add-on or custom meal preferences.

## Meal Plans And Subscriptions

Existing Avomeal-compatible structures:

```text
store_subscriptions
store_subscription_items
```

Supported behavior:

* weekly subscription records,
* linked customer by `id_user` or `email`,
* meal count,
* price per meal,
* minimum meals,
* next charge date,
* pause/resume/cancel status,
* subscription items,
* manual renewal into a Store order.

Future automation:

* scheduled weekly renewal job,
* pause windows,
* customer delivery-day preferences,
* subscription payment retry queue,
* automatic order generation with notification.

Do not implement global subscription cron until payment-provider behavior is tested with real Avomeal customers.

## Weekly Menus

Avomeal needs a weekly menu concept so the active meal list can change by week without deleting products.

Prepared support tables:

```text
store_weekly_menus
store_weekly_menu_products
```

These tables allow Avomeal to group existing `store_products` by week and owner. They do not replace categories or products.

## Delivery Zones

Avomeal needs delivery-zone rules for local meal-prep fulfillment.

Prepared support table:

```text
store_delivery_zones
```

It supports:

* owner/company scope,
* city/state/zip matching,
* delivery fee,
* minimum order,
* delivery days,
* pickup availability,
* active/inactive status.

Delivery execution still uses:

```text
store_order_workflow
store_order_tasks
store_delivery_location_logs
```

## Payments

Avomeal should use Ophyra Store payment behavior:

```text
payment_providers_credentials
PaymentProvidersRepository::getActiveProviderForOwner()
store_payments
```

Rules:

* The payment provider belongs to the confirmed business owner/company scope `2`.
* Stripe, Square and PayPal should reuse the existing Store checkout pattern.
* Do not create a separate Avomeal checkout.
* Do not write card numbers or CVV to Ophyra.
* Subscription renewals may charge saved tokenized cards only when the existing provider/customer token is valid.
* Store revenue stays in `store_payments`; Ophyra subscription revenue stays separate.

## Client And Team Behavior

Clients:

* Level 5 can see Avomeal Store orders and subscriptions by `id_user` or email.
* If the same customer also buys from another Ophyra operation, each row must show the selling business.
* Clients do not need to switch company context to see their own purchases.

Team Members:

* Level 4 can work for Avomeal through `user_institutions`.
* Store-specific role is stored in `store_user_roles`.
* `general`, `kitchen` and `delivery` are the key Avomeal roles from Avomeal.
* Team Members should use the unified work model described in `docs/TEAM_CHAT_DELIVERY_OPERATIONS.md`.

## Reports

Avomeal reports must be Business Operations reports scoped to owner ID `2`.

Avomeal reports can include:

* paid Store orders,
* pending Store orders,
* subscriptions active/paused/cancelled,
* upcoming subscription renewals,
* weekly menu product performance,
* abandoned carts,
* delivery tasks,
* failed payments,
* coupons used,
* customers by city/zip.

Do not mix Avomeal sales with:

* VNV Events revenue,
* Ophyra subscription revenue,
* module revenue,
* affiliate payouts.

## SQL

Run:

```text
db/avomeal_ophyra_integration.sql
```

The script is idempotent and safe to run repeatedly. It creates or confirms the Avomeal-specific Store support tables and adds missing nutrition fields when needed.

It does not import historical data.

## Operation Owner Resolution

Ophyra resolves Avomeal's owner/company in this order:

1. `AVOMEAL_OWNER_ID` from `.env`, if configured.
2. An `institution_profile` whose `company_name`, `business_nature` or `business_operation_type` indicates Avomeal, meal prep or nutrition.
3. Manual query-string override with `operation_owner_id={id}` for controlled admin testing.

VNV Events resolves from `VNV_EVENTS_OWNER_ID`, `STORE_OWNER_ID` or the current Level 1 owner fallback.

Every operational Store query for Level 1 should use the selected owner ID:

```text
store_products.id_owner
store_categories.id_owner
store_attributes.id_owner
store_attribute_values.id_owner
store_orders.id_owner
store_payments.id_owner
store_coupons.id_owner
store_subscriptions.id_owner
store_order_tasks.id_owner
store_delivery_location_logs.id_owner
```

## Migration Strategy

Recommended Avomeal migration phases:

1. Confirm the VNV Events owner/company ID `2` exists.
2. Confirm Avomeal public branding and Store configuration use owner `2`.
3. Configure Avomeal payment provider in `payment_providers_credentials`.
4. Run `db/avomeal_ophyra_integration.sql`.
5. Import categories and products into `store_categories` and `store_products`.
6. Import nutrition into `store_products_nutrition`.
7. Import audiences and meal styles into relation tables.
8. Import customers by email, avoiding duplicate global users.
9. Associate customers through `clients_users`.
10. Import active subscriptions only after payment-token mapping is reviewed.
11. Import historical orders only as read-only/audit rows if needed.

## Testing Checklist

Level 1 Ophyra Global Admin:

* Confirm Avomeal appears as a food/store brand under owner `2`.
* Confirm VNV Events and Avomeal reports stay separate.
* Confirm Ophyra platform metrics do not include Avomeal Store sales.

Level 1 Avomeal Operations:

* Create a meal product.
* Add nutrition facts.
* Add audience and meal-style tags.
* Add product to a weekly menu.
* Create a coupon.
* Review Store orders.
* Review subscriptions.
* Assign kitchen/preparation and delivery tasks.

Public / customer:

* Open Avomeal Store.
* Add meals to cart.
* Apply coupon.
* Checkout with the Avomeal payment provider.
* Confirm Store order and payment are created with owner ID `2`.
* Confirm Level 5 sees the order and selling business.

Delivery:

* Assign delivery.
* Start delivery with location permission.
* Log location.
* Mark delivered.
* Confirm customer can see delivery status/proof when allowed.

## Pending Work

* Add UI screens for `store_delivery_zones` and `store_weekly_menus`.
* Add a controlled import command for Avomeal product/customer/subscription data.
* Add subscription renewal cron only after payment-provider token behavior is verified.
* Add richer nutrition UI if current product editor only stores basic nutrition facts.
* Decide whether Avomeal website will call public Store routes directly or keep a thin frontend over Ophyra public Store endpoints.
