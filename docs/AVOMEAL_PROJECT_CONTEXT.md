# Avomeal Project Context

Avomeal is the active food/store brand for this sprint.

## Confirmed Operating Model

```text
Avomeal = store / food brand / food operation under VNV Events
VNV Events = business owner / parent operation
id_user_business = 2
id_owner = 2
```

Avomeal no longer should be described as an isolated system with a separate database. It uses the same database universe as VNV Events and must always be scoped to the confirmed business owner.

Important: owner scope and public site visibility are separate. `id_owner = 2` means VNV Events owns or operates the record. It does not automatically mean the record belongs on the public Avomeal site. Public Avomeal content/configuration should use:

```text
site_key = avomeal
```

See `docs/SITE_SCOPE_MODEL.md`.

## What Avomeal Is

Avomeal is a public-facing food brand that can sell:

- meal preps,
- prepared meals,
- holiday menus,
- party boxes,
- appetizer boxes,
- sweets,
- themed dinner kits,
- standalone food products,
- subscription or recurring meal options.

Minimum order:

```text
$65
```

## Relationship With VNV Events

VNV Events owns the business scope. Avomeal operates as the food/store brand under that scope.

The public Avomeal experience should keep Avomeal branding. Operationally, Store data should be connected to owner `2`.

## Relationship With Ophyra

Ophyra remains the parent/reference architecture and possible future administrative layer.

Future Ophyra integration should recognize Avomeal as a store/food operation associated with VNV Events through:

```text
id_user_business = 2
```

Do not make Avomeal pages or checkout feel like generic Ophyra SaaS.

## Active User Model

- Level 1: business/admin owner under the VNV Events scope.
- Level 4: team/operations users where delivery, kitchen, preparation or support workflows apply.
- Level 5: customers.
- Level 6: CMS/marketing where used.

New customers should not become Level 2/3 business owners.

## Current Technical Priorities

1. Centralize `AVOMEAL_BUSINESS_USER_ID=2`.
2. Ensure Store queries filter by `id_owner = 2` and public visibility/configuration filters by `site_key = avomeal`.
3. Confirm categories/products/orders/payments/subscriptions never read globally without intent.
4. Enforce or document `$65 minimum order`.
5. Keep public copy focused on meal preps, holiday menus and party boxes.
6. Preserve VNV Events data and routes while making Avomeal safe inside the shared DB.
