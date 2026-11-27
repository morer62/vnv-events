# VNV Events commercial partnerships implementation — November 2026

## Scope

This implementation extends VNV Events under the existing brand, router, authentication, public request flow, Lead Intake queue, invoices module and Level 1 CRM. It does not introduce an independent product or replace traditional event, catering, staffing, decor, photo/video, DJ or rental services.

## Public experience

The Productions menu links to Event Production, Nonprofit Partnerships, Brand Experiences & Activation, VNV-Q Event Software and Original Experiences, in that order. The established corporate route continues to answer directly for inbound links. Cause on Cue retains its application and selection process. Retro Glam Sessions and Mommy & Daddy Break Club accept interest, with specific benefits and availability subject to each edition.

The public service matrix is maintained in `src/Services/VnvProductionCatalog.php`. Jonathan approved its terms on November 27, 2026 for **new agreements**. Day Coordinator and Event Planner retain their package rates. Essential starts at $1,500 for a defined assignment. Full production uses the greater of 10% of the managed production budget and $1,950. Standalone activation uses the greater of 12% of the managed activation budget and $750. Advanced projects and sponsorship inventory require individual proposals. Quote direct costs separately, exclude tax, gratuities and the fee from the managed base, and count each responsibility once. Validate labor, commissions, overhead and margin before issuing a quote. Signed agreements retain their terms.

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

VNV-Q is the public identity for VNV Events’ event management software, powered by Ophyra. Management approved its use on November 27, 2026 after a preliminary naming search. Technical identifiers remain unchanged. The earlier corporate 15% agreement recorded on November 16 still governs any contracts signed on those terms. The terms above govern new proposals.

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
