# VNV Events commercial partnerships implementation — November 2026

## Scope

This implementation extends VNV Events under the existing brand, router, authentication, public request flow, Lead Intake queue, invoices module and Level 1 CRM. It does not introduce an independent product or replace traditional event, catering, staffing, decor, photo/video, DJ or rental services.

## Public experience

- Homepage: updated positioning, two production CTAs, Production & Partnerships cards and exactly two original-experience cards.
- Event Production: clearer distinction between full production, nonprofit support, corporate production, sponsorship development and paid sponsor activation production.
- Corporate Events: one-time production, defined 2/4/6-event annual plans and March 2027 planning language; existing event-request form remains the primary intake and additionally syncs to the Corporate pipeline.
- Nonprofit Partnerships: canonical `/nonprofit-partnerships/` landing, eligibility/terms/process/FAQ, full application, consent, attribution and acknowledgment.
- Sponsor Activation Production: canonical `/sponsor-activation-production/` landing, formats, approval workflow, custom brief and no public internal package pricing or unsupported ROI promise.
- Original Experiences: Retro Glam and Mommy and Daddy Break only, both Coming Soon with priority-list/partner-interest capture and no ticket sale.
- Archived concepts: Vanguard Business Gala and Flavor Fusion Fest remain physically recoverable but are removed from visible navigation, forms, internal discovery and generated SEO files; their pages are `noindex,nofollow`.

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
