# Subscriptions And Orders

Avomeal can support one-time orders and recurring/subscription-style ordering.

Confirmed scope:

```text
id_user_business = 2
id_owner = 2
```

## Order Types

- one-time meal prep order,
- party box order,
- holiday menu order,
- individual product order,
- subscription or recurring meal order,
- corporate or family recurring food support.

## Store Order Tables

- `store_orders`
- `store_order_items`
- `store_payments`
- `store_order_workflow`
- `store_order_tasks`
- `store_delivery_location_logs`

## Subscription Tables

- `store_subscriptions`
- `store_subscription_items`

Do not enable automated subscription renewal until payment-provider token behavior has been reviewed and tested.

## Customer Rules

- Existing customers should not be duplicated unnecessarily.
- New public customers should be Level 5/client-style users, not business owners.
- Client associations must remain scoped to the VNV Events/Avomeal business owner.

## Minimum Order

Checkout should respect:

```text
$65 minimum order
```

If the current checkout does not enforce it, document that as a launch gap before adding logic.
