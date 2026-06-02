# Ophyra Commercial Launch Readiness

> Avomeal web note: this is Ophyra commercial launch reference. Use it only for inherited signup, billing, module or support behavior. The current launch readiness target is Avomeal store under owner `2`.

## Purpose

This document reviews the current web registration, activation, module billing, payment and affiliate flow for Ophyra as a commercial web platform.

Scope:

```text
Ophyra commercial launch = web signup, web billing, web memberships, web module activation, web affiliate flow and Level 1 platform administration.
```

Out of scope for this commercial launch audit:

```text
Mobile app signup as a business registration channel.
Jonnys Media as an operating brand inside Ophyra.
The full external project ecosystem.
```

VNV Events is used only as a real operational reference because it already receives benefits from the system. Avomeal may be mentioned only as a related brand that can operate through scoped Store/Delivery flows, but it is not the center of this audit.

Main question:

```text
Is Ophyra ready to launch commercially as a self-service SaaS platform for new customers?
```

Short answer:

```text
Ophyra is web soft-launch ready and can onboard real customers.
Ophyra is ready for assisted commercial launch with web signup, a free starter workspace, paid add-on checkout, affiliate tracking and Level 1 oversight. It is not yet fully automated SaaS-ready for unattended add-on renewals, dunning and advanced profile moderation.
```

## Readiness Summary

| Area | Status | Notes |
| --- | --- | --- |
| Web signup | Mostly ready | `/signup` creates a Level 2 business owner, self-scopes `id_owner`, creates a basic business profile and can capture affiliate cookie data. |
| Post-signup onboarding | Ready for soft launch | Web signup now authenticates the new business owner automatically and redirects to `/panel/onboarding` so the user can complete profile basics, review included modules, see locked add-ons and continue to billing. |
| Affiliate link to signup | Mostly ready | `/r/{affiliateCode}` stores affiliate cookie and redirects to signup, but only approved affiliate profiles can produce valid tracking. |
| Mobile/API signup boundary | Guarded | Mobile signup belongs to brand-specific apps and now creates/associates final clients only. It rejects business-owner levels and requires or falls back to a business owner context. |
| Basic business profile | Partial | Signup creates a basic `institution_profile`; profile builder can complete it. There is no explicit profile status for active/pending/paused/deleted beyond account suspension and custom-domain status. |
| Free starter base | Ready | `/signup` creates a Level 2 business owner with a free starter workspace and basic business profile. Payment starts when paid modules are activated. |
| Base subscription payment | Legacy/optional | The audited membership payment path still exists if Ophyra later reintroduces paid Base, but current launch positioning is free starter workspace plus paid add-ons. |
| Add-on autopay/webhook | Foundation ready | Stripe webhook/retry services can renew paid add-ons when metadata is complete. Production Stripe retry/dunning QA is still required. |
| Add-on module catalog | Mostly ready | `modules`, `user_modules`, `ModulesRepository`, `UserModulesRepository` and `ModuleAccessService` exist. |
| Add-on self-service payment | Ready for soft launch | `payments_all.concept` now supports `OphyraAddon`. Level 2 can activate paid add-ons from Billing & Modules with a saved Stripe card. Payments are logged and affiliate commission is triggered when applicable. |
| Stripe billing automation | Foundation ready | `/api/stripe/webhook` logs Stripe events, validates signatures, processes supported renewal/failure events idempotently and exposes review from `/panel/billing-automation`. Live Stripe QA is still required. |
| New account module access | Guarded | New Level 2 accounts receive the starter/base workspace only. Paid add-ons stay inactive unless paid, manually activated, gifted by Level 1 or explicitly trialed. Direct Level 2 add-on URLs are guarded by `ModuleGuardService`. |
| Admin module control | Ready for manual control | `/panel/module-manager` lets Level 1 update base membership, suspend/reactivate accounts, activate/gift/expire/cancel add-ons, record manual payments and charge a saved Stripe token. |
| Affiliate commissions | Mostly ready | Referral registration and commission creation exist for web membership payments, admin membership payments, autopay and paid add-on activation. Commission payout QA is still required before high-volume launch. |
| Affiliate payouts | Mostly ready/manual | Admin can approve affiliate profile status/rate, view grouped pending commissions, record manual payouts and upload proof. |
| VNV Events operations | Operational | VNV Events already benefits from Level 1 operational flows. That does not automatically mean Ophyra is fully ready for external self-service SaaS customers. |

## 1. Registration Flow

### Web registration

Primary files:

```text
src/views/public/signup/index.php
src/views/public/signup/index.twig
```

Current behavior:

1. User opens `/signup`.
2. User enters business name, business type, operation focus, personal details and password.
3. System creates a user with:
   - `level = 2`
   - `membership_type = FREE`
   - `membership_due_date = null`
   - `id_owner = null` initially
4. System updates the same user so:
   - `id_owner = user_id`
5. System creates or updates a basic `institution_profile` for that owner.
6. If affiliate cookie exists, system registers the referral.
7. System authenticates the new user with the normal `LoginService` flow.
8. System redirects the user to `/panel/onboarding`.

This is aligned with the current Ophyra model:

```text
New Ophyra commercial customer = Level 2 Business Owner
Level 1 = Ophyra Global Admin / central operations
```

Important clarification:

```text
New external Ophyra customers should not become User Level 1.
They become Level 2 owners of their own company scope.
```

They receive owner/admin capabilities for their own business context, but technically they are not Level 1.

Post-signup rule:

```text
New Ophyra web signup users should not be sent back to login.
They should enter onboarding immediately after the account, profile and affiliate referral are safely created.
```

The onboarding experience lets the user:

* review profile completion;
* set business type and operation focus;
* see included starter/base modules;
* see paid add-ons as recommended or requiring activation;
* go to billing, payment methods or dashboard.

### Mobile/API registration boundary

Primary file:

```text
src/views/api/auth/signup.php
```

This endpoint is not part of the commercial web signup for Ophyra.

Correct rule:

```text
Web signup = commercial registration for new Ophyra business customers.
Mobile signup = final-client registration for a specific brand app, such as VNV Events or Avomeal.
```

Current concern:

`src/views/api/auth/signup.php` has been guarded as a mobile/client endpoint, not converted into the Ophyra SaaS business signup. The Google/Apple mobile signup endpoints and mobile `change-level` endpoint now follow the same boundary.

It now:

* accepts only Level 5 final-client signup;
* rejects business-owner or arbitrary levels from mobile;
* does not use `FREE_MEMBERSHIP_DAYS`;
* does not activate Ophyra Base, modules or affiliates;
* binds the client to `id_user_business`, `id_owner`, `business_id` or `owner_id`;
* falls back to the first active Level 1 owner for legacy branded apps that do not yet send an owner;
* creates the `clients_users` association for the brand/business owner.
* prevents Google/Apple app signup from creating venue/vendor/business-owner accounts;
* prevents mobile `change-level` from switching into Level 2 or Level 3.

For Ophyra commercial launch, the issue is not that mobile cannot register businesses.

The issue is that mobile should not register businesses. That boundary is now enforced for `src/views/api/auth/signup.php`.

## 2. Affiliate Registration Flow

Primary files:

```text
src/Kernel.php
src/Services/AffiliateService.php
src/views/panel/level1/afiliate-hub/index.php
src/views/panel/level2/afiliate-hub/index.php
src/views/panel/level1/planner-hub/management/commissions/pending/index.php
src/views/panel/level1/planner-hub/management/commissions/pending/details/index.php
```

Expected link pattern:

```text
/r/{affiliateCode}
```

Current behavior:

1. `Kernel::handleAffiliateRoute()` detects `/r/{code}`.
2. `AffiliateService::processAffiliateClick()` validates:
   - code exists,
   - code status is active,
   - affiliate profile is approved.
3. If valid, it increments clicks and stores `affiliate_data` cookie for 30 days.
4. If the visitor is not logged in, they are redirected to `/signup?from_affiliate=1`.
5. Web signup reads the cookie and calls `AffiliateService::registerReferral()`.
6. `affiliate_referrals` receives the relationship between referrer and referred user.

Important limitation:

New affiliate profiles are created as `PENDING` by default. Their links do not convert until Level 1 approves the affiliate profile.

## 3. Basic Business Profile

Primary files:

```text
src/Repositories/InstitutionProfileRepository.php
src/Services/BusinessProfileBuilderService.php
src/views/panel/level2/planner-hub/institution-profile/index.php
src/views/public/business-profile/index.php
```

Current behavior:

* Web signup creates a basic `institution_profile`.
* Profile builder lets the owner complete:
  - company name,
  - nature,
  - operation type,
  - phone/email,
  - location,
  - slug,
  - public sections,
  - custom domain request.
* Public profile URL is generated when slug exists.
* `profile_completed_at` can be set when required profile fields are complete.

Current gap:

There is no general profile workflow status such as:

```text
PENDING
ACTIVE
PAUSED
DELETED
REJECTED
```

The current admin-level control is mostly through:

* account suspension with `users.is_active = 0`,
* custom-domain status with `institution_profile.custom_domain_status`,
* missing/completed profile fields.

If profile moderation is required before public launch, add explicit profile status fields or document that account suspension is the moderation tool for now.

## 4. Modules And Access

Primary files:

```text
src/Repositories/ModulesRepository.php
src/Repositories/UserModulesRepository.php
src/Services/ModuleAccessService.php
src/Services/AddonBillingService.php
src/views/panel/level2/home/index.php
src/views/panel/level2/home/index.twig
src/views/panel/level1/module-manager/index.php
src/views/panel/level1/module-manager/index.twig
```

Primary tables:

```text
modules
user_modules
users
```

Current model:

* Base modules are defined in `ModulesRepository::BASE_SLUGS`.
* Add-ons are defined in `ModulesRepository::ADDON_SLUGS`.
* Services uses the same official price as Store through `OPHYRA_MODULE_STORE_DELIVERY_TRACKING_MONTHLY`.
* `ModuleAccessService` treats the starter base as available for active Level 2 business owners.
* Add-ons require `user_modules.status = ACTIVE` and valid `renewal_at` when present.
* Level 1 has broad access by platform role in operational screens.

Add-ons currently recognized:

```text
inventory_storage
services
store_delivery_tracking
ai_advisor
tickets_rsvp
```

Current behavior:

The dashboard quotes add-ons and displays renewal dates. Billing & Modules lets the user activate a paid add-on with a saved Stripe card after starting with the free base workspace.

`AddonBillingService` now:

* verifies `payments_all.concept` supports `OphyraAddon`;
* logs add-on payments to `payments_all`;
* activates `user_modules`;
* creates affiliate commission when a confirmed referral exists.

New signup access rule:

```text
Level 2 ownership does not mean all paid modules are active.
If a paid add-on does not have an active, valid `user_modules` row, it must be treated as inactive.
```

The following paid add-ons are not enabled by default:

```text
inventory_storage
services
store_delivery_tracking
ai_advisor
tickets_rsvp
```

They can become active only through successful payment, Level 1 manual activation, gift/admin activation or an explicitly documented trial. The dashboard may show them as available or requiring activation, but not as active operational tools.

## 5. Payments And Renewals

Primary files:

```text
src/views/panel/level2/cards/index.php
src/views/panel/level2/membership/manage/index.php
src/views/panel/level2/membership/pay/index.php
src/Repositories/UserCardsRepository.php
src/Repositories/UserRepository.php
src/Services/AutopayService.php
src/cron/autopay-renewals.php
```

Primary tables:

```text
user_cards
user_billing_info
payments_all
autopay_settings
autopay_transactions
autopay_retry_queue
```

Current behavior:

* Users can add a Stripe tokenized payment method.
* The system stores display card metadata and a token/customer reference in `user_cards`.
* It does not store raw card number or CVV.
* The user can start with free Ophyra Base and add paid modules from Billing & Modules.
* Successful base payment calls `UserRepository::updateMembershipAndRegisterPayment()`.
* That updates:
  - `users.membership_type = PAID`
  - `users.membership_due_date`
  - `payments_all` with `concept = Membership`
* Admin can record manual payments or charge saved Stripe methods for paid modules or legacy/optional base billing from `/panel/module-manager`.

Autopay state:

* `AutopayService` and `src/cron/autopay-renewals.php` exist.
* It can process Stripe card renewal attempts and retry failures.
* On success it updates membership and logs `autopay_transactions`.

Current autopay state:

The autopay success path now calls `UserRepository::updateMembershipAndRegisterPayment()` with a Stripe autopay reference. That creates the same `payments_all` membership record and affiliate commission path as the web/manual membership payment path.

Remaining work before high-volume automation:

* live Stripe retry QA;
* dunning emails/messages;
* failed-payment access rules.

### Stripe webhook and billing automation

Primary files:

```text
db/ophyra_billing_automation_required.sql
src/Services/BillingAutomationService.php
src/views/api/stripe/webhook/index.php
src/views/panel/level1/billing-automation/index.php
src/views/panel/level1/billing-automation/index.twig
```

Primary table:

```text
stripe_billing_events
```

Current behavior:

* Stripe sends webhook events to `/api/stripe/webhook`.
* The endpoint validates `STRIPE_WEBHOOK_SECRET`.
* Every event is logged before processing.
* Duplicate `stripe_event_id` deliveries do not create duplicate payments.
* Supported successful payment events can renew Ophyra Base and paid add-ons when Stripe metadata identifies the user and billing components.
* Failed invoices mark users as `past_due` and add-ons as `at_risk` without deleting data.
* Incomplete or ambiguous events are marked `pending_review`.
* Level 1 can review webhook status from `/panel/billing-automation`.

Supported events:

```text
invoice.paid
invoice.payment_failed
customer.subscription.updated
customer.subscription.deleted
checkout.session.completed
```

Required production step:

Stripe subscription invoices and checkout sessions should include Ophyra metadata such as `user_id`, `ophyra_base=1`, `module_slug` or `module_slugs`. Events without enough metadata are safely logged for review instead of being forced through.

## 6. Affiliate Commissions

Primary tables:

```text
affiliate_codes
affiliate_profiles
affiliate_referrals
affiliate_commissions
affiliate_commission_payments
```

Current behavior:

* Affiliate codes are unique per user.
* Affiliate profile status controls whether a link can convert.
* Commission rate is configurable per affiliate profile.
* Allowed admin rates are 30%, 40% and 50%.
* Referral association is saved in `affiliate_referrals`.
* Membership payment path creates a commission through `AffiliateService::createCommission()`.
* `AddonBillingService::activateAddonAfterPayment()` also attempts to create an `ophyra_addon` commission.
* Duplicate commission protection exists through `transaction_id` and `payment_id`.
* Admin can record affiliate payout and upload proof.

Current gaps:

* Automatic add-on renewal exists through the Stripe webhook foundation when payment metadata is complete, but still requires real Stripe subscription QA.
* Commission approval is mostly handled at affiliate-profile and payout level; individual commission approval/cancellation workflows should be QA-tested before high-volume launch.
* Public affiliate landing page exists but is minimal; the operational affiliate hub is inside panel.

## 7. Administration Central

Primary file:

```text
src/views/panel/level1/module-manager/index.php
```

Level 1 can currently:

* search Level 2 business accounts,
* filter by active/suspended account,
* filter by active add-on,
* update base membership type and due date,
* suspend/reactivate account,
* activate/deactivate modules manually,
* gift modules with zero price,
* set add-on status and expiration,
* record manual membership payment,
* charge main saved Stripe method,
* review Stripe webhook events from `/panel/billing-automation`,
* see failed or pending billing events,
* view recent `payments_all`,
* view saved-card metadata,
* add internal notes,
* audit actions through `admin_account_actions`.

Related admin areas:

```text
/panel/module-manager
/panel/billing-automation
/panel/planner-hub/management/commissions/pending
/panel/custom-domain-requests
/panel/payments
/panel/home
```

Level 1 can monitor VNV Events Operations where those operations are scoped by owner/company context. Other brands such as Avomeal can be connected only when explicitly scoped, but they are not part of this Ophyra commercial launch readiness question.

## 8. Comparison With VNV Events

VNV Events is already receiving real benefits because it is operated directly from Level 1 and uses mature internal operational flows:

* CRM,
* clients,
* orders,
* contracts,
* team,
* payroll,
* payments,
* communication,
* reports,
* Store/operations where configured.

Ophyra can offer similar operational benefits to external businesses only after their account has:

* valid business-owner scope,
* completed business profile,
* active Ophyra Base membership,
* configured payment method,
* active modules where required,
* admin/manual or paid activation for add-ons.

The operational core exists, but the external SaaS selling flow is less mature than the internal VNV Events operating flow.

## 9. Commercial Launch Answer

### What is ready

* Web signup into Level 2 business account.
* Basic business profile creation.
* Owner scoping with `id_owner = user_id` from web signup.
* Free starter Ophyra Base for new Level 2 business accounts.
* `payments_all` audit for paid add-on payments and legacy/optional membership payments.
* Module catalog and module access services.
* Stripe webhook logging and idempotent billing automation foundation.
* Automatic base/add-on renewal from supported Stripe events when metadata is complete.
* Initial dunning state: `past_due` accounts and `at_risk` add-ons.
* Manual Level 1 module activation/gifting/expiration.
* Affiliate code, click tracking, referral relationship and base payment commission path.
* Manual affiliate payout recording with proof upload.
* Ophyra Global Admin monitoring for users, modules, payments and commissions.
* VNV Events-style operational benefits when account/module access is active.

### What exists but needs adjustment

* Stripe subscription and checkout metadata must be configured consistently for base and add-on renewal.
* Dunning still needs customer/admin notification emails and policy rules for grace periods or lockout.
* Profile moderation needs explicit status if admin must approve/pause/delete profiles separately from account suspension.
* Branded app mobile signup should eventually use per-app configured business owner IDs instead of a legacy Level 1 fallback.

### What is not implemented end-to-end

* Fully unattended, high-volume Stripe live billing QA.
* Customer-facing dunning emails and repeated-failure lockout policy.
* Manual replay/reprocess controls for `pending_review` webhook events.
* Explicit profile approval/paused/deleted workflow.
* Complete production QA for Stripe live mode, retries and failed-payment recovery.

## 10. Launch Recommendation

Ophyra can begin controlled onboarding and early commercial soft launch.

Recommended launch mode:

```text
Soft launch / assisted onboarding
```

Do not launch as a fully automated SaaS checkout yet.

Required first fixes before broad fully automated SaaS launch:

1. Configure Stripe subscription/checkout metadata for Ophyra Base and add-ons.
2. Run full production QA for webhook -> payment ledger -> renewal -> commission.
3. Add dunning/retry emails and failed-payment grace-period rules.
4. Decide whether profile moderation needs explicit `institution_profile.status`.
5. Replace branded mobile signup fallback with per-app configured business owner IDs.
6. Add manual replay/reprocess tooling for selected `pending_review` webhook events after first QA cycle.

## 11. Manual QA Checklist

### Signup

1. Open `/signup`.
2. Register a new business.
3. Confirm `users.level = 2`.
4. Confirm `users.id_owner = users.id`.
5. Confirm `membership_type = FREE`.
6. Confirm `membership_due_date IS NULL`.
7. Confirm `institution_profile.id_owner = users.id`.

### Mobile signup boundary

1. Confirm Ophyra public commercial signup is web-only.
2. Confirm branded mobile app signup does not create Level 2 Ophyra business owners.
3. Confirm mobile app signup creates or associates Level 5 final clients.
4. Confirm the client is associated with the correct app/business owner through `id_user_business` or the equivalent owner field.
5. Confirm mobile app signup does not activate Ophyra Base, add-ons, affiliate profiles or business modules.

### Affiliate

1. Create or approve an affiliate profile.
2. Open `/r/{affiliateCode}` in a clean browser.
3. Confirm cookie is set.
4. Complete signup.
5. Confirm `affiliate_referrals` row exists.
6. Activate a paid add-on.
7. Confirm `affiliate_commissions` row exists for the paid add-on if that commission rule applies.
8. Pay/record payout from admin.
9. Confirm `affiliate_commission_payments` row and commissions marked paid.

### Free starter base

1. Add billing info.
2. Add Stripe card token.
3. Confirm Ophyra Base/starter workspace is available without payment.
4. Confirm paid modules require payment before activation.
5. Confirm add-on payments create `payments_all.concept = OphyraAddon`.

### Modules

1. Confirm module rows exist.
2. Open `/panel/module-manager`.
3. Activate Store for a test Level 2 user.
4. Confirm `user_modules.status = ACTIVE`.
5. Confirm Store appears in the user's dashboard/sidebar.
6. Expire the module and confirm access is blocked.

### Autopay

1. Enable autopay.
2. Run autopay in staging.
3. Confirm card charge result.
4. Confirm membership extended.
5. Confirm `autopay_transactions` record.
6. Confirm `payments_all` and affiliate commission are created.

### Stripe webhook billing automation

1. Configure `STRIPE_WEBHOOK_SECRET`.
2. Keep `STRIPE_WEBHOOK_LOG_ONLY=true` for the first staging delivery.
3. Send `invoice.paid` with `user_id` and `ophyra_base=1`.
4. Confirm `/panel/billing-automation` logs the event.
5. Turn log-only off in staging and resend a new event.
6. Confirm `payments_all.billing_transaction_id` exists.
7. Confirm Ophyra Base renewal date changed once.
8. Send `invoice.paid` with `module_slugs=store_delivery_tracking,inventory_storage`.
9. Confirm paid add-ons renewed in `user_modules`.
10. Send the same event again and confirm no duplicate payment or commission is created.
11. Send `invoice.payment_failed`.
12. Confirm `users.billing_status = past_due`.
13. Confirm related add-ons are `at_risk`.
14. Review the event from `/panel/billing-automation`.

## 12. Tables Involved

```text
users
institution_profile
business_profile_sections
modules
user_modules
payments_all
user_cards
user_billing_info
autopay_settings
autopay_transactions
autopay_retry_queue
stripe_billing_events
affiliate_codes
affiliate_profiles
affiliate_referrals
affiliate_commissions
affiliate_commission_payments
admin_account_actions
```

## 13. Key Code Involved

```text
src/views/public/signup/index.php
src/views/api/auth/signup.php
src/views/api/auth/google/signup-app.php
src/views/api/auth/apple-signing/client-app.php
src/views/api/auth/change-level.php
src/Kernel.php
src/Services/AffiliateService.php
src/Services/AddonBillingService.php
src/Services/BillingAutomationService.php
src/Services/AutopayService.php
src/Services/ModuleAccessService.php
src/Repositories/ModulesRepository.php
src/Repositories/UserModulesRepository.php
src/Repositories/UserRepository.php
src/Repositories/InstitutionProfileRepository.php
src/views/panel/level2/home/index.php
src/views/panel/level2/membership/manage/index.php
src/views/panel/level2/membership/pay/index.php
src/views/panel/level2/cards/index.php
src/views/panel/level1/module-manager/index.php
src/views/panel/level1/billing-automation/index.php
src/views/panel/level1/billing-automation/index.twig
src/views/api/stripe/webhook/index.php
src/views/panel/level1/planner-hub/management/commissions/pending/index.php
src/views/panel/level1/planner-hub/management/commissions/pending/details/index.php
```
