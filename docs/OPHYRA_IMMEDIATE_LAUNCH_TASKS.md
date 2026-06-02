# Ophyra Immediate Launch Tasks

> Avomeal web note: this checklist belongs to Ophyra. Do not use it as the active Avomeal launch checklist; use `docs/NEXT_AGENT_HANDOFF.md`, `docs/DATABASE_SCOPE.md` and `docs/STORE_MODEL.md`.

## Purpose

This checklist defines the immediate work needed to launch Ophyra as a web commercial platform while continuing to operate VNV Events and Avomeal from User Level 1.

## Launch Position

Ophyra can launch in assisted soft-launch mode now.

That means:

* Web signup can receive new business customers.
* Level 2 business owners can complete billing, add cards, activate Ophyra Base and activate paid add-ons.
* Level 1 can monitor users, memberships, modules, payments and affiliates.
* VNV Events and Avomeal can continue being operated from User Level 1 as separate scoped operations.

Do not market Ophyra yet as a fully automatic enterprise SaaS with unattended subscription recovery, complex profile moderation or app-store mobile SaaS signup.

## Completed For Immediate Launch

1. Web signup remains the commercial signup path for new Ophyra businesses.
2. Mobile signup is guarded as final-client signup only.
3. Mobile signup no longer creates business owners or arbitrary user levels.
4. Mobile email/password, Google and Apple app signup bind clients to a business owner through `id_user_business`, `id_owner`, `business_id` or `owner_id`, with a documented Level 1 fallback for legacy branded apps.
5. Ophyra Base is free to start; paid add-ons write to `payments_all`.
6. Membership payments now support custom references and stable transaction IDs.
7. Autopay renewal now uses the audited membership payment path.
8. Autopay renewal now creates `payments_all` records.
9. Autopay renewal now triggers the affiliate commission path when a referral exists.
10. Level 1 manual membership payments now use the same audited membership payment path.
11. Level 1 saved-card membership charges now use the same audited membership payment path.
12. `payments_all.concept` now supports `OphyraAddon`.
13. Official Ophyra module rows are seeded and active.
14. Level 2 Billing & Modules can activate paid add-ons with a saved Stripe card.
15. Add-on activation logs payment in `payments_all`, activates `user_modules` and triggers affiliate commission when applicable.
16. Billing Automation foundation receives Stripe webhook events at `/api/stripe/webhook`.
17. Stripe events are logged in `stripe_billing_events` with idempotent processing.
18. Successful Stripe invoice/checkout events can renew Ophyra Base and paid add-ons when metadata is complete.
19. Failed Stripe invoices mark accounts/add-ons at risk without deleting data.
20. Level 1 can review webhook status from `/panel/billing-automation`.

## Files Changed For Launch

```text
db/ophyra_immediate_launch_required.sql
docs/OPHYRA_IMMEDIATE_LAUNCH_TASKS.md
docs/OPHYRA_COMMERCIAL_LAUNCH_READINESS.md
src/Repositories/UserRepository.php
src/Services/AddonBillingService.php
src/Services/AutopayService.php
src/views/api/auth/signup.php
src/views/api/auth/google/signup-app.php
src/views/api/auth/apple-signing/client-app.php
src/views/api/auth/change-level.php
src/views/panel/level1/module-manager/index.php
src/views/panel/level1/billing-automation/index.php
src/views/panel/level1/billing-automation/index.twig
src/views/api/stripe/webhook/index.php
src/Services/BillingAutomationService.php
src/views/panel/level2/home/index.twig
src/views/panel/level2/membership/manage/index.php
src/views/panel/level2/membership/manage/index.twig
```

## SQL Applied Locally

```text
db/ophyra_immediate_launch_required.sql
db/ophyra_billing_automation_required.sql
```

This SQL:

* adds `OphyraAddon` to `payments_all.concept`;
* inserts or updates all official Ophyra Base modules;
* inserts or updates all official paid add-ons.
* creates `stripe_billing_events`;
* adds billing idempotency fields to `payments_all`;
* adds billing/dunning status fields to `users` and `user_modules`.

Run these SQL files in production before enabling self-service add-on activation and Stripe webhook processing there.

## Immediate Manual QA

### Ophyra Web Signup

1. Open `/signup`.
2. Create a new business account.
3. Confirm the user is Level 2.
4. Confirm `users.id_owner = users.id`.
5. Confirm `institution_profile.id_owner = users.id`.

### Free Starter Base

1. Add billing info.
2. Add a Stripe card.
3. Open `/panel/membership/manage`.
4. Confirm the starter workspace is available without paying Ophyra Base.
5. Confirm paid add-ons are the payment trigger.
6. Confirm affiliate commission when a paid add-on is purchased by a referred account.

### Paid Add-ons

1. Confirm `payments_all.concept` includes `OphyraAddon`.
2. Open `/panel/membership/manage`.
3. Activate Store, Services, Inventory, AI Advisor or Tickets.
4. Confirm Stripe charge succeeds.
5. Confirm `payments_all.concept = OphyraAddon`.
6. Confirm `user_modules.status = ACTIVE`.
7. Confirm affiliate commission is created if the user was referred.

### Autopay

1. Enable autopay for a test user with a saved card.
2. Set membership due date to today or earlier in staging/local.
3. Run `src/cron/autopay-renewals.php`.
4. Confirm `autopay_transactions.status = success`.
5. Confirm `payments_all.concept = Membership`.
6. Confirm affiliate commission when the user has a confirmed referrer.

### Stripe Webhook / Billing Automation

1. Configure `STRIPE_WEBHOOK_SECRET`.
2. Send a Stripe `invoice.paid` test event with `user_id` and `ophyra_base=1` metadata.
3. Confirm `/panel/billing-automation` shows the event as `processed`.
4. Confirm `payments_all.billing_transaction_id` is set.
5. Send the same event again and confirm no duplicate payment is created.
6. Send `invoice.payment_failed` and confirm `users.billing_status = past_due`.
7. Confirm affected add-ons show `user_modules.billing_status = at_risk`.

### Mobile Signup Boundary

1. Submit mobile signup with `level = 5` and `id_user_business`.
2. Confirm the user is created as Level 5.
3. Confirm `clients_users` association exists.
4. Submit mobile signup with `level = 2`.
5. Confirm the endpoint rejects it.
6. Submit Google/Apple mobile signup with `accountType = venue` or `vendor`.
7. Confirm the endpoint rejects it.
8. Submit `api/auth/change-level` with `level = 2` or `level = 3`.
9. Confirm the endpoint rejects it.

### Level 1 Operations

1. Open `/panel/module-manager`.
2. Confirm Level 2 business accounts appear.
3. Record a manual payment.
4. Confirm `payments_all` and affiliate commission path.
5. Charge a saved Stripe card.
6. Confirm `payments_all` and affiliate commission path.

### VNV Events From User 1

1. Log in as User Level 1.
2. Open VNV Events Operations.
3. Confirm CRM, clients, orders, team, payroll, reports and chat still load.
4. Confirm Ophyra Global Admin metrics remain separate from VNV operational metrics.

### Avomeal From User 1

1. Log in as User Level 1.
2. Switch or filter to Avomeal Operations where configured.
3. Confirm Store products, nutrition, weekly menus, subscriptions, delivery zones, orders and reports use the Avomeal owner/company scope.
4. Confirm Avomeal data does not mix with VNV Events data.

## Still Not A Full Self-Service SaaS

These items remain future stabilization work:

* richer profile moderation statuses;
* production Stripe webhook QA with real subscription metadata;
* dunning, lockout and recovery emails;
* more polished affiliate public landing and affiliate self-service UX;
* full production payment processor QA with live Stripe mode;
* app-specific mobile signup owner configuration instead of fallback.

## Launch Recommendation

Launch Ophyra as:

```text
Soft launch / assisted onboarding
```

Messaging should be:

```text
Ophyra is open for early business onboarding. Billing, modules and operations are web-based. Add-ons can be activated from Billing & Modules, and Level 1 can assist or override when needed.
```
