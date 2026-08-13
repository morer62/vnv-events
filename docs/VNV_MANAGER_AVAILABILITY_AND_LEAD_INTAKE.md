# VNV Events — Lead Intake and Main Manager availability

Updated: 2026-08-13.

## Purpose

This module implements one shared operational rule between ManyChat, estimates,
contracts, first payment and events. `orders` remains the canonical commercial
and event record; Lead Intake is deliberately separate from the existing CRM.

```text
ManyChat -> Lead Intake -> Availability Engine -> Estimate/order
         -> contract signature -> final check -> first payment -> confirmed event
```

## Non-negotiable scheduling rules

- Every confirmed event needs one `main_manager_id`.
- Setup defaults to 60 minutes before the public start.
- The same manager needs 180 minutes of Transition Time between the prior event
  end and the next setup start.
- Checks work in both chronological directions.
- A declared `UNAVAILABLE` period excludes that manager.
- A conflict means manual review, not that the sale is impossible.
- Level 1 overrides retain the original check snapshot, author and reason.
- Historical order assignments are retained. Deactivation is blocked while the
  manager has future assigned orders.

Orders without a stored manager are not silently rewritten. The engine includes
`info@vnvevents.com` as the temporary Level 1 fallback candidate.

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
