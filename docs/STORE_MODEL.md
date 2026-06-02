# Store Model

Avomeal should operate as the food/store brand under VNV Events business scope.

Confirmed scope:

```text
id_user_business = 2
id_owner = 2
```

## Store Purpose

The store should support:

- meal preps,
- holiday menus,
- party boxes,
- appetizers,
- sweets,
- dinner kits,
- standalone products,
- recurring/subscription products,
- delivery or pickup where configured.

## Minimum Order

The current commercial rule is:

```text
$65 minimum order
```

This should be visible in customer-facing shopping flows and enforced in checkout logic when the store implementation supports it.

## Product Types

Avomeal products may include:

- individual meals,
- meal bundles,
- weekly meal plans,
- family meals,
- corporate meals,
- holiday packages,
- dessert boxes,
- appetizer boxes,
- themed dinner kits,
- catering-style packages.

## Store Tables

Primary tables:

- `store_products`
- `store_categories`
- `store_products_categories`
- `store_product_variations`
- `store_carts`
- `store_cart_items`
- `store_orders`
- `store_order_items`
- `store_payments`
- `store_subscriptions`
- `store_subscription_items`
- `store_delivery_zones`

All relevant rows should be scoped to `id_owner = 2` unless a table is intentionally global and documented.

## Relationship With VNV Events

VNV Events is the business owner. Avomeal is a branded food line/store under that owner.

Operational reporting can later roll up into VNV Events, but public pages and customer messaging should keep the Avomeal identity.
