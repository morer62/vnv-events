# VNV Events Project Context

> Avomeal web note: VNV Events is the parent business owner for Avomeal in this shared DB. The current active web context is Avomeal under `id_user_business = 2`.

## What VNV Events Is

VNV Events is an independent events and services business.

It has its own public identity, customer-facing presence, brand assets, domain, operational workflows and service experience.

Public domain:

```text
vnvevents.com
```

VNV Events should not be understood as merely an Ophyra module. It is a separate operating business that can be monitored and administered from Ophyra Level 1 where the database and operational architecture are shared.

## Repositories And Apps

Known repositories:

```text
morer62/vnv-events
morer62/vnv-mobile-app
```

The `morer62/vnv-events` repository is the current VNV Events web system reference. Its Level 1 home uses VNV-specific admin branding and event-operation language, so changes should preserve the event business identity.

The `morer62/vnv-mobile-app` repository is the related Expo/React Native mobile app. Its package name was observed as:

```text
vnv-events-app
```

The mobile app generally provides login, signup, dashboard/navigation, WebView, token/session bridging and notification support for the VNV Events experience.

## Business Purpose

VNV Events focuses on event and service operations.

Relevant business areas include:

* CRM,
* leads,
* clients,
* orders,
* services,
* contracts,
* files,
* payments,
* team members,
* payroll,
* inventory,
* tickets / RSVP,
* business profile,
* reports,
* operational tasks.

VNV Events is service-operation oriented, while Avomeal is meal-prep and commerce oriented.

## Relationship With Ophyra

Ophyra can act as the central administrative and operational layer for VNV Events.

This means User Level 1 can access VNV Events Operations from inside Ophyra to review or manage business data that lives in the shared system.

The correct relationship is:

```text
VNV Events = independent events/services brand and operating business
Ophyra = central operations and administration layer
```

Ophyra should make it clear when Level 1 is viewing Ophyra Global Admin metrics versus VNV Events Operations metrics.

## Why VNV Events Appears In This Repository

VNV Events appears in this repository because the historical system and the current Ophyra system share part of the same operational code, database and administrative structure.

Ophyra is evolving from a legacy Planner Hub / VNV Events-oriented system into a modular operations platform.

For that reason, VNV Events remains important inside the repository:

* it is a real operating business,
* it uses many existing workflows,
* it provides real operational use cases,
* Level 1 needs to operate it from the same central admin,
* its data must stay separated from Ophyra platform metrics and Avomeal metrics.

## What Level 1 Can Monitor Or Administer

From Ophyra Level 1, VNV Events Operations can include:

* business dashboard,
* CRM,
* clients,
* orders,
* contracts,
* team,
* payroll,
* inventory / storage,
* ticket sales / RSVP,
* payments,
* business profile,
* AI Advisor / business insights,
* reports,
* operational tasks.

These areas should be scoped to the VNV Events operation owner, usually through `id_owner`.

## What Remains Outside Ophyra

VNV Events can continue to have independent assets and systems outside the Ophyra core repository, including:

* public website,
* domain configuration,
* branding,
* logo and visual identity,
* marketing pages,
* customer-facing navigation,
* public service pages,
* venue/event-specific content,
* social media assets,
* email branding,
* future VNV-specific frontend experiences.

Those pieces should not be treated as generic Ophyra platform screens unless the work explicitly targets Ophyra admin or shared operations.

## Architecture Boundary

VNV Events should not be implemented as:

* a generic Ophyra module only,
* Avomeal data,
* Ophyra SaaS platform metrics,
* subscription revenue,
* affiliate payout data,
* a second isolated Level 1 system.

VNV Events should be implemented as:

```text
Independent event/services brand
+ shared Ophyra central admin where useful
+ Business Operations workflows
+ owner-scoped reports and operational data
```

## User Levels In The Derived VNV Events Project

The VNV Events codebase derives from Ophyra, but its practical user model is simpler:

* Level 1: owner/admin of the VNV Events brand.
* Level 4: team member / employee / collaborator.
* Level 5: client.
* Level 6: marketing or specialized marketing user if used.

Do not assume Level 2 or Level 3 are active business flows in the VNV Events project unless the local code clearly uses them.

## Ophyra Global Admin Versus VNV Events Operations

Ophyra Global Admin is for platform management:

* users,
* companies,
* memberships,
* modules,
* subscription payments,
* affiliates,
* custom domains,
* manual activations,
* system settings,
* global Ophyra reports.

VNV Events Operations is for business operations:

* orders,
* clients,
* CRM,
* contracts,
* team,
* payroll,
* inventory,
* tickets,
* collected business payments,
* operational reports.

These metrics must not be mixed.

## Current Implementation Notes

The Ophyra Level 1 dashboard and sidebar should expose VNV Events Operations as a clear operational area.

Existing technical routes may still use legacy paths such as:

```text
panel/planner-hub
panel/planner-hub/management/orders/orders
panel/planner-hub/management/users
panel/planner-hub/management/payroll
```

### Order And Team Clock Reliability Update (2026-07-22)

Level 1 order creation, order editing, suborder creation/editing, status updates and order-file uploads now use a shared asynchronous form layer. The UI blocks repeated submissions, shows progress, validates the final server response and performs an explicit redirect after success.

The Level 4 team clock uses the same protected asynchronous flow for clock-in and clock-out. Successful actions have an unambiguous server redirect so validation failures cannot be mistaken for successful saves.

Two blank-screen causes were corrected during the same pass:

- Dotenv values containing spaces must be quoted, for example `MAIL_FROM_NAME='VNV Events and Gourmet'`.
- JavaScript must read a form destination with `form.getAttribute('action')`; `form.action` can be shadowed by an input named `action`.

The complete local Chromium verification covered creating and editing an order, creating a suborder with services, changing status, uploading a file, and starting/stopping a signed team member's clock. Stripe test-card verification was intentionally skipped because the active VNV Events Stripe credential was production rather than sandbox.

The visible label should be VNV Events Operations or Business Operations, not generic Planner Hub.

## Related Documentation

```text
docs/OPHYRA_BUSINESS_MODEL.md
docs/ORDER_ACCESS_PAYMENT_FLOWS.md
docs/USER_COMPANY_ACCESS_MODEL.md
docs/TEAM_CHAT_DELIVERY_OPERATIONS.md
docs/ADMIN_USER_MODULE_REPORTS_CONTROL.md
docs/VNV_EVENTS_AI_AGENTS_ROADMAP.md
```
## AI Agent Approval Workflow (2026-07-26)

Agent outputs use a dashboard approval inbox with manual editing, AI correction cycles, version history and a final handoff to the corresponding module. Approval alone never sends or publishes.

## Private Large-Video Ingest (2026-07-27)

AI Video Studio accepts 500 MB–10 GB production originals through a private
SFTP inbox configured with `VIDEO_INGEST_PATH`. Level 1 displays the exact
owner directory and detects completed files inside project folders after a
stability window. Imports are idempotent and store non-public `vnv-local://`
references; no additional SQL is required.

Transcription now extracts compressed audio in 20-minute chunks so the original
is never loaded into PHP memory or submitted whole to the AI provider. Final
rendering reads private originals directly from disk. Operational setup and
capacity guidance are documented in `docs/VNV_VIDEO_RENDER_PRODUCTION.md`.
