# VNV Events AI Agents Roadmap

Last updated: 2026-07-26

## Purpose

Level 1 will include a private area named **Agents**, visually and navigationally aligned with Growth Hub.

The reference screenshot that mentions “BNB Events” must be interpreted as **VNV Events**.

This area must be extensible: each agent appears as an independent card and opens its own functions and settings.

## Shared Agent Interface

Each agent should support, where applicable:

- name and description;
- status: `DRAFT`, `ACTIVE`, `PAUSED` or `ERROR`;
- manual execution;
- last execution and next scheduled execution;
- configuration;
- execution history and errors;
- pending human approvals;
- webhook URL with a copy control;
- signed webhook secret with rotation support;
- AI usage or cost information.

Webhook secrets must not be exposed as plain public URLs. Integrations must use signed requests, scoped credentials and recoverable secret rotation.

## Initial Agents And Tools

### AI Video Studio

- Upload source videos.
- Generate synchronized transcriptions.
- Create editable automatic subtitles.
- Edit video through transcript selections.
- Support undo and redo.
- Suggest assisted color corrections.
- Generate or insert contextual images and clips.
- Manage reusable intros, outros and overlays.
- Produce short-form vertical clips.

OpenAI can assist with transcription, scripts, summaries and editing decisions. Rendering and media processing will also require a video pipeline such as FFmpeg and suitable media storage.

### Blog Writer

- Reuse and automate the existing Growth Hub content creator.
- Generate ideas, articles, SEO metadata, JSON Schema and images.
- Preserve the existing human review and publishing workflow.
- Avoid duplicating the current CMS implementation.

### Social Publisher

- Adapt approved articles for Facebook and LinkedIn.
- Generate platform-specific copy and previews.
- Support scheduling and approval before publication.

### Instagram Carousel

- Extract key points from an approved article.
- Generate a cover, slides, caption and hashtags.
- Export or publish after approval.

### Short Video Agent

- Convert long videos or articles into Reels, Shorts and TikTok-ready clips.
- Select relevant moments.
- Generate subtitles and vertical `9:16` layouts.
- Prepare publication copy.

### Meta Lead Estimator

- Process authorized Meta conversations.
- Identify requested services, date, guest count, location and budget.
- Prepare an estimate draft.
- Flag missing or contradictory information.

### Estimate Follow-up Agent

- Evaluate estimates daily.
- Classify them as urgent follow-up, normal follow-up, wait or omit.
- Prepare a suggested follow-up message.
- Explain why each estimate received its classification.

## Future Agents Approved For Later Implementation

### Event Brief Agent

Transform conversations, documents and order information into an event brief, timeline, checklist, playlist requirements, services and team tasks.

### Lead Qualification Agent

Score leads using intent, event date, budget, location, requested services and estimated probability of conversion.

### Contract And Order Auditor

Detect incomplete orders, unsigned contracts, pending payments, missing files and contradictory information.

### Content Refresh Agent

Find outdated articles, broken internal links, incomplete Schema, weak metadata and SEO content needing revision.

### Post-Event Agent

Organize event photos and videos and prepare albums, recaps, blog articles and social content.

### Review And Reputation Agent

Prepare post-event review requests and suggested responses to received reviews or comments.

### Operations Risk Agent

Alert Level 1 about upcoming events lacking team assignments, inventory, payments, acceptance, contracts or essential information.

### Client Concierge Agent

Answer frequently asked questions using only confirmed client, order and event information.

## Safety And Approval Policy

The initial default for consequential actions is **prepare for approval**.

Human approval is required before an agent:

- sends a client or lead message;
- publishes an article or social post;
- creates or sends a final estimate;
- changes an order or contract;
- initiates a payment-related action;
- deletes content or operational records.

Full automation can be enabled later per agent and per action after its accuracy, permissions, audit logs and recovery behavior have been verified.

## Approval And Revision Workflow

Every agent output that could be sent, published or applied must enter the shared approval inbox.

1. The agent creates a draft with contextual identifiers and a link to its corresponding VNV module.
2. Level 1 sees the pending total and recent drafts on the main dashboard, the Agents catalog and the individual agent page.
3. The reviewer can edit the main draft manually, reject it, approve it or enter correction instructions.
4. Correction instructions produce a new version through OpenAI. The previous version becomes `REVISION_REQUESTED`; the new version becomes `PENDING` and appears again in the dashboard.
5. Approval synchronizes safe draft fields with the corresponding module when supported, but does not send or publish.
6. A second explicit **Execute final action** click publishes, sends, queues or creates the approved result. A distributed lock prevents double execution and every attempt is audited.

The approval history retains every version, correction instruction, reviewer and decision for auditability.

## Recommended Implementation Order

1. Shared Agents infrastructure and navigation.
2. Blog Writer integration with Growth Hub.
3. AI Video Studio.
4. Estimate Follow-up Agent.
5. Meta Lead Estimator.
6. Social Publisher and Instagram Carousel.
7. Short Video Agent.
8. Future operational agents according to business priority.

## Implemented Foundation (2026-07-26)

- Level 1 Agents catalog, detail pages, configuration, history and approvals.
- Signed webhook endpoints with secret rotation.
- Manual and scheduled execution infrastructure.
- Daily scheduler entry point: `php src/cron/ai-agents-scheduler.php`.
- Blog Writer shortcut into the existing Growth Hub AI workflow.
- Video Studio uploads, OpenAI transcription, editable transcript/SRT, undo/redo and non-destructive AI edit plans.
- Blog and Video Studio support encrypted OpenAI, Claude and Gemini provider connections with independent text/image models and defaults.
- Blog optimization can use any configured text provider and optionally regenerate its thumbnail with OpenAI or Gemini before approval.
- Video plans include hooks, animated-caption direction, trend adaptation, color/audio notes, B-roll, intro/outro and logo treatment. Reusable brand assets can be uploaded once; the selected logo is rendered into the final MP4 with an animated intro.
- The `Marketing Educator` preset adapts the concise, high-contrast educational structure associated with successful marketing channels: immediate hooks, purposeful jump-cut plans, VNV-colored captions/callouts, charts, normalized voice audio, platform copy and thumbnail concepts. It does not copy another creator's branding or likeness.
- Queued FFmpeg final rendering with burned captions, aspect-ratio export and Cloudinary output. Production setup is documented in `docs/VNV_VIDEO_RENDER_PRODUCTION.md`.
- Operational readers for estimate follow-up, event briefs, order auditing, content refresh and event risk.
- Facebook Pages, Instagram Professional accounts/carousels and LinkedIn publishing connectors support encrypted credentials, live verification and explicit post-approval publication.
- Final actions use execution locks, exponential retries and an audit log. AI calls record provider/model token usage; image calls record generation usage.
- Approval-ready notifications always create a dashboard bell and can additionally send email/mobile push when `AI_AGENT_NOTIFY_EMAIL` and `AI_AGENT_NOTIFY_PUSH` are enabled.
- Schedule expressions support `DAILY`, `WEEKLY` and five-field cron syntax with IANA timezones.
- Signed Meta Lead Ads webhook receiver: `/api/agents/meta-webhook`.

## Completed Agent Engines (2026-07-26)

All 15 catalog agents now have a working local engine or a dedicated workspace:

- Social Publisher creates separate Facebook and LinkedIn drafts from selected CMS content.
- Social Publisher asks which networks to use on every run (Facebook, Instagram and/or LinkedIn) and stores an independent encrypted connection for each platform. Access tokens are never rendered back to the browser.
- Instagram Carousel produces cover copy, slides, visual prompts, caption and hashtags.
- Short Video reads ready/completed Video Studio projects and submits them for review.
- Meta Lead Estimator analyzes authorized CRM conversation history and prepares an estimate brief without inventing missing information.
- Lead Qualification scores active CRM leads and queues higher-priority leads for review.
- Post-Event reads private event-execution photos and prepares a recap draft.
- Review & Reputation prepares review-request messages for recent events.
- Client Concierge produces grounded responses from a selected order and flags unconfirmed answers.
- Event Brief, Estimate Follow-up, Order Auditor, Content Refresh and Operations Risk create contextual review tasks linked back to the corresponding order or CMS module.

Facebook, Instagram and LinkedIn publication is available as a separate explicit final action after approval. It remains unavailable until each production account is saved and passes **Verify now**.

## Growth Hub And Automations Separation

Growth Hub is the production workspace and contains five complete studios:

1. Article Studio.
2. Video Studio.
3. Social Publisher.
4. Instagram Carousel.
5. Short Video Studio.

Automations is the orchestration layer. It runs those same engines manually, by schedule or signed webhook, creates approval tasks and links results back to the appropriate Growth Hub, CMS, CRM or order workspace. The former `/panel/agents/video-studio` URL redirects to `/panel/growth-hub/video-studio` for backward compatibility.

Run `db/vnv_ai_agents_required.sql` before opening the module. Configure these CLI entries once per minute:

```bash
php src/cron/ai-agents-scheduler.php
php src/cron/ai-video-render-worker.php 1
php src/cron/ai-approval-retry-worker.php 10
php src/cron/ai-conversation-worker.php 5
php src/cron/ai-editorial-planner.php
```

Optional production settings:

```dotenv
AI_AGENT_NOTIFY_EMAIL=false
AI_AGENT_NOTIFY_PUSH=false
YOUTUBE_API_KEY=
META_WEBHOOK_VERIFY_TOKEN=
META_APP_SECRET=
META_WEBHOOK_OWNER_ID=2
META_GRAPH_VERSION=v23.0
LINKEDIN_API_VERSION=202605
```

Each enabled agent maintains its own timezone-aware `next_run_at`. Consequential actions remain approval-first.

## Integrated Production Expansion

- Approval Center groups social publications, estimates, reminders, content and operations into navigable tabs with safe batch approval/execution.
- Editorial Automation stores weekly quotas for articles, location pages, general pages, social posts and videos and creates a modifiable seven-day plan from current content and public format signals.
- Social Publisher accepts a CMS article/page, a completed video project or current YouTube format signals and prepares Facebook, Instagram, LinkedIn and YouTube output.
- Video Studio treats every upload as a reusable project with revision history, remix duplication, caption/SRT editing, phrase-list cleanup, transcript-selection removal and timestamp-level removal from the rendered media.
- Timed visual instructions can generate reusable OpenAI/Gemini visual inserts that are composited only at approved timestamps.
- CMS AI creation can start from zero or search an existing VNV article, page or location page as its structural reference. Existing generated content can be routed to OpenAI, Claude or Gemini for revision before publication.
- Meta webhook intake covers Lead Ads, Messenger, Instagram messaging and WhatsApp. Conversations are stored, grounded responses are drafted from published VNV content, and replies/estimate creation require approval.
- Estimate creation verifies the client by email, creates a Level 5 client when absent, creates an `INVOICE_DRAFT` order and matches requested services against the VNV order-service catalog.
# Final QA and operability notes — 2026-07-26

- The Level 1 agent catalog distinguishes local draft capability from missing external publishing connectors.
- Approval creation deduplicates matching pending actions for the same agent, action type and title.
- Approval Center is paginated at 30 items, searchable, filterable and supports select-current-page bulk actions.
- Agent runs automatically move from `AWAITING_APPROVAL` to `COMPLETED` after their final pending approval is decided.
- Chromium coverage includes the agent catalog, individual agents, approvals, Meta conversations, Growth Hub, Distribution Studio, Video Studio and editorial settings.
- Operational runs were verified for scheduled agents and contextual agents using existing scoped owner data.
- Facebook, Instagram, LinkedIn, YouTube and WhatsApp publishing remain intentionally unavailable until their per-network encrypted credentials are configured and verified.
