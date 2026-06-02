# AI Content Settings

Settings can be controlled in `/panel/ai-content/review` after unlocking the review panel.

VNV Events is the default profile in this repo:

```text
AI_CONTENT_SITE_KEY=vnv_events
```

For Avomeal portability, switch to:

```text
AI_CONTENT_SITE_KEY=avomeal
```

## Stored Settings

- `enabled`
- `daily_blog_count`
- `daily_location_count`
- `default_language`
- `cloudinary_enabled`
- `reddit_sources_enabled`
- `max_pending_drafts`
- `priority_services`
- `priority_cities`
- `location_state`
- `site_key`
- `brand_name`
- `id_owner`
- `id_user_business`

These settings are scoped by `site_key`.

## Forced Settings

These are intentionally forced by code in this phase:

```text
auto_publish = 0
require_approval = 1
```

## Env Fallbacks

When DB settings are absent, the assistant uses `.env` values:

```text
AI_CONTENT_ENABLED
AI_CONTENT_DAILY_BLOG_COUNT
AI_CONTENT_DAILY_LOCATION_COUNT
AI_CONTENT_DEFAULT_LANGUAGE
AI_CONTENT_SITE_KEY
AI_CONTENT_CLOUDINARY_ENABLED
AI_CONTENT_REDDIT_SOURCES_ENABLED
AI_CONTENT_MAX_PENDING_DRAFTS
```

`OPENAI_TOKEN` is required for generation.
