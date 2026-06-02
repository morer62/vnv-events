# AI Content Assistant

First phase scope: blog posts and location pages only.

This implementation is completed first for VNV Events. It remains portable to Avomeal by changing the site settings/env profile.

The assistant can generate SEO ideas and drafts with the OpenAI API, but generated content must remain private until an admin reviews and publishes it manually. Do not implement service landing pages, forums, products, campaigns or store content in this phase.

## Core Rule

AI can generate ideas and drafts. Admin approval is required before publishing.

## Current Implementation

- Review panel: `/panel/ai-content/review`
- Cron script: `src/cron/ai-content-daily.php`
- Proposed SQL: `db/ai_content_assistant_required.sql`
- Draft repository: `src/Repositories/AiContentDraftsRepository.php`
- Settings repository: `src/Repositories/AiContentSettingsRepository.php`
- Generation service: `src/Services/AiContentAssistantService.php`
- Manual publish service: `src/Services/AiContentPublishingService.php`

The cron creates `NEEDS_REVIEW` drafts only. It does not publish.

Manual publish requires a draft status of `APPROVED`. Publishing creates either:

- `cms_contents` + `cms_routes` for blog posts.
- `cms_location_pages` for location pages.

Publishing also creates or updates `site_visibility` as `VISIBLE` for the published entity.

Reviewers can manually edit generated drafts before approval. Saving edits returns the draft to `NEEDS_REVIEW`; it still cannot publish until approved.

## Supported Sites

VNV Events:

```text
id_user_business = 2
id_owner = 2
site_key = vnv_events
brand = VNV Events
```

Avomeal portable profile:

```text
id_user_business = 2
id_owner = 2
site_key = avomeal
brand = Avomeal
```

When copying to Avomeal, set:

```text
AI_CONTENT_SITE_KEY=avomeal
```

VNV Events owner/business defaults are read from:

```text
AI_CONTENT_OWNER_ID=2
AI_CONTENT_ID_USER_BUSINESS=2
```

## Required Environment

```text
OPENAI_TOKEN=...
AI_CONTENT_ENABLED=true
AI_CONTENT_DAILY_BLOG_COUNT=1
AI_CONTENT_DAILY_LOCATION_COUNT=5
AI_CONTENT_AUTO_PUBLISH=false
AI_CONTENT_REQUIRE_APPROVAL=true
AI_CONTENT_DEFAULT_LANGUAGE=en
AI_CONTENT_REVIEW_PASSWORD=CHANGE_ME
AI_CONTENT_REVIEW_REMEMBER_DAYS=30
AI_CONTENT_SITE_KEY=vnv_events
AI_CONTENT_CLOUDINARY_ENABLED=true
AI_CONTENT_REDDIT_SOURCES_ENABLED=false
AI_CONTENT_MAX_PENDING_DRAFTS=50
AI_CONTENT_MODEL=gpt-4o-mini
```

`AI_CONTENT_AUTO_PUBLISH` is documented for clarity but forced off by the code in this phase.

If the base SQL was installed before the VNV Events profile seed existed, apply:

```text
db/ai_content_assistant_vnv_events_seed.sql
```

## Tables

The assistant expects these tables:

- `ai_content_settings`
- `ai_content_drafts`
- `ai_content_reviews`
- `ai_content_sources`
- `ai_content_assets`

Until SQL is applied, the panel shows warnings and avoids fatal errors.
