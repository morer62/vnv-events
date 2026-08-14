# VNV Events — Lead Intake and Main Manager availability

Updated: 2026-08-14.

## Launch decision — 2026-08-14

The production launch is limited to Event Manager availability and order
conflict visibility. ManyChat/native Meta conversational automation is deferred
until the channel decision is final; it is not shown in Level 1 navigation and
requires no production environment variables for this release.

Run `db/20260814_manager_scheduling_launch.sql`. Level 1 explicitly marks a
Level 4 user as an Event Manager while creating or editing that user. Event
Managers see a dashboard reminder to record days they cannot work. Estimate and
order creation never stops because of a manager conflict: the record is saved,
Level 1 is notified, and the first order-table column displays the review alert.

Transition time is configured in hours with
`VNV_MANAGER_TRANSITION_HOURS` (for example `3`, `2`, `1`, or `1.5`).

Level 1 can change an individual Main Manager from the order editor or Manager
Scheduling. When an Event Manager is deactivated, the Team list requires one
bulk replacement for all future events. The replacement may be another active
Event Manager or the configured Level 1 account. Past assignments are retained,
and every transferred order receives a new availability check and history row.

## Purpose

This module implements one shared operational rule between ManyChat, estimates,
contracts, first payment and events. `orders` remains the canonical commercial
and event record; Lead Intake is deliberately separate from the existing CRM.

```text
ManyChat -> Lead Intake -> Availability Engine -> Estimate/order
         -> contract signature -> final check -> first payment -> confirmed event
```

The shared public multistep request form also writes a `public_multistep` entry
to Lead Intake after its canonical `event_requests` record is saved. Because the
public form currently asks for a start time but not an end time, that entry is
marked `NEEDS_MANUAL_REVIEW` until Level 1 completes the schedule. The original
request and email workflow remain unchanged.

## Non-negotiable scheduling rules

- Every confirmed event needs one `main_manager_id`.
- Setup defaults to 60 minutes before the public start.
- The same manager needs the configured `VNV_MANAGER_TRANSITION_HOURS` between
  the prior event end and the next setup start.
- Checks work in both chronological directions.
- A declared `UNAVAILABLE` period excludes that manager.
- A conflict means manual review, not that the sale is impossible.
- Level 1 overrides retain the original check snapshot, author and reason.
- Historical order assignments are retained. Deactivation is blocked while the
  manager has future assigned orders.

Only active Level 4 users explicitly marked as Event Manager are candidates.
`VNV_DEFAULT_MANAGER_EMAIL` is used only to route administrative alerts.

## Routes

```text
POST /api/integrations/manychat/lead-intake
GET  /panel/lead-intake
GET  /panel/manager-scheduling
GET  /panel/manager-availability       (Level 4)
```

ManyChat sends JSON and header `X-VNV-Webhook-Secret`. Required schedule fields:

```json
{
  "external_id": "manychat-subscriber-or-request-id",
  "channel": "instagram",
  "contact_name": "Client Name",
  "email": "client@example.com",
  "phone": "+1...",
  "service": "Catering",
  "guest_count": 80,
  "venue": "Venue or address",
  "event_date": "2026-10-18",
  "start_time": "18:00",
  "end_time": "22:00",
  "setup_minutes": 60
}
```

Response statuses are `AVAILABLE`, `MANAGER_SCHEDULING_CONFLICT` and
`NEEDS_MANUAL_REVIEW`. ManyChat must consume the result; it must not reproduce
the scheduling rules.

## First payment safety

`/order-access/first` runs the engine before calling Stripe, Square or PayPal.
If the final check is not available, no charge is attempted, Level 1 receives an
internal notification, and the client sees a scheduling-review message.

## Installation

Run:

```text
db/20260813_vnv_manager_availability_lead_intake.sql
```

Add the environment variables documented in `.env.example`. No Composer update
is required.

`MANYCHAT_LEAD_INTAKE_SECRET` protects inbound External Requests from ManyChat
and is sent in `X-VNV-Webhook-Secret`. `MANYCHAT_API_TOKEN` is a different
credential: the Account Public API bearer token generated in ManyChat Settings
> API. Never reuse the bearer token as the inbound webhook secret.

## Tables reused

- `orders` for estimate/event date, time, status and owner.
- `users` and Level 4 for manager candidates.
- `notifications` for Level 1 alerts.
- existing order/status/payment/contract flows.

## Tables added

- `lead_intake`
- `manager_availability`
- `event_manager_profiles` (explicit Event Manager eligibility; Level 4 alone is not enough)
- `manager_availability_checks`
- `manager_availability_overrides`
- `manager_assignment_history`

The check log is the scheduling timeline. Assignment history preserves changes;
past events keep the original manager even after the user leaves the team.
