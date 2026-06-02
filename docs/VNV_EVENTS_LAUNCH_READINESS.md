# VNV Events Launch Readiness

> Avomeal web note: this is now reference material from the VNV Events sprint. The current repo context is Avomeal Web operating under VNV Events owner `2`.

## Project Identity

This reference document was written when the sprint focused on VNV Events.

VNV Events is a real event planning, event production and event services business operating publicly at:

```text
https://vnvevents.com
```

The codebase may share database structures, routes, services and historical modules with Ophyra, VNV Venues, Avomeal or older Planner Hub work. Those shared pieces are implementation history, not the current product identity.

The launch goal is to make the system feel and operate as VNV Events: a live event operations platform for orders, clients, team members, payments, contracts, schedules, CMS, public content, forums and SEO.

## Active User Levels

### Level 1

Owner/admin of the VNV Events operation.

Primary responsibilities:

- Manage orders and estimates.
- Review calendar/schedule.
- Manage clients.
- Manage team members.
- Manage contracts, files, payments and reminders.
- Review team payroll and clock activity.
- Manage CMS content, blog posts, location pages and forums.
- Regenerate SEO/AI files.
- Manage music sessions and event invitations.

### Level 4

Team member, employee or collaborator.

Primary responsibilities:

- View assigned jobs/orders.
- Use the schedule/calendar.
- Clock in and out with geolocation when required.
- Review pending/paid hours.
- Use team chat.
- Access storage/files when authorized.
- Review operational store/kitchen/delivery tasks when applicable.

### Level 5

Client/public account.

Primary responsibilities:

- View their own VNV Events orders.
- Open order files, contracts and payment links.
- Participate in public forums when logged in.
- Review tickets and event invitations when applicable.
- Access planning tools and music sessions when exposed.

### Level 6

Marketing/CMS operator.

Primary responsibilities:

- Manage CMS pages, blog posts, templates and location pages.
- Use marketing tools.
- Access the SEO / AI Control Center.

## Active Launch Modules

- Level 1 dashboard and navigation.
- Planner Hub business operations routes.
- Orders, estimates, suborders, payments, contracts and files.
- Calendar/agenda view for orders.
- Clients and team members.
- Team chat.
- Payroll/time clock.
- Music sessions.
- CMS pages, blog posts, templates and location pages.
- Public forums.
- SEO Center, sitemap, robots, llms and llms-full files.
- Public service/location/blog/forum/store routes.
- Public event request popup and Level 1 request intake/archive workflow.

## Legacy Or Shared Modules

The repository still contains code related to:

- Ophyra SaaS/platform administration.
- Avomeal store and nutrition flows.
- Venue/vendor marketplace flows.
- Level 2 and Level 3 business/venue/vendor flows.
- Older Planner Hub labels.

These areas should not be removed blindly. For launch readiness, the visible VNV Events panel should prioritize Level 1, Level 4, Level 5 and Level 6 workflows.

## Current High-Priority Fixes

1. Keep VNV Events branding visible in dashboards, sidebars and documentation.
2. Make Level 1 navigation operational: orders, calendar, clients, team, payments, contracts, CMS, forums, SEO, music and settings.
3. Add order calendar views without replacing order lists.
4. Require geolocation before clock-in when time tracking stores coordinates.
5. Keep Level 5 client navigation clean and free of internal admin modules.
6. Expose music sessions clearly for team/client review.
7. Keep SEO files manually regenerable from the SEO Center.
8. Confirm public event requests save to `event_requests`, email VNV Events, and can be archived from Level 1.

## Launch Sprint Status

### Phase 1: Documentation And Identity

Completed baseline documentation for the current VNV Events launch context. The repo should be read as VNV Events first, with Ophyra and VNV Venues treated as historical/technical references.

### Phase 2: Navigation And Base Style

Level 1, Level 4 and Level 5 sidebars have been tightened around real VNV Events workflows. Level 1 now exposes event orders, order calendar, clients/team, chat, payroll/time clock, forums, music sessions, CMS publishing, SEO Center and settings. Level 4 focuses on assigned jobs, calendar, clock, pending hours, chat and music sessions. Level 5 focuses on client orders, forum replies, public forums, music sessions, tickets, planning tools and store orders.

### Phase 3: Level 1 Operations

The Level 1 dashboard remains the visual reference and now links directly to the order calendar, CMS and SEO Center. It also surfaces recent public event requests from the home/popup flow and supports archiving them once reviewed. Future operational widgets should be added from real order/payment/message queries rather than placeholder counts.

### Phase 4: Orders And Calendar

Order list views remain intact. A shared order calendar view has been added for Level 1 and Level 4:

- Level 1: `/panel/planner-hub/management/orders/calendar`
- Level 4: `/panel/planner-hub/team/orders/calendar`

The calendar groups orders by week, shows scheduled orders by day/time, and isolates orders without assigned time.

### Phase 5: Level 4 Payroll/Clock

Clock-in now requires valid browser geolocation. The frontend prompts for location before submitting, and the backend rejects clock-in attempts without numeric latitude/longitude. This follows the stronger payroll behavior reviewed in VNV Venues.

### Phase 6: Level 5 Client Experience

Level 5 navigation is now cleaner and avoids exposing Level 1/Level 4 operations. Public forum participation and personal forum replies remain part of the client/community flow. Level 5 users can also start an event request from the dashboard without being pushed into a business-owner signup path.

### Phase 7: CMS/SEO

Level 1 now has a CMS route bridge to the existing CMS workspace so the owner/admin can reach publishing tools from the VNV Events navigation. SEO Center remains manually triggered; no cron should be assumed.

### Phase 8: QA

Verified PHP syntax for the new calendar service, calendar controllers and Level 1 CMS bridge files. Full browser QA is still required against local authenticated Level 1, Level 4 and Level 5 sessions.

### Phase 9: Process Review

Current launch risk is not a single missing feature; it is route sprawl from inherited Ophyra/VNV Venues/Avomeal modules. New work should continue reducing visible confusion without deleting inherited modules until usage and data impact are confirmed.

Use `docs/VNV_EVENTS_LAUNCH_QA_CHECKLIST.md` as the final practical gate before production launch.

## Current Launch Blockers

- Manual SQL must be confirmed in production for `event_requests`, `seo_files_logs` and forum tables.
- SMTP must be tested by submitting a real event request and confirming delivery to `info@vnvevents.com` and `contact@vnvevents.com`.
- SEO Center must regenerate files after final content is loaded.
- Browser QA is still required for Level 1, Level 4 and Level 5 on desktop and mobile.
- Public pages must be spot-checked for VNV Events language, metadata, schema and broken links.

## Manual SQL Policy

This project is not currently relying on running migrations automatically during this sprint. When database changes are needed, provide SQL in chat so it can be run manually.

## Reference

VNV Venues remains a technical/UX reference for mature order/payroll/team workflows, but VNV Events remains the current operational brand.
