# Ophyra Final Stabilization QA

> Avomeal web note: this is inherited stabilization reference. Translate only relevant owner-scoped, Store, payment, mobile or QA lessons into Avomeal workflows.

## Purpose

This document summarizes the final stabilization state for Ophyra as an initial demo-ready operational version.

It does not introduce a new architecture. It documents the current owner-scoped model, the stable flows, the SQL that should be present, and the manual QA required before a production demo.

## Stabilization Principles

* Keep technical routes stable, including legacy `/planner-hub/` paths.
* Do not rename mobile/API endpoints.
* Do not remove JSON fields used by the mobile app.
* Do not hard-delete users with history.
* Do not store card numbers or CVV.
* Do not mix Ophyra SaaS revenue with business operational revenue.
* Do not reintroduce Venue/Vendor pricing into new Ophyra flows.
* Keep Level 3 and legacy flows available until they are intentionally retired.

## Required SQL

Run the core scripts that match the implemented features:

```text
db/admin_user_module_reports_control.sql
db/store_commerce_required.sql
db/store_team_delivery_operations.sql
db/final_stabilization_required.sql
```

The reviewed full dump already contained the major Store, module, profile, affiliate, recommendation and workspace tables. The final stabilization SQL adds conservative support for:

```text
membership_payments
user_agent_tokens
```

Legacy repositories also reference:

```text
orders_closure_payment_receipts
orders_closure_service_proofs
orders_services_steps
```

Those were not created during stabilization because no active migration or strong runtime dependency was found. Confirm their intended UI/schema before creating them.

## Level 1 Stable State

Level 1 has two contexts:

* **Ophyra Global Admin** for platform users, companies, modules, memberships, subscription payments, referrals, custom domains and manual activations.
* **Business Operations / VNV Events Operations** for CRM, clients, orders, Store, team, payroll, delivery, tracking, business profile and operational reports.

Level 1 can:

* access all modules by default for testing;
* suspend/reactivate users instead of hard-deleting;
* manage modules manually through `/panel/module-manager`;
* record manual payments;
* review affiliate commissions and payouts;
* review custom-domain requests;
* operate Store and Team workflows.

## Level 2 Stable State

Level 2 is the standard Business Owner level.

Level 2 operates its own owner/company scope and should only see company-owned:

* Business Profile;
* CRM;
* Clients;
* Orders;
* Team;
* Payroll;
* Store, Services, Inventory, Tickets or AI when active;
* Business Operations reports.

Inactive paid add-ons should appear as available modules in dashboard/Billing & Modules, not as usable sidebar tools.

## Level 4 Stable State

Level 4 is Team Member.

Team Members can work for one or more companies through `user_institutions`. The selected workspace is stored in `user_workspace_preferences`.

Level 4 should see:

* assigned service/event tasks;
* Store tasks from `store_order_tasks`;
* payroll and clock-in for the selected company;
* chat with admin/team;
* chat with clients only when `allow_chat_with_clients` and workflow permissions allow it.

Location permission is required for clock-in or delivery tasks that require tracking.

## Level 5 Stable State

Level 5 is Client.

Clients can be associated with multiple businesses through `clients_users`.

Clients should see their own:

* service/event orders;
* Store purchases;
* contracts/files;
* payment links;
* fulfillment/tracking status;
* business name/logo for each selling company.

Clients do not need a mandatory company switch to see their own orders across businesses.

## Public Stable State

Public surfaces include:

* Business Profile / Public Profile.
* Request service / estimate flow when the selling owner has the paid `services` module.
* Store catalog/cart/checkout when the selling owner has the Store module or is Level 1.
* Order-access payment/signature links under `/order-access`.

Public order-access tokens are separate from mobile `api_token`.

## Mobile/API Stable State

Mobile app behavior is documented in:

```text
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

Stable expectations:

* login returns the existing `token` plus `api_token`;
* token validation accepts bearer token, form token or JSON token;
* Expo token save accepts common field names;
* token auth has priority over stale WebView sessions;
* chat messages API verifies thread participation;
* daily recommendations and evaluator settings accept token or session auth.

Do not rename endpoints under `src/views/api/` without mobile compatibility review.

## Payments Stable State

Ophyra billing:

* Ophyra Base uses `OPHYRA_BASE_MONTHLY`.
* Store and Services use `OPHYRA_MODULE_STORE_DELIVERY_TRACKING_MONTHLY`.
* Other add-ons use their approved `OPHYRA_MODULE_*` variables.
* Admin-side card collection must not ask for card number or CVV.

Business Operations payments:

* service/event customer payments remain in existing order payment tables;
* Store payments are stored in `store_payments`;
* Store payments are not written to `payments_all` until that enum/schema is intentionally expanded;
* collected revenue must come from real paid records, not from created orders.

## Reports Stable State

Reporting contexts must stay separate:

* Ophyra Global Admin: users, companies, modules, subscription payments, failed payments, affiliate payouts, custom domains.
* Business Operations: orders, collected revenue, pending payments, Store sales, payroll, leads, tasks, abandoned carts and unsigned contracts.

Business dashboards should support date scopes such as today, this week, this month, last month and custom range where available.

## AI Recommendations Stable State

Recommendations should be compact and based on internal data.

Rules:

* Use `institution_profile` for business nature, operation type and location.
* Do not hardcode New York or any fallback market city.
* If location/profile data is missing, recommend completing Business Profile.
* Do not invent market trends without an external data source.

## Affiliates Stable State

Affiliate/referral flows should remain connected to existing referral codes and `AffiliateService`.

Stable expectations:

* commission percentage is configurable through affiliate profile/admin data;
* allowed rates are 30%, 40% and 50%;
* commissions can apply to Ophyra Base and eligible add-ons;
* payouts are manual;
* proof upload is tracked on commission payment records;
* admin can review pending, approved, paid and cancelled commissions.

## Legacy Boundaries

The following legacy areas still exist and should not be broken during demo stabilization:

* Level 3.
* Venue/Vendor public catalog endpoints.
* Old membership screens under Level 3/4/5.
* Legacy venue/service payment flows.
* Existing order-access payment links.

Legacy pricing references remain in legacy folders. They should be retired in a separate compatibility sprint, not during final stabilization.

## Manual QA Checklist

### Level 1

1. Open Ophyra Global Admin home.
2. Open VNV Events Operations links.
3. Search users in `/panel/module-manager`.
4. Suspend and reactivate a test user.
5. Activate, gift, expire and deactivate a test module.
6. Record a manual payment.
7. Review affiliate payout screens.
8. Open Business Profile Builder.
9. Open Store products/orders.
10. Open Team My Work and payroll reports.

### Level 2

1. Log in as Business Owner.
2. Confirm dashboard shows company profile, membership and modules.
3. Confirm inactive Store/Services do not appear as usable sidebar tools.
4. Activate Store manually from Level 1 and confirm Store appears.
5. Create or edit a product.
6. Create a client and an order.
7. Confirm reports are owner-scoped.

### Level 4

1. Log in as Team Member with one company.
2. Confirm My Work shows only selected company tasks.
3. Log in as Team Member with multiple companies.
4. Switch workspace and confirm tasks change.
5. Test clock-in with location denied and allowed.
6. Test Store delivery task start/complete.
7. Test chat with client disabled and enabled.

### Level 5

1. Log in as client with one company.
2. Confirm service orders and Store purchases show.
3. Log in as client associated with multiple companies.
4. Confirm all own orders show and each row identifies the selling business.
5. Confirm no internal company data is visible.
6. Confirm client cannot chat with other clients.

### Public

1. Open public Business Profile.
2. Submit a quote/request service form for a business with Services active.
3. Confirm request connects to the expected business.
4. Open public Store.
5. Add product to cart.
6. Test checkout provider error when no provider is configured.
7. Test Stripe/Square/PayPal in sandbox where configured.
8. Open `/order-access?token=...` and confirm signature checkbox is visible on mobile.

### Mobile

1. Call mobile login and confirm `token`, `api_token` and `user`.
2. Save Expo token with `expo_token` and `expoPushToken` variants.
3. Validate token with `Authorization: Bearer`.
4. Open WebView panel route.
5. Test chat messages as participant and non-participant.
6. Trigger a real Expo notification from a device token.

## Demo Readiness

Ophyra is demo-ready when:

* required SQL scripts are applied;
* Level 1/2/4/5 login flows are manually tested;
* Store checkout is tested with at least one sandbox provider;
* mobile login and Expo token save are tested from the app;
* no production demo depends on legacy Venue/Vendor pricing flows.

## Future Work

* Global automatic renewal cron.
* Store payments in `payments_all` after explicit enum/schema expansion.
* Full order-specific chat schema.
* Legacy pricing retirement.
* Richer module-payment ledger.
* Live map tracking UI beyond latest-location links.
