# Avomeal Context

Avomeal is the active food/store brand for this repo.

It should be understood as a public-facing gastronomy brand connected to VNV Events, not as a standalone database and not as generic Ophyra SaaS.

## Naming Correction

Avomeal is the real public brand. VNV Gourmet was an early temporary codename used before launch. Any remaining `vnv-gourmet` route, asset or legacy repository naming should be treated as technical history, not as customer-facing brand direction.

## Confirmed Scope

```text
id_user_business = 2
AVOMEAL_BUSINESS_USER_ID=2
VNV_EVENTS_OWNER_ID=2
```

This ID is confirmed as the business owner scope for Avomeal in the current shared database structure.

## Brand Position

Avomeal helps customers enjoy prepared meals, seasonal menus and party-ready food boxes without the stress of planning everything from scratch.

It serves:

- weekly prepared meals,
- meal prep customers,
- families,
- corporate groups,
- holiday gatherings,
- small celebrations,
- party hosts,
- customers who need food support connected to VNV Events.

## Commercial Pillars

### Meal Preps

Prepared meals, weekly orders, healthier options, family meals, corporate meal programs and recurring meal support.

### Holiday Menus

Seasonal menus for Thanksgiving, Christmas, New Year, Valentine's, Mother's Day, Father's Day, corporate holiday meals and family celebrations.

### Party Boxes

Appetizers, sweets, party snacks, dessert boxes, themed dinner kits, celebration boxes and small-event food kits.

## Commercial Rules

- Minimum order: `$65`.
- Products can be standalone or subscription-friendly.
- Store checkout should remain customer-facing and simple.
- Public branding should say Avomeal.
- Operational data must remain scoped to owner `2`.

## Relationship Summary

```text
Ophyra = parent/reference architecture
VNV Events = parent business owner and event operation
Avomeal = food/store brand under VNV Events scope
```

Avomeal can share users, store tables, payments, delivery structures, notifications and mobile patterns with the rest of the ecosystem, but it must keep its own public food-brand experience.
