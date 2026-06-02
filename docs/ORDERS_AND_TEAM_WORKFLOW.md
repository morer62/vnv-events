# Orders And Team Workflow

Avomeal orders are Store orders under the VNV Events owner scope.

```text
id_user_business = 2
id_owner = 2
```

## Customer Order Flow

1. Customer browses Avomeal public Store.
2. Product and category reads must filter to owner `2`.
3. Cart is created with `store_carts.id_owner = 2`.
4. Checkout uses the active payment provider configured for owner `2`.
5. Store order is created with `store_orders.id_owner = 2`.
6. Store order items and payments are created with owner `2`.
7. Checkout enforces the Avomeal minimum order amount of `$65`.

## Admin Flow

Level 1 can manage:

- products,
- categories,
- attributes,
- carts,
- Store orders,
- payments,
- subscriptions,
- coupons,
- customer visibility,
- payment provider settings.

Admin Store screens should not show products, carts or orders from any owner other than `2`.

## Team Flow

Team members should see assigned work only.

For Avomeal this usually means:

- kitchen preparation,
- packing,
- delivery,
- customer notes,
- order status,
- chat or internal communication when enabled,
- location/tracking only with permission and clear user consent.

Do not expose global VNV Events operational orders inside the Avomeal team workspace unless the record is explicitly assigned and scoped.
