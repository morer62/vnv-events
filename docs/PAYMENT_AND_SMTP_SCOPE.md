# Payment And SMTP Scope

Avomeal and VNV Events can share the same owner and database, so payment and email settings must be selected by site.

Confirmed Avomeal values:

```text
id_user_business = 2
id_owner = 2
site_key = avomeal
```

## Payment Provider

Avomeal should use:

```text
brand_site_settings.active_payment_provider_id
```

The selected provider must also satisfy:

```text
payment_providers_credentials.id_owner = 2
payment_providers_credentials.site_key = avomeal
payment_providers_credentials.is_active = 1
```

If `active_payment_provider_id` is missing, code can fall back to the active/default provider scoped to `site_key = avomeal`, but this should be treated as a launch warning.

If more than one Avomeal provider is active, Level 1 should choose the exact provider in Site Scope settings.

## SMTP

Avomeal should use:

```text
brand_site_settings.active_smtp_id
```

The selected SMTP row must also satisfy:

```text
smtp_credentials.id_owner = 2
smtp_credentials.site_key = avomeal
smtp_credentials.is_active = 1
```

If `active_smtp_id` is missing, code can fall back to the active/default SMTP scoped to `site_key = avomeal`, but this should be treated as a launch warning.

Do not send Avomeal emails with VNV Events event-service branding unless Level 1 intentionally configured that SMTP identity for Avomeal.

## QA Queries

```sql
SELECT id, provider_type, provider_name, is_active, is_default
FROM payment_providers_credentials
WHERE id_owner = 2 AND site_key = 'avomeal';

SELECT id, provider_name, from_email, from_name, is_active, is_default
FROM smtp_credentials
WHERE id_owner = 2 AND site_key = 'avomeal';

SELECT setting_key, setting_value
FROM brand_site_settings
WHERE site_key = 'avomeal'
  AND setting_key IN ('active_payment_provider_id', 'active_smtp_id');
```
