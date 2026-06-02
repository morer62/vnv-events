# VNV Events Launch QA Checklist

> Avomeal web note: this checklist is retained as VNV Events reference. For the current Avomeal sprint, use `docs/DATABASE_SCOPE.md`, `docs/STORE_MODEL.md`, `docs/HOMEPAGE_POSITIONING.md` and `docs/NEXT_AGENT_HANDOFF.md`.

This checklist is the final launch gate for this repository.

VNV Events is the active operating brand. Ophyra, Avomeal, VNV Venues and Planner Hub references are historical or technical references unless a specific route is still active for VNV Events.

## Required Before Public Launch

### Database

- Confirm the manual SQL for `event_requests` was run.
- Confirm the manual SQL for `seo_files_logs` was run.
- Confirm forum tables/columns exist before enabling public forum replies.
- Confirm production data is backed up before any schema change.

### Environment

- `APP_URL` points to `https://vnvevents.com`.
- `VNV_EVENTS_OWNER_ID` is set to the correct Level 1 owner when the production owner is not `1`.
- SMTP is configured and can send from the production server.
- reCAPTCHA keys are configured if the public event request modal uses reCAPTCHA.
- Google Maps or browser geolocation requirements are configured where applicable.
- Public write permissions allow manual regeneration of `sitemap.xml`, `robots.txt`, `llms.txt` and `llms-full.txt`.

### Level 1

- Dashboard loads without PHP or JavaScript errors.
- New public event requests appear on the dashboard.
- Event requests can be archived.
- Orders list works.
- Orders calendar works.
- Calendar item click opens the details modal and links to the order detail route.
- Clients, team members, payroll/time clock, chat and reports load.
- CMS, blog, pages, locations and forums are reachable from navigation.
- SEO Center regenerates public SEO/AI files manually.

### Level 4

- Team dashboard loads.
- Assigned orders/tasks load.
- Orders calendar loads.
- Time clock blocks clock-in when location permission is denied.
- Time clock accepts clock-in only after valid latitude/longitude are available.
- Chat loads on desktop and mobile.
- Music sessions are reachable and category navigation is usable.

### Level 5

- Client dashboard loads without internal admin tools.
- Client can view their own orders.
- Client calendar loads.
- Order details and payment/contract links remain accessible when authorized.
- Music sessions require category selection before showing the list.
- Forum replies page shows the user's own replies.
- Public forum quick signup creates a Level 5 account and returns the user to the same thread.

### Public Site

- Home loads.
- Event request popup submits successfully.
- Submission sends email to `info@vnvevents.com` and `contact@vnvevents.com`.
- Service/landing pages load.
- Location pages load with title, meta description, canonical and JSON-LD.
- Blog posts load with title, meta description, canonical and JSON-LD.
- CMS pages load with title, meta description, canonical and JSON-LD.
- Public forums list and thread URLs load.
- Catalog/store public pages load if catalog/store is active for VNV Events.

### SEO Files

- Regenerate all files from SEO Center after final content changes.
- Confirm these URLs are public:

```text
https://vnvevents.com/sitemap.xml
https://vnvevents.com/robots.txt
https://vnvevents.com/llms.txt
https://vnvevents.com/llms-full.txt
```

- Confirm `robots.txt` points to `https://vnvevents.com/sitemap.xml`.
- Confirm drafts, admin routes, private routes, login and API routes are not in the sitemap.
- Submit `https://vnvevents.com/sitemap.xml` in Google Search Console.

### Visual And Mobile

- Dashboard style is consistent across Level 1, Level 4 and Level 5.
- Tables do not overflow in mobile without a scroll container.
- Buttons do not overlap.
- Empty states look intentional.
- Chat input remains usable on mobile.
- Calendar modal fits mobile screens.
- Public pages use VNV Events language, not generic Ophyra language.

## Not A Launch Blocker, But Track

- Replace more legacy `planner-hub` visible labels when they appear in UI.
- Continue reducing Ophyra/Avomeal copy that is visible to VNV Events users.
- Add automated browser smoke tests for Level 1/4/5.
- Add production monitoring for failed emails, failed SEO file generation and failed public form submissions.
