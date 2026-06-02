# Ophyra Billing Automation

> Avomeal web note: this is Ophyra SaaS billing reference. Avomeal store/subscription work should not become generic Ophyra subscription billing unless the route explicitly manages Ophyra platform accounts.

## Purpose

This document explains the integrated billing automation layer for Ophyra.

Billing automation must be treated as one system:

```text
Stripe webhook + base membership renewal + add-on renewal + payment failures/dunning + payments_all ledger + affiliate commissions + Level 1 fallback.
```

Do not implement these pieces as isolated features. They share the same payment cycle, users, modules, ledger, affiliate rules and recovery workflow.

## Current Status

Ophyra now has a safe billing automation foundation:

* Stripe webhook endpoint: `/api/stripe/webhook`
* event log table: `stripe_billing_events`
* idempotency fields on `payments_all`
* billing status fields on `users`
* billing status fields on `user_modules`
* automatic processing for supported Stripe events
* safe `pending_review` fallback for incomplete data
* Level 1 review screen: `/panel/billing-automation`
* manual recovery remains available through `/panel/module-manager`

This moves Ophyra closer to automated SaaS billing, but live Stripe QA is still required before unattended high-volume billing.

## Environment Variables

```env
STRIPE_WEBHOOK_SECRET=
STRIPE_WEBHOOK_HARD_DISABLE=false
STRIPE_WEBHOOK_LOG_ONLY=false
```

Behavior:

* `STRIPE_WEBHOOK_SECRET` is required before processing real Stripe events.
* `STRIPE_WEBHOOK_HARD_DISABLE=true` logs/ignores processing safely.
* `STRIPE_WEBHOOK_LOG_ONLY=true` stores events without changing users, modules, payments or commissions.

These variables are safety switches, not the core architecture.

## Supported Events

Initial supported Stripe events:

```text
invoice.paid
invoice.payment_failed
customer.subscription.updated
customer.subscription.deleted
checkout.session.completed
```

Unsupported events are logged and marked `ignored`.

## Event Logging

All webhook deliveries are logged in:

```text
stripe_billing_events
```

Important columns:

```text
stripe_event_id
event_type
stripe_customer_id
stripe_subscription_id
stripe_invoice_id
stripe_payment_intent_id
stripe_checkout_session_id
user_id
id_owner
raw_payload
status
error_message
processing_notes
processed_at
created_at
updated_at
```

Statuses:

```text
received
processed
failed
ignored
pending_review
duplicate
```

The same `stripe_event_id` must not process twice.

## Idempotency

Billing automation prevents duplicates through:

* unique `stripe_billing_events.stripe_event_id`
* unique `payments_all.billing_transaction_id`
* invoice/payment intent references in `payments_all`
* affiliate commission duplicate protection through the existing affiliate service

Payment transaction IDs follow predictable patterns:

```text
stripe_invoice:{invoice_id}:base
stripe_invoice:{invoice_id}:addon:{module_slug}
stripe_checkout:{session_id}:base
stripe_checkout:{session_id}:addon:{module_slug}
```

If Stripe retries an already processed event, the event is not processed again.

## Required Stripe Metadata

For automatic processing, Stripe invoices or checkout sessions should include enough metadata to identify the Ophyra account and billing components.

Recommended metadata:

```text
user_id
ophyra_base=1
module_slug=store_delivery_tracking
```

For multiple add-ons:

```text
module_slugs=inventory_storage,store_delivery_tracking,ai_advisor
```

Accepted user keys:

```text
user_id
id_user
ophyra_user_id
```

Accepted base membership hints:

```text
ophyra_base=1
includes_base=1
plan=ophyra_base
```

Accepted module keys:

```text
module_slug
ophyra_module_slug
module_slugs
```

If the webhook cannot identify the user or billing components safely, it marks the event `pending_review`.

## Successful Payment Flow

For `invoice.paid` or paid `checkout.session.completed`, the service attempts to:

1. Identify the Ophyra user.
2. Identify whether the payment includes Ophyra Base, add-ons or both.
3. Register each payment component in `payments_all`.
4. Renew Ophyra Base through the same audited membership path.
5. Renew paid add-ons in `user_modules`.
6. Set billing status back to `current`.
7. Create affiliate commissions when a confirmed referral applies.
8. Mark the webhook event `processed`.

If a component already has a matching `billing_transaction_id`, it is skipped safely.

## Base Membership Renewal

Ophyra Base renewal uses the same membership payment path as manual/web payments:

```text
UserRepository::updateMembershipAndRegisterPayment()
```

That keeps these fields consistent:

```text
users.membership_type
users.membership_due_date
payments_all
affiliate_commissions
```

## Add-on Renewal

Paid add-ons are renewed only when the Stripe payment includes that add-on.

Current add-ons:

```text
inventory_storage
services
store_delivery_tracking
ai_advisor
tickets_rsvp
```

Add-on renewal updates:

```text
user_modules.status = ACTIVE
user_modules.renewal_at
user_modules.billing_status = current
payments_all.concept = OphyraAddon
payments_all.module_slug
```

Add-ons not included in the payment are not renewed by the webhook.

## Payment Failure / Dunning

For `invoice.payment_failed`, the service:

1. Logs the failed invoice.
2. Identifies the user where possible.
3. Sets `users.billing_status = past_due`.
4. Increments `users.billing_failure_count`.
5. Marks paid add-ons as `at_risk`.
6. Leaves data intact.
7. Adds an admin audit note.
8. Keeps manual recovery available.

One failed payment does not delete data or aggressively suspend access.

Future phases can add:

* customer notification emails;
* retry/dunning message templates;
* grace-period rules;
* automatic lockout rules after repeated failures.

## Level 1 Fallback

Level 1 must always have manual recovery.

Current tools:

```text
/panel/billing-automation
/panel/module-manager
/panel/planner-hub/management/commissions/pending
/panel/payments
```

Level 1 can:

* review webhook events;
* see `failed` and `pending_review` cases;
* see duplicate/ignored events;
* renew membership manually;
* activate, renew, expire, cancel or gift add-ons;
* record manual payments;
* charge a saved tokenized Stripe method where available;
* review affiliate commissions and payouts.

## Tables Involved

```text
stripe_billing_events
users
user_modules
modules
payments_all
user_cards
affiliate_referrals
affiliate_commissions
affiliate_commission_payments
admin_account_actions
```

## Files Involved

```text
db/ophyra_billing_automation_required.sql
src/Services/BillingAutomationService.php
src/views/api/stripe/webhook/index.php
src/views/panel/level1/billing-automation/index.php
src/views/panel/level1/billing-automation/index.twig
src/views/panel/level1/module-manager/index.php
src/views/panel/level1/module-manager/index.twig
src/Repositories/UserRepository.php
src/Services/AffiliateService.php
src/Services/AddonBillingService.php
```

## Production QA Required

Before calling Ophyra a fully unattended SaaS billing system, run Stripe live/test-mode QA:

1. Free starter workspace remains available after signup.
2. One paid add-on paid successfully.
3. Multiple paid add-ons paid successfully.
4. Prorated add-on activation.
5. Monthly renewal of base plus add-ons.
6. Duplicate Stripe event delivery.
7. Payment failed.
8. Retry successful after failed payment.
9. Affiliate commission created.
10. Affiliate commission not duplicated.
11. `payments_all` ledger entry created.
12. `user_modules.renewal_at` updated.
13. Incomplete metadata marked `pending_review`.
14. Manual correction from Level 1.

## Remaining Future Work

* Configure Stripe subscriptions/invoices to always send Ophyra metadata.
* Add customer-facing dunning emails and admin notification emails.
* Add stricter grace-period and lockout policy after repeated failures.
* Replace any legacy token/customer mapping gaps with explicit Stripe customer ownership records.
* Expand the Level 1 screen with event replay/reprocess tools after the first production QA cycle.
