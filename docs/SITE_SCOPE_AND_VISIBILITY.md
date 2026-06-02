# Site Scope And Visibility

Avomeal Web runs in a shared database with VNV Events.

Use this model:

```text
Ownership: id_user_business / id_owner
Site assignment: site_key
Public visibility: site_visibility
Site configuration: brand_site_settings
```

Confirmed Avomeal values:

```text
id_user_business = 2
id_owner = 2
site_key = avomeal
```

## Rule

Do not show a record publicly just because `id_owner = 2`.

Public Avomeal records must be assigned to `site_key = avomeal` and approved in `site_visibility` as:

```text
is_visible = 1
visibility_status = VISIBLE
```

Rows without a visibility decision should remain hidden or `PENDING_REVIEW`.

## Level 1 Panel

The current admin route is:

```text
/panel/planner-hub/settings/site-scope
```

It can:

- seed missing `site_visibility` rows as `PENDING_REVIEW`,
- show visibility counts by entity type,
- approve records,
- hide records,
- archive records,
- edit site-level settings.

## Site Settings

`brand_site_settings` stores settings such as:

- `minimum_order_amount`
- `active_payment_provider_id`
- `active_smtp_id`
- `storefront_enabled`
- `brand_logo`
- `brand_primary_color`
- `brand_secondary_color`
- `seo_title`
- `seo_description`

Code should read from this table first, then use environment/default fallbacks only for resilience.

## Public Queries

Public Store, CMS, location and forum repositories now use `site_visibility` when that table exists. Admin queries use `site_key` so Level 1 can see pending records and make decisions.

## Launch Warning

If an early migration assigned many owner `2` records to `site_key = avomeal`, keep them in `PENDING_REVIEW` until reviewed. This is safer than publishing VNV Events content inside Avomeal.
