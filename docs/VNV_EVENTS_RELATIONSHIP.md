# Relationship With VNV Events

Avomeal operates under the VNV Events business owner in the shared database.

Confirmed owner:

```text
id_user_business = 2
id_owner = 2
```

## Correct Relationship

```text
VNV Events = parent business owner / operating company
Avomeal = food/store brand and product line
```

Avomeal can support VNV Events through catering, party boxes, holiday menus, event food kits and prepared food products, but the public Avomeal experience should still feel like Avomeal.

## Shared Areas

- users and clients,
- store products,
- carts,
- orders,
- payments,
- delivery,
- notifications,
- mobile app patterns,
- reporting,
- admin operations.

## Separation Rules

- Do not mix VNV Events event-service orders with Avomeal store orders unless a report intentionally combines them.
- Do not show VNV Events event-admin tools as Avomeal public UX.
- Do not use Avomeal pages to sell Ophyra SaaS.
- Keep owner scope explicit.
