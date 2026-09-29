# Social Media Editorial & Publishing Agent

This agent converts recent published articles into reviewed social campaigns for the official Miami Tech Lab and VNV Events brand Pages. The name `BnB Events` is treated as a legacy alias for VNV Events. The Pasta Station is present in configuration but disabled.

## Safety and workflow

- Default mode: `REVIEW_BEFORE_PUBLISH`.
- Each run discovers the latest six valid articles per enabled brand, ranks them, selects three, and prepares a LinkedIn and Facebook adaptation for each.
- Every social copy retains the original article URL and its publication record retains the cover image URL.
- Duplicate brand/article/network/format combinations are rejected by the database.
- Approval cannot schedule a post unless the exact brand Page/network connection is verified.
- Tokens are stored through the existing encrypted connection repository. They are not written to source, output, or logs.
- The worker retries only transient timeouts, rate limits, and server failures. Authentication and permission failures stop for human correction.

## Installation

Run these migrations in order against the shared application database:

1. `db/20260828_growth_hub_multisite_agent_scope.sql` if multi-site agent scope has not already been installed.
2. `db/20260906_social_media_editorial_agent.sql`.

No new `.env` values are required. Configure each official Page from `/panel/social-media-agent` with its Page/organization identifier and access token, then use **Verify now**.

LinkedIn requires an approved Community Management integration, organization posting permission (`w_organization_social`), and an authenticated Page administrator/content administrator. Facebook requires a valid Page access token that can manage posts for the selected Page. A personal profile is never used as the publication target.

## Commands

Prepare a full run for review:

```bash
php src/cron/social-media-agent.php run REVIEW_BEFORE_PUBLISH
```

Prepare in automatic scheduling mode (still requires verified Page connections):

```bash
php src/cron/social-media-agent.php run AUTO_PUBLISH
```

Publish due, approved records:

```bash
php src/cron/social-media-agent.php publish-due 20
```

Suggested cron frequency for the worker is every five minutes. Editorial runs should be invoked once per weekly cycle; their proposed cadence is Monday/Wednesday/Friday at 10:00 in `America/New_York`.

## LinkedIn format boundary

The current production adapter publishes an official organization Page post that links to the complete original article. The orchestration labels this `standard_page_post`; it does not claim to create a LinkedIn newsletter edition or native long-form article. Native article content is supported by LinkedIn's Posts API only when the application has the corresponding approved API access. Until that capability is verified for the connected app, the linked Page post is the explicit fallback.

## Operational states

`DISCOVERED` -> `REVIEWED` -> `SELECTED` -> `PREPARED` -> `SCHEDULED` -> `PUBLISHED`

Any publication can instead become `SKIPPED` or `FAILED`. The panel and database preserve the run, source article, network, proposed time, external post identifier, retry count, and sanitized error message.
