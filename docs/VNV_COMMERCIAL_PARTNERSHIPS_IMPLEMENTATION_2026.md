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

## VNV Support business plan — branded app eligibility

**Internal decision draft, November 27, 2026.** Cuebook is the proposed commercial name customers will see under VNV Support. VNV Operations remains the name of the private technical pilot. Direction is evaluating how to fund development and support for an organization's own branded app. The options below are for costing and approval before anyone quotes them.

**Current offer.** Paid production partners and approved no-fee or in-kind partners receive the agreed digital workspace, setup and ongoing access for future events at no additional platform charge. A foundation can apply to Cause on Cue with any event budget. Selected nonprofits can already receive a branded app at no development cost when their written support plan includes it. Honor existing commitments. The organization retains control of its guest, donor and volunteer records. Assign access by role and record any authorized sharing separately.

**Existing commercial reference.** The November 16 agreement, recorded in the November 18 VNV Events Productions master summary, sets the private corporate full-production fee at 15% of the approved production budget. The fee appears separately on the proposal and invoice. Charge coordination already in a service package only once. Each date in an annual agreement has its own production budget. Direction still needs to decide whether to use that model for a paid VNV Support production that includes a branded app. Donations, sponsorship proceeds and donated services do not enter the proposed fee base.

| Proposed route to a branded app | Financial illustration | What Direction must decide |
| --- | --- | --- |
| Single-event production | Preferred interpretation of the proposed USD 10,000 threshold is the approved production budget before the VNV fee and tax. At that budget, a 15% fee is USD 1,500, bringing the total to USD 11,500 before tax. | Confirm the basis. If USD 10,000 means the fee itself, a 15% fee would require about USD 66,667 of production spend. Check whether the selected fee covers app delivery and event production labor. |
| Annual production partnership | An organization commits to four contracted events within 12 months. Quote each date separately. If every date were budgeted at USD 10,000 and carried a 15% fee, total production spend would be USD 40,000 and total fees would be USD 6,000 before tax. | Decide whether four events qualify on their own, whether there is a minimum annual spend, and how cancellations or fewer delivered dates affect the app commitment. USD 10,000 is not an all-inclusive four-event price. |
| Mission-funded exception | Direction can approve app work as an in-kind contribution through a nonprofit's support plan, with the contribution and funding source recorded. | Review fit, available capacity and the cost VNV will absorb. No fee or minimum event count applies to the existing approved grant. |

Before offering either paid route, cost the initial configuration, branding, publishing and training, plus maintenance and support over the agreed term. Record which modules are enabled, who manages store accounts and third-party charges, who provides support and how the organization retains or exports its records. Direction approves the economics and scope in writing before commercial staff quote an app commitment. Keep these scenarios out of public decks, FAQs, intake rules and commission calculations until adopted.

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
