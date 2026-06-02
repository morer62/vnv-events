# Store / Commerce Flow

> Avomeal web note: Store/catalog behavior is shared architecture. In this repo it should be exposed as Avomeal food-store behavior under the confirmed VNV Events owner scope `id_user_business = 2` / `id_owner = 2`.

## Purpose

Store / Commerce is Ophyra's paid commerce module. A business can publish products, manage stock, accept coupons, collect customer payment and review fulfillment without creating a checkout system separate from the existing payment-provider configuration.

Avomeal uses this same Store foundation as a specialized meal-prep Business Operation. Its nutrition, weekly menu, subscription and delivery-zone model is documented in:

```text
docs/AVOMEAL_OPHYRA_INTEGRATION_MODEL.md
```

The technical add-on slug is:

```text
store_delivery_tracking
```

The public services catalog uses:

```text
services
```

Both read their monthly price from:

```text
OPHYRA_MODULE_STORE_DELIVERY_TRACKING_MONTHLY
```

## Areas And Routes

Public Store:

```text
/store
/store/cart
/store/checkout
/store/order-access?token=...
/store/payment-successful
```

These public URLs are compatibility wrappers around the existing implementation in:

```text
src/views/public/commerce/store
```

Administration:

```text
src/views/panel/level1/planner-hub/store
src/views/panel/level2/planner-hub/store
```

Customer portal:

```text
src/views/panel/level5/store
```

Technical `/planner-hub/` paths remain unchanged for compatibility.

Mobile/API auth, Expo Push Token registration and notification behavior are documented separately in:

```text
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

## Access Rules

* Level 1 has full Store administration access by default.
* Level 2 can open Store administration only when `store_delivery_tracking` is ACTIVE in `user_modules` and the owner membership permits paid module access.
* Level 2 without Store activation sees Store as an available add-on in Billing & Modules, but not as an operational sidebar tool.
* Level 5 can buy from the public Store and review associated purchases.
* The public catalog, cart and checkout also verify the selling owner's Store entitlement. A Level 1 owner bypasses activation for testing.
* A free basic Business Profile is allowed without payment. Store and Services remain paid features.

## Catalog

Products are stored in `store_products` and scoped by `id_owner`. A product can be fixed-price or variable. Products support SKU, description, public visibility, status, image, price, promotional price, stock and purchase quantity limits. Public variable products expose an option selector and persist the selected variation into the cart.

Categories use:

```text
store_categories
store_products_categories
```

Attributes and variations use:

```text
store_attributes
store_attribute_values
store_products_attributes
store_product_variations
```

The administration views create, edit, activate, deactivate and remove catalog records through Store repositories. Level 1 Store administration now uses an explicit central-operation owner context so VNV Events and Avomeal catalog records stay separated by `id_owner`.

## Cart And Coupons

The public catalog stores the current selection in browser local storage and sends it to `/store/cart`. The server validates each public product, variation, price, stock and quantity before persisting:

```text
store_carts
store_cart_items
```

Coupons use:

```text
store_coupons
store_coupon_customers
store_coupon_redemptions
```

Coupons support active dates, active/inactive status, percentage or fixed discounts, minimum total, total usage limit, per-customer limit and optional customer assignment.

## Checkout And Payment Providers

Checkout resolves the business owner from the cart. The owner ID is preserved from the public catalog through cart and checkout so the payment provider belongs to the company selling the product.

The active provider comes from:

```text
payment_providers_credentials
PaymentProvidersRepository::getActiveProviderForOwner()
```

Supported providers:

```text
stripe
square
paypal
```

Stripe and Square tokenize card details in the browser. PayPal creates an order through the same `/store/checkout` controller and captures the approved PayPal order through `PayPalProvider`.

If the selling company has no active provider, checkout shows a clear configuration error and does not attempt a real payment.

## Store Orders And Payments

Before charging, checkout revalidates available stock. After successful payment it creates:

```text
store_orders
store_order_items
store_payments
```

It then marks the order paid, changes the order to PROCESSING, decrements product or variation stock, converts the cart and emails the customer an order-access link.

Store payment history is stored in `store_payments`. It is intentionally not written to `payments_all` because the current `payments_all.concept` enum does not include Store. `payments_all` remains reserved for its existing audited flows until that schema is intentionally expanded.

## Unified Team Operations

Store fulfillment is connected to the Team Member experience without merging Store rows into service-order tables.

```text
store_order_workflow
store_order_tasks
store_delivery_location_logs
```

`store_order_workflow` stores the current preparation and delivery assignments, permission flags, latest delivery coordinates and delivery proof. `store_order_tasks` stores operational work items such as preparation, assistance, delivery, fulfillment, customer support and other tasks. `store_delivery_location_logs` stores location updates only while a delivery task is actively executed.

The Store payment state and operational state remain separate:

```text
payment_status = PENDING | PAID | FAILED | REFUNDED
status = NEW | CONFIRMED | PROCESSING | IN_PREPARATION | READY |
         READY_FOR_DELIVERY | OUT_FOR_DELIVERY | DELIVERED |
         COMPLETED | CANCELLED
```

Level 1 and Level 2 use the Store Orders board to assign preparation and delivery, create additional work items, allow or deny team-member delivery closure, allow contextual customer communication and review latest tracking coordinates. Level 4 sees Store and service/event assignments together under `My Work`. Level 5 sees fulfillment status, latest delivery location while in transit and delivery proof when available.

Store reporting is surfaced through `BusinessOperationsReportService`. Paid Store revenue comes only from `store_payments.status = PAID`. Open carts are reported separately from paid Store orders and can be reviewed from the existing abandoned-carts view.

## Level 5 Customer Association

Public checkout looks up the customer globally by email. If the customer already exists, it associates that user with the selling business through `clients_users`. If the customer does not exist, it creates a Level 5 account, creates the association and emails temporary access credentials.

This avoids duplicating a global client record when the same client buys from more than one business.

Level 5 Store history should show all Store purchases tied to the logged-in user or email and display the selling business for each order. A company selector may help focus chat or business contact, but it must not hide a client's own purchases from other companies.

## SQL Required

Run:

```text
db/store_commerce_required.sql
db/store_team_delivery_operations.sql
db/final_stabilization_required.sql
db/avomeal_ophyra_integration.sql
```

The first script safely adds `store_products.id_owner` and its indexes only when missing, then registers or refreshes the paid `services` module. The second extends Store fulfillment with operational statuses, Team assignments and location logs. The final stabilization script adds conservative support tables referenced by legacy reports/services. The Avomeal script confirms meal-prep nutrition, audience, meal-style, subscription, weekly menu and delivery-zone support. These scripts are idempotent and can be run again.

## Testing Checklist

1. Level 1 opens Store products, categories, attributes, coupons, orders and payments for VNV Events and then for Avomeal using `?operation=vnv_events` / `?operation=avomeal`.
2. Level 2 without `store_delivery_tracking` is redirected to Billing & Modules.
3. Level 2 with ACTIVE `store_delivery_tracking` opens Store administration and sees the Store sidebar link.
4. Public `/store?owner=2` lists public in-stock products.
5. Add a fixed product to cart and continue to checkout.
6. Confirm an out-of-stock product cannot be purchased.
7. Apply a valid coupon and reject expired, inactive or exhausted coupons.
8. Test Stripe, Square and PayPal with sandbox credentials configured for the selling owner.
9. Confirm `store_orders`, `store_order_items` and `store_payments` records are created after payment.
10. Confirm stock decreases only after an approved payment.
11. Log in as the Level 5 customer and review Store order history.
12. Assign preparation and delivery from the Level 1 or Level 2 Store Orders board.
13. Log in as the assigned Level 4 user and open `My Work`.
14. Confirm delivery cannot start without browser location permission.
15. Mark a delivery in transit, confirm the latest map link appears for admin and client, then close delivery with an optional proof photo.

## Pending Risks

* Provider sandbox credentials must be tested per business before production activation.
* `store_payments` is the Store audit trail until `payments_all` receives an explicit Store concept migration.
* Existing legacy meal-plan subscription screens remain compatible but need a separate subscription-renewal QA pass before broad production use.
* Public Business Profile quote requests are shown and accepted only when the selling owner has the paid `services` module. Legacy venue/vendor quote flows remain unchanged.
* Avomeal must use the confirmed owner/company scope `2` before importing products or orders. Do not read/write Store data globally.
