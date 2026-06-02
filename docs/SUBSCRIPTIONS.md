# Subscriptions

Avomeal supports subscription-friendly meal ordering.

Subscriptions are part of the Store/food model, not Ophyra SaaS membership billing.

## Scope

```text
id_user_business = 2
id_owner = 2
```

Relevant Store subscription records must be owner-scoped.

## Expected Behavior

Admin can review subscriptions by owner `2`.

Customers can view their own subscriptions in the Level 5 Avomeal area.

Recurring charges should use the active payment provider for owner `2`.

Subscription renewals must never run globally across all owners without explicit owner filtering.

## Commercial Position

Subscriptions are one part of Avomeal, alongside:

- individual products,
- meal preps,
- holiday menus,
- party boxes,
- appetizers,
- sweets,
- dinner kits.

The storefront minimum order remains `$65` unless a future subscription-specific rule is explicitly configured.
