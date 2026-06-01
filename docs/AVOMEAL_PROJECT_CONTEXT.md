# Avomeal Project Context

> VNV Events repository note: this document describes a related brand, not the active launch target of this repo. Use it only when comparing shared architecture or Store/delivery behavior. VNV Events remains the primary context.

## What Avomeal Is

Avomeal is an independent food, nutrition and delivery brand.

Its public identity, customer experience and product positioning are separate from Ophyra. Avomeal can have its own website, public navigation, visual design, product presentation, checkout experience, customer messaging and brand assets.

Public domain:

```text
avomeal.com
```

Avomeal should be understood as a real operating business and public-facing brand, not as a generic Ophyra module.

## Business Purpose

Avomeal started with meal-prep and nutrition commerce, but the current direction is broader.

It should be understood as a food delivery and gastronomy brand that can support:

* prepared meals,
* weekly meals,
* nutrition-oriented products,
* food delivery to homes,
* party kits,
* themed dinners,
* food solutions for events or gatherings,
* products that can be sold through a public store.

Relevant business areas include:

* meal products,
* food categories,
* nutrition facts,
* ingredients,
* allergens,
* calories and macros,
* weekly menus,
* meal plans,
* subscriptions,
* customer orders,
* delivery zones,
* delivery tracking,
* customer purchase history,
* coupons and promotions,
* payments.

Avomeal needs Store + Delivery capabilities, but with specialized food, nutrition, weekly menu and meal-plan fields that a generic Store does not require.

## Repositories And Apps

Known repositories:

```text
morer62/VNV_Gourmet
morer62/vnv-gourmet-app
```

The `morer62/VNV_Gourmet` repository is the current Avomeal / VNV Gourmet web system reference. Its Level 1 home uses Avomeal/Gourmet-specific admin branding and should be treated as a food/delivery business interface, not a generic Ophyra module.

The `morer62/vnv-gourmet-app` repository is the related Expo/React Native mobile app. Its package name was observed as:

```text
avomeal-app
```

The mobile app generally provides login, signup, dashboard/navigation, WebView, token/session bridging and notification support for the Avomeal experience.

## Relationship With Ophyra

Ophyra can act as a central administrative system for Avomeal operations.

This means User Level 1 can monitor or manage Avomeal operational data from Ophyra when that data lives in the shared central database or follows the same administrative architecture.

Ophyra is not the public identity of Avomeal.

The correct relationship is:

```text
Avomeal = independent brand / operating business
Ophyra = central operations and administration layer
```

Avomeal can be connected to Ophyra because both can share database structures such as users, products, Store orders, payments, delivery workflows and customer records.

## Why Avomeal Appears In This Repository

Avomeal appears in the Ophyra repository because Ophyra is being prepared to centrally administer multiple operations from User Level 1.

The current central operations model is:

```text
Ophyra Global Admin
VNV Events Operations
Avomeal Operations
jonnys.media Operations, future
```

Avomeal is included here so Level 1 can manage its operational data without creating another isolated admin system or another Level 1 role.

## What Level 1 Can Monitor Or Administer

From Ophyra Level 1, Avomeal Operations can include:

* products / meals,
* categories,
* nutrition fields,
* audiences and meal styles,
* weekly menus,
* subscriptions,
* Store orders,
* delivery workflow,
* delivery tracking,
* customers,
* payments,
* coupons,
* reports,
* operational tasks.

These records must be scoped by the Avomeal operation owner, usually through `id_owner`, so Avomeal data does not mix with VNV Events data.

## What Remains Outside Ophyra

Avomeal can continue to have independent assets and systems outside the Ophyra core repository, including:

* public website,
* domain configuration,
* branding,
* logo and visual identity,
* marketing pages,
* customer-facing navigation,
* landing pages,
* public product storytelling,
* frontend design,
* social media assets,
* email branding,
* future Avomeal-specific dashboard or frontend.

Those pieces should not be treated as generic Ophyra UI unless the work explicitly targets Ophyra admin.

## Architecture Boundary

Avomeal should not be implemented as:

* another User Level 1,
* a separate duplicate Store system,
* a generic Ophyra add-on,
* a cloned VNV Events workspace,
* a brand hidden inside Ophyra navigation without its own identity.

Avomeal should be implemented as:

```text
Independent brand
+ shared Ophyra central database/admin where useful
+ Store + Delivery foundation
+ Nutrition / Meal Prep extensions
```

## User Levels In The Derived Avomeal Project

The Avomeal / VNV Gourmet codebase derives from Ophyra, but its practical user model is simpler:

* Level 1: owner/admin of the Avomeal brand.
* Level 4: team member / employee / collaborator.
* Level 5: customer.
* Level 6: marketing or specialized marketing user if used.

Do not assume Level 2 or Level 3 are active business flows in the Avomeal project unless the local code clearly uses them.

## Current Implementation Notes

The Ophyra central admin can switch into Avomeal operational context through Level 1 navigation.

Existing central routes may remain under technical paths such as:

```text
panel/planner-hub/store/products/home?operation=avomeal
panel/planner-hub/store/orders/home?operation=avomeal
panel/planner-hub/store/subscriptions/home?operation=avomeal
```

The visible label should be Avomeal Operations, even if the technical route still uses legacy `planner-hub` paths.

## Related Documentation

```text
docs/AVOMEAL_OPHYRA_INTEGRATION_MODEL.md
docs/STORE_COMMERCE_FLOW.md
docs/USER_COMPANY_ACCESS_MODEL.md
docs/TEAM_CHAT_DELIVERY_OPERATIONS.md
```
