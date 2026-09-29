# VNV Gourmet To Go

VNV Gourmet To Go is a commerce branch of VNV Events (`id_owner=2`, `site_key=vnvevents`). It is not The Pasta Station/`avomeal` and must never enable or move that catalog in bulk.

## Product eligibility

A product is purchasable only when it is active/public and explicitly has:

- `purchase_mode=DIRECT`
- `allow_immediate_payment=1`
- `fulfillment_type=DELIVERY`
- `allow_recurring_purchase=1` only when recurring delivery is permitted

Catalog-only products remain request-only.

Level 1 manages the serving model, cooking preferences and preparation/reheating/serving/plating/presentation instructions in the normal Store product create/edit forms. No SQL editing is required for those fields.

## Scheduling and money

- Business timezone: `America/New_York`.
- Delivery area: Miami-Dade County, Broward County and Boca Raton, Florida.
- Pickup origin: VNV Events / VNV Gourmet, 10258 NW 47th St, Sunrise, FL 33351 (`26.1832823, -80.2863131`).
- Sales tax configuration: 7%.
- Local delivery time and UTC are both stored.
- `store_gourmet_settings` controls minimum notice, delivery hours, T-48 lead time, price-change threshold, delivery markup/rounding and fallback tiers.
- A recurring parent stores the customer's schedule and configured basket.
- Occurrences store each local/UTC delivery time, charge time, expected total, actual total and payment/fulfillment state.
- Only a successfully paid occurrence creates an operational `store_order`.
- A material price change stops automatic billing and requests customer action.
- Stripe uses Customer + PaymentMethod + PaymentIntent. Raw card details are never stored.
- Checkout supports one-time and recurring frequency. A recurring checkout charges only the first scheduled delivery, saves an authorized Stripe PaymentMethod, and creates a limited future occurrence horizon.
- Customers can change or skip pending occurrences and pause, resume or cancel a recurrence from the existing Level 5 subscription area.
- Successful T-48 charges and payment-action-required failures send customer email notifications. A failed occurrence never enters preparation.

## Worker

Run every 10-15 minutes with PHP CLI:

```text
php src/cron/vnv-gourmet-recurring-billing.php
```

Read-only verification:

```text
php src/cron/vnv-gourmet-recurring-billing.php --dry-run
```

The worker uses a process lock, atomically claims eligible occurrences and sends an occurrence-specific Stripe idempotency key. If Stripe succeeds but local order persistence fails, the occurrence returns to a retryable state; Stripe returns the original PaymentIntent on retry rather than charging twice.

## Production prerequisites

Before activating recurring products:

1. Run `db/20260926_vnv_gourmet_to_go_foundation.sql`.
2. Configure active delivery zones or a live delivery provider. The approved area and pickup coordinates are already stored.
3. Confirm that the configured 7% tax policy is appropriate for every taxable line item before launch.
4. Confirm the active Stripe credential is the intended production account. Gourmet To Go deliberately resolves the same active business provider used by the normal Store/order checkout; it does not duplicate Stripe secrets.
5. Install the recurring billing cron.
6. Do not run the demo catalog SQL in production unless development products are intentionally wanted.

The demo catalog is in `db/20260926_vnv_gourmet_to_go_demo_catalog.sql` and all names/SKUs are explicitly marked `DEMO`.
