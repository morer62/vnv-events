# VNV Events commercial partnerships implementation — November 2026

## Scope

This implementation extends VNV Events under the existing brand, router, authentication, public request flow, Lead Intake queue, invoices module and Level 1 CRM. It does not introduce an independent product or replace traditional event, catering, staffing, decor, photo/video, DJ or rental services.

## Public experience

The Productions menu links to Event Production, Nonprofit Partnerships, Brand Experiences & Activation, VNV-Q Event Software and Original Experiences, in that order. The established corporate route continues to answer directly for inbound links. Cause on Cue retains its application and selection process. Retro Glam Sessions and Mommy & Daddy Break Club accept interest, with specific benefits and availability subject to each edition.

`src/Services/VnvProductionCatalog.php` supplies the public prices for new production proposals. Jonathan approved these fixed fees on November 27, 2026.

| Service | Production fee | Scope |
| --- | --- | --- |
| Production Essential | $1,950 | One defined production assignment. |
| Full Event Production | $3,500 | One day, up to 300 guests and up to 10 sponsors, with VNV providing catering, sound and staffing. |
| Full Event Production Plus | $4,500 | More than 300 guests, more than 10 sponsors, paid ticketing, or an outside catering, sound or staffing provider. Check the Advanced criteria first. |
| Advanced & Large-Scale Production | From $6,500 | Multiple days, multiple venues or more than 1,000 guests. Quote one fixed fee for the project. |
| Standalone Brand Activation | $1,500 for the first event day, then $500 for each additional day | An activation within a VNV production is included in the production fee. |

The production fee covers the producer, run of show, vendor decisions, rehearsal planning and agreed deliverables tracked in VNV-Q. Book a separate Day Coordinator for physical coverage. List direct costs separately, including materials, fabrication, rentals, extra staffing, installation, technical services and third-party costs. Obtain the client's approval before committing to those costs. Validate labor, commissions, overhead and margin before sending a proposal. Sponsorship rights are quoted for the specific event.

Effective November 28, 2026, Event Planner, Wedding Planner and Day Coordinator packages use four guest tiers: up to 75, 76–150, 151–250 and 251+. In that order, Event Planner is $1,500 / $2,250 / $2,950 / $3,750 and Wedding Planner is $1,950 / $2,750 / $3,450 / $4,250. Planning includes 3 / 4 / 5 / 6 scheduled meetings. An extra meeting costs $150 and each additional event day on the planning program costs $500. Day Coordinator is $850 / $1,100 / $1,400 / $1,750 for one day of up to ten hours. The two highest tiers include one assistant. An extra on-site hour costs $180. Each additional coordination day is 75% of the selected tier. Use a separate direct-pay line for the coordinator and exclude that line from the commission base.

## CRM integration

The additive commercial schema complements the existing CRM with owner/site isolation:

- organizations, contacts, opportunities and projects;
- sponsorship inventory/deals and activation orders;
- proposals and links to existing VNV invoices;
- nonprofit support grants and approval limits;
- activities, consent, media and audit logs;
- commission records with Direction approval.

Pipelines:

- Nonprofit: Received → Under Review → Approved → Active → Closed.
- Corporate: Lead → Discovery → Proposal → Negotiation → Won/Lost.
- Sponsorship: Intro Consented → Contacted → Proposal → Contracted → Paid → Fulfilled.
- Activation: Brief → Design → Approved → Build → Live → Recap.
- Original interest: Waitlist → Qualified → Invited → Ticketed.

Direction (Level 1/6) can see the complete pipeline, approve nonprofit support, link existing invoices, record/approve eligible commission splits and assign opportunities. Team users see only explicitly assigned opportunities and never receive the finance controls in the rendered interface.

## Finance rules

- Corporate and sponsorship sources split the eligible collected fee 35% Oriana / 30% Florida / 35% VNV reserve.
- Original Experiences first create a 15% pool from positive contribution, then split that pool 35/30/35.
- Duplicate collected-payment references are rejected.
- Nonprofit support validation enforces maximum 60% selected food/staff support and 80% selected decor support.
- Approved support terms are recorded separately and can be linked to the existing invoice rather than duplicating invoice/order data.

## VNV-Q and branded app eligibility

VNV-Q is the public identity for VNV Events’ event management software, powered by Ophyra. Management approved its use on November 27, 2026 after a preliminary naming search. Existing signed agreements continue under their signed terms.

An agreed digital workspace and the access described in the contract are included in the production service. A selected nonprofit can receive branded app development at no development cost when its written Cause on Cue support plan includes it. The organization controls its records and any introductions require specific consent. For a private branded app, confirm configuration, publication, store accounts, support, ownership and maintenance in the written scope. A $10,000 spend threshold and a four-event annual commitment were discussed as possible routes. Neither is a published entitlement. Price any commitment using agreed delivery costs and coverage.

## Security and data handling

- CSRF, honeypot, input limits, server-side validation and short session rate limiting on partnership forms.
- Explicit contact consent on nonprofit, activation, original, sponsor and existing event-request flows.
- IP addresses are stored only as salted hashes in the commercial consent table.
- Public forms do not expose margins, internal package prices or commission values.
- Audit records cover stage changes, assignments, support approvals, invoice links and commission creation/approval.

## Deployment

Production deployment is terminal-only:

```bash
git pull origin main
php tools/apply-commercial-partnership-crm.php
php tools/backup-commercial-partnership-crm.php
php tools/regenerate-public-seo.php
```

The CRM installer is idempotent and verifies all 15 tables. The backup is written outside the public web root under `storage/backups/commercial-crm/`.

## Rollback

1. Put the prior application commit back on `main` and run `git pull origin main` in cPanel terminal.
2. Keep the additive commercial tables in place to preserve submitted leads, or export them with the backup tool before any schema rollback.
3. Regenerate sitemap/robots/LLM files after application rollback.
4. Do not run the migration `down()` in production unless Direction has approved permanent removal and a verified backup exists.

## Deliberately deferred

Promotional video production remains a later production phase. No synthetic case study, fictitious sponsor mark, unconfirmed venue/date or fabricated event evidence was added in this website/CRM release.
