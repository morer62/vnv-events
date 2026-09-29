# VNV dashboard standardization QA — 2026-09-26

## Scope inventory

- Level 1 controllers: 257.
- Level 4 controllers: 129.
- Level 5 controllers: 40.
- Total private route controllers: 426.
- Full route-by-route static inventory: `docs/VNV_PRIVATE_ROUTE_INVENTORY.csv`.
- Public authentication routes reviewed: login, signup, forgot password, reset password and password update.
- Public Order Access/payment routes reviewed: main access, advance, first installment, second installment, full payment, PayPal order creation, success, receipt acceptance, tips, tip receipts and the corresponding suborder payment routes.

## Shared visual system

`public/assets/css/vnv-panel.css` is now the common baseline for Levels 1, 4 and 5. It normalizes typography, content width, cards, forms, buttons, tabs, tables, alerts, modals, status presentation, pagination, focus/disabled states and responsive behavior. This applies to deep legacy screens through the shared private layout rather than only to dashboard home pages.

A shared contextual navigation was added for Orders, CRM, Inventory/Storage, team work, client orders and Content. It exposes the real related screens, indicates the current destination and becomes horizontally scrollable on mobile.

Breadcrumb labels are normalized centrally. The historical `planner-hub` implementation path now renders as `VNV Operations`; `management` renders as `Administration`; CMS renders as `Content`; and CRM retains its correct capitalization.

## VNV-only visible context

Visible references to Planner Hub, Avomeal and Ophyra were removed or renamed throughout Levels 1, 4 and 5. Remaining search matches are compatibility identifiers such as CSS classes, browser events and JavaScript globals; they are not rendered product branding and were preserved to avoid breaking chat and native location bridges.

The Level 1 sidebar now leads directly to VNV Content Studio and no longer exposes shared site-scope infrastructure. The duplicate generic Home navigation was also removed for Levels 1, 4 and 5.

## Authentication

- Public signup always creates Level 5 clients, including Google OAuth callbacks; submitted or manipulated role values are ignored.
- The phone field was removed from the initial signup form.
- Standard and Google registrations authenticate immediately and honor a safe stored deep-link redirect.
- Login/Signup no longer display the mobile-app promotion over the active authentication form.
- The former role-selection template is no longer routed or reachable from signup.

## Past-event payments

Event completion and payment completion are independent. Date-based payment blocks were removed from:

- the Order Access landing decision;
- first-installment GET and POST;
- second-installment GET and POST;
- full-payment GET and POST;
- PayPal order creation;
- the payment UI and its JavaScript initialization.

Outstanding balance, provider configuration and normal payment validation remain authoritative.

## Browser QA

Automated Chromium QA created a disposable client through the real signup form, confirmed auto-login, changed the disposable local account through Levels 5, 4 and 1, logged in again at each level, and deleted the test user afterward.

Thirty-nine scenarios passed with HTTP success, no uncaught browser errors, no broken images, no visible foreign branding and no horizontal document overflow:

- Level 5: Home, Orders, Calendar, Payment Methods, Billing, Messages and Settings.
- Level 4: Home, Assigned Orders, My Work, Team Chat, Contract, Availability and Clock.
- Level 1: Home, Execution, Orders, Create Order, Calendar, Contracts, CRM, CRM Categories, Create Customer, Storage, Containers, Create Container, Team Management, CMS Library, Content Pages, Content Studio, Agents and Settings.
- Mobile 390×844: Execution, CRM, Storage, Containers, Create Container and Content Pages.
- Authentication: client-only signup and automatic authenticated redirect.

A local past event with an outstanding payment state was opened through a valid Order Access token. The page returned HTTP 200, presented a payment action and did not render the former past-event prohibition.

All modified PHP files passed `php -l`. `git diff --check` reports no content errors; repository line-ending notices remain Windows CRLF normalization notices.

## Deliberately preserved compatibility details

- Filesystem routes still contain `planner-hub` because changing those URLs would be a high-risk routing migration. Users see `VNV Operations` in breadcrumbs and VNV-specific navigation instead.
- Internal `.ophyra-chat`, `OPHYRA_MOBILE_LOCATION` and `ophyra:mobile-location` identifiers remain for CSS/native-client compatibility and are not visible branding.
- Screens requiring a real order, customer, container or payment-provider record cannot all exercise destructive create/edit/pay actions in an automated smoke test. Their controllers and templates are inventoried and inherit the shared system; production payment capture was not attempted.
