# VNV Events UX & Conversion Correction — 2026-11-12

## Outcome

The six priority public pages now present a shorter first decision, a contextual primary action and a reusable accessible quick-request modal. The modal writes to the existing Level 1 **Lead Intake** queue; it does not introduce a parallel CRM or navigation area. Detailed nonprofit and activation forms remain available as optional advanced qualification.

## Page-by-page changes

### Home

- Preserved the existing H1, canonical URL, metadata, public header and traditional service paths.
- Shortened the hero explanation.
- Changed the principal `Plan My Event` action to the quick-request flow.
- Preserved `Explore Production` as a secondary route and the existing full request component for header/navigation use.

### Full Event Production

- Preserved `/event-production/`, its H1, metadata and traditional production content.
- Reduced hero height and made `Request a Consultation` the principal action.
- Added visual collaboration cards for Victoria Venezuela Foundation and Shake-A-Leg Miami.
- Added the 2027 roadmap goal of supporting up to 20 nonprofit organizations.
- Consolidated the visible production method from eight overlapping steps to five: Discover, Design, Build, Produce, Close & Recap.
- Kept nonprofit, corporate and sponsor activation as three clearly separated production paths.

### Nonprofit Partnerships

- Preserved eligibility, limitations, preferential-term language, FAQ and detailed application.
- Shortened the hero and service-card copy.
- Added visual Victoria Venezuela Foundation and Shake-A-Leg Miami collaboration references.
- Made `Apply for Nonprofit Support` open the quick application.
- Moved the technical form behind `Complete detailed application` progressive disclosure.
- Restored the floating WhatsApp action.

### Corporate Events

- Preserved traditional corporate-event services and `/corporate-events/`.
- Added `By Invitation` context.
- Reduced the hero to one short explanatory paragraph.
- Replaced page-level proposal actions with the contextual `Request an Invitation` quick flow.
- Kept the full event request component available to the shared public header.

### Sponsor Activation Production

- Preserved formats, workflow, limitations and complete technical brief.
- Added `By Invitation` context and shortened the hero.
- Made `Request an Invitation` the primary action.
- Moved dimensions, electricity, approvals, staffing and deliverables behind `Complete technical activation brief`.
- Restored the floating WhatsApp action.

### VNV Original Experiences

- Kept only Retro Glam and Mommy & Daddy Break, both marked `Coming Soon`.
- Shortened the hero.
- Reduced the priority form to experience, name, email, optional phone and consent.
- Restored the floating WhatsApp action.

## CRM and form integration

- New quick requests are stored in the existing `lead_intake` table with source `public_quick_request`, status `NEW` and `NEEDS_MANUAL_REVIEW`.
- The detailed nonprofit, activation and original-experience forms continue using the existing commercial opportunity pipelines.
- The quick form includes CSRF validation, a honeypot, a 15-second submission throttle, server-side validation, explicit contact consent and escaped email output.
- Visitor and administrative acknowledgments use the existing `EmailService` and configured provider.
- No separate CRM tables, sidebar entries or administrative routes were added.

## Accessibility and responsive QA

- Modal supports Escape, focus trapping, focus restoration and an accessible dialog name/description.
- WhatsApp is suppressed while the modal is open and restored after closing.
- Mobile actions use safe scrolling inside the dialog and touch targets of at least 46px.
- Corrected low-contrast turquoise text on light partnership and production sections.
- Verified one H1, canonical URL, no horizontal overflow and no page-level JavaScript errors.

## Evidence

Current and corrected viewport captures were generated for all six pages at 360px, 390px, 430px and 1440px.

- Before: `C:\Users\jonat\AppData\Local\Temp\vnv-ux-correction-2026-11-12\before`
- After: `C:\Users\jonat\AppData\Local\Temp\vnv-ux-correction-2026-11-12\after`

## Automated verification

- PHP syntax checks: passed.
- Twig compilation: 13 templates passed.
- Commercial/UX contract: 87 assertions passed.
- Responsive Chromium QA: 32 page/viewport combinations passed.
- WebKit mobile QA: 6 priority pages passed at 390px.
- Quick-request Lead Intake persistence: passed and the temporary QA row was removed.
- Existing commercial opportunity, consent and pipeline integration tests: passed and temporary QA records were removed.

## Deployment and rollback

- No database migration is required.
- Pre-deployment production revision: `eb99812d`.
- Rollback is a Git deployment of that revision if the new public flow must be reverted.
- Production deployment must continue through the cPanel terminal with `git pull`; no FTP or cPanel Git UI is used.

## Pending

- Replace the interim production photography on the two collaboration cards if the organizations provide approved project-specific images.
- Monitor the first quick requests in Level 1 → Lead Intake to confirm operational response time and refine request-type labels from real usage.
