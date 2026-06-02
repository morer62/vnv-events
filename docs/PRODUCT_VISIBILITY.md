# Product Visibility

Avomeal shares the database universe with VNV Events.

The confirmed business scope is:

```text
id_user_business = 2
id_owner = 2
```

## Current Rule

For this sprint, Avomeal public Store visibility is controlled by ownership plus explicit site visibility:

- `store_products.id_owner = 2`
- `store_products.site_key = avomeal`
- `store_products.status = ACTIVE`
- `store_products.is_public = 1`
- `site_visibility.entity_type = store_product`
- `site_visibility.is_visible = 1`
- `site_visibility.visibility_status = VISIBLE`
- product slug exists

Categories should also be scoped:

- `store_categories.id_owner = 2`
- `store_categories.site_key = avomeal`
- `store_categories.status = ACTIVE`
- `site_visibility.entity_type = store_category`
- `site_visibility.is_visible = 1`
- `site_visibility.visibility_status = VISIBLE`
- category slug exists

Do not show global products or categories in Avomeal unless they are intentionally assigned to `site_key = avomeal` and approved in `site_visibility`.

## Level 1 Control

Level 1 can review and approve/hide Avomeal visibility from:

```text
/panel/planner-hub/settings/site-scope
```

New or migrated records should default to `PENDING_REVIEW` until approved.

## Risk

Some inherited data may have `id_owner = NULL`. Those rows should be treated as unscoped legacy data and should not appear publicly in Avomeal until reviewed.

Some records with `id_owner = 2` can still belong to VNV Events rather than Avomeal. Do not approve them for Avomeal unless the product/category copy, image, SKU, pricing and operational use are clearly food/store related.
