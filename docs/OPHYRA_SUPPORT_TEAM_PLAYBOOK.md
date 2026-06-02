# Ophyra Support Team Playbook

> Avomeal web note: this is Ophyra support reference. For Avomeal launch support, adapt only the operational QA habits, not the SaaS customer/account language.

## Purpose

This document is the onboarding and daily operating guide for the Ophyra support team.

It explains what support should understand, what screens to monitor, what questions to ask, what tests to run, how to handle tickets and when to escalate.

Ophyra can launch in assisted soft-launch mode because Level 1 can monitor billing, accounts, modules, payments and customer issues manually while automation matures.

## Support Team Role

The support team is not expected to change code or database records directly.

Support works from Level 1 and should:

* monitor new business signups;
* confirm Ophyra Base membership status;
* confirm paid modules are active;
* review billing automation events;
* identify `past_due`, `failed` and `pending_review` cases;
* help customers complete setup;
* document tickets clearly;
* escalate technical or payment processor issues.

Support should never:

* ask customers for full card numbers or CVV;
* enter raw card details into admin forms;
* delete customer data to solve a billing issue;
* manually change database data outside the admin panel;
* promise unsupported modules or pricing;
* treat VNV Events, Avomeal and Ophyra as the same product.

## Required Context Before Handling Tickets

Every support person should read these files first:

```text
docs/README.md
docs/OPHYRA_BUSINESS_MODEL.md
docs/OPHYRA_COMMERCIAL_LAUNCH_READINESS.md
docs/OPHYRA_BILLING_AUTOMATION.md
docs/OPHYRA_IMMEDIATE_LAUNCH_TASKS.md
docs/USER_COMPANY_ACCESS_MODEL.md
docs/ADMIN_USER_MODULE_REPORTS_CONTROL.md
```

For brand-specific context:

```text
docs/VNV_EVENTS_PROJECT_CONTEXT.md
docs/AVOMEAL_PROJECT_CONTEXT.md
docs/MOBILE_APPS_ECOSYSTEM.md
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

## Core Rule

Use this mental model:

```text
Ophyra = web platform for business customers.
Level 1 = Ophyra admin/support control.
Level 2 = business owner/customer account.
Level 4 = team member/employee.
Level 5 = final client.
Mobile signup = final client signup for a branded app, not Ophyra business signup.
```

## Main Routes Support Must Know

### Billing Automation

```text
/panel/billing-automation
```

Use this screen to review Stripe webhook events and automation status.

Support should check:

* `pending_review`
* `failed`
* `ignored`
* duplicate deliveries
* `invoice.paid`
* `invoice.payment_failed`
* events not matched to a user
* events missing Ophyra metadata

Main question:

```text
Did Stripe send an event that Ophyra could process safely?
```

### Module Manager

```text
/panel/module-manager
```

Use this screen to manage and recover customer accounts.

Support should check:

* account active/suspended;
* billing status;
* Ophyra Base membership type;
* membership renewal date;
* active add-ons;
* add-on expiration dates;
* saved tokenized cards;
* recent `payments_all` records;
* admin notes;
* manual payment records;
* saved-card charge result.

Main question:

```text
Does this customer have the access they paid for?
```

### Affiliate Commissions

```text
/panel/planner-hub/management/commissions/pending
```

Use this screen to review referral and affiliate payout issues.

Support should check:

* pending commissions;
* affiliate profile status;
* commission amount;
* referred customer;
* payment that generated commission;
* duplicate or missing commission concerns;
* payout proof.

Main question:

```text
Was the affiliate relationship and commission recorded correctly?
```

### Payments

```text
/panel/payments
```

Use this screen for general payment review when available.

Support should check:

* payment history;
* payment status;
* amount;
* concept;
* renewal date;
* payment references;
* whether the payment is membership, add-on, order or manual.

Main question:

```text
Is there an internal payment record that matches the customer issue?
```

### Business Operations

Depending on the customer and active modules, support may need to verify:

```text
/panel/home
/panel/planner-hub/institution-profile
/panel/planner-hub/management/clients
/panel/planner-hub/management/orders
/panel/planner-hub/team
/panel/membership/manage
```

Exact routes can vary by level and module. Support should use the visible panel navigation first and avoid direct URL guessing unless instructed.

## Daily Support Routine

Run this every business day.

### 1. Billing Automation Check

Open:

```text
/panel/billing-automation
```

Check:

* events with `pending_review`;
* events with `failed`;
* recent `invoice.payment_failed`;
* repeated duplicate events;
* events with no matched user;
* events with missing metadata.

Questions to answer:

```text
Are there billing events that need human review?
Did any paid invoice fail to renew access?
Did any failed payment mark a customer at risk?
Is this an isolated issue or repeated pattern?
```

### 2. Past Due Customer Check

Open:

```text
/panel/module-manager
```

Search/filter for customers with:

* billing status not `current`;
* failed payment attempts;
* suspended accounts;
* expired membership due date;
* inactive add-ons that should be active.

Questions to answer:

```text
Who is past due?
Was access affected?
Do we need to contact the customer?
Can this be corrected manually?
```

### 3. New Signup Check

Review recent Level 2 business accounts.

Questions to answer:

```text
Did the account create correctly?
Does `id_owner` match the user ID?
Was a business profile created?
Did the customer start free and activate any paid add-ons?
Did they activate add-ons?
Did they come from an affiliate link?
```

### 4. Payments Check

Review recent membership and add-on payments.

Questions to answer:

```text
Is the payment recorded in `payments_all`?
Does the payment amount match the product?
Was the renewal date updated?
Was the right add-on activated?
Was a commission created when applicable?
```

### 5. Ticket Queue Check

Review open tickets and group them by category:

* signup/account access;
* billing/payment;
* module activation;
* affiliate/commission;
* profile setup;
* operational workflow;
* mobile final-client login/signup;
* bug/technical issue.

Every ticket must have:

* customer name;
* user email;
* user ID if known;
* business name;
* module affected;
* route/screen affected;
* screenshot or exact error message if possible;
* support status;
* escalation status.

## Ticket Categories And What To Do

## Signup / Account Access Ticket

Customer says:

```text
I cannot sign up.
I cannot log in.
I created an account but cannot access the panel.
```

Check:

* Is the user Level 2?
* Is `users.is_active = 1`?
* Does the email exist once or multiple times?
* Does `id_owner` match the user ID?
* Does the account have Ophyra Base active?
* Is the account suspended?

Questions to ask customer:

```text
What email did you use to sign up?
Are you logging in from the web platform or a mobile app?
What exact message do you see?
Did you recently change your password?
```

Escalate if:

* login token/session appears broken;
* duplicate users are causing access conflict;
* customer can see another account's data;
* signup created the wrong user level.

## Billing / Payment Ticket

Customer says:

```text
My card was charged but my account is not active.
My payment failed.
I need to update my payment method.
I was charged twice.
```

Check:

```text
/panel/billing-automation
/panel/module-manager
/panel/payments
```

Verify:

* Stripe event status;
* `payments_all` record;
* membership due date;
* billing status;
* duplicate `billing_transaction_id`;
* add-on renewal date;
* admin notes.

Questions to ask customer:

```text
What email is on the account?
What date was the charge?
What amount was charged?
Was this Ophyra Base or an add-on?
Do you see the charge as pending or completed?
```

Support can:

* record a manual payment if verified;
* renew membership manually;
* activate/renew add-ons manually;
* add an internal note;
* mark the ticket for Stripe review.

Escalate if:

* Stripe shows paid but Ophyra has no event;
* event is `failed` or `pending_review`;
* duplicate charge is suspected;
* refund is requested;
* customer claims unauthorized charge.

## Module Activation Ticket

Customer says:

```text
I paid for Store but cannot see it.
I need Inventory activated.
My add-on disappeared.
```

Check:

```text
/panel/module-manager
/panel/membership/manage
```

Verify:

* Ophyra Base is active;
* add-on exists in `user_modules`;
* add-on status is `ACTIVE`;
* `renewal_at` is valid;
* payment exists in `payments_all`;
* module appears in dashboard/sidebar;
* backend access is not blocked.

Questions to ask customer:

```text
Which module did you activate?
When did you pay?
Can you see the module card in Billing & Modules?
Are you using the correct business owner login?
```

Support can:

* activate the module manually if payment is verified;
* set renewal date;
* gift module access when approved;
* add internal note.

Escalate if:

* module is active but still blocked;
* sidebar/dashboard does not match module access;
* payment and module data disagree.

## Affiliate / Commission Ticket

Affiliate says:

```text
My referral did not show up.
My commission is missing.
My payout is wrong.
```

Check:

```text
/panel/planner-hub/management/commissions/pending
/panel/module-manager
/panel/billing-automation
```

Verify:

* affiliate profile is approved;
* affiliate code is active;
* referral was created;
* referred user paid;
* commission exists;
* duplicate commission was not created;
* payout proof exists if paid.

Questions to ask:

```text
What affiliate code/link did you share?
What customer did you refer?
What email did the customer use?
What payment should have generated the commission?
```

Escalate if:

* referral cookie did not persist;
* commission missing despite confirmed referral and payment;
* duplicate commission exists;
* affiliate rate is wrong.

## Profile Setup Ticket

Customer says:

```text
I need help setting up my business profile.
My logo is not showing.
My public profile is incomplete.
```

Check:

```text
/panel/planner-hub/institution-profile
/panel/module-manager
```

Verify:

* profile exists;
* `company_name` is set;
* email/phone/location are correct;
* logo path exists;
* public slug exists if needed;
* profile fields are complete.

Questions to ask:

```text
What business name should appear publicly?
What phone/email should customers see?
Do you want to show an external website?
What city/state should be listed?
```

Escalate if:

* uploads fail;
* public page route is broken;
* profile data appears under wrong account.

## Operational Workflow Ticket

Customer says:

```text
I cannot create an order.
I cannot find a client.
My team member cannot see work.
Chat is not working.
```

Check:

* user level;
* owner scope;
* module access;
* client association;
* team assignment;
* order ownership;
* permissions.

Useful docs:

```text
docs/USER_COMPANY_ACCESS_MODEL.md
docs/ORDER_ACCESS_PAYMENT_FLOWS.md
docs/TEAM_CHAT_DELIVERY_OPERATIONS.md
```

Questions to ask:

```text
What screen are you on?
What action are you trying to complete?
Which client/order/team member is involved?
What exact error appears?
Is this happening for one record or all records?
```

Escalate if:

* data ownership looks wrong;
* Level 4 sees global data;
* Level 5 sees internal data;
* order/client relationship is broken;
* chat thread participants look incorrect.

## Mobile App Ticket

Customer says:

```text
The mobile app signup/login is not working.
The app does not keep me logged in.
Push notifications are not working.
```

Important:

```text
Mobile signup is for final clients of a specific brand, not Ophyra business signup.
```

Check:

* Is this VNV Events app, Avomeal app or another branded app?
* Is the user a final client?
* Is `id_user_business` or owner context present?
* Is the user Level 5?
* Is the client associated to the business owner?
* Is token validation working?

Useful docs:

```text
docs/MOBILE_APPS_ECOSYSTEM.md
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

Escalate if:

* app creates Level 2 business owner;
* app creates arbitrary levels;
* user is associated to wrong business;
* API token validation fails repeatedly.

## Severity Levels

### Severity 1 - Urgent

Handle immediately and escalate.

Examples:

* customer paid and cannot access account;
* customer can see another customer's data;
* Stripe webhook failures affecting many customers;
* duplicated charges;
* security or permission issue;
* Level 5 sees internal business data.

### Severity 2 - High

Handle same day.

Examples:

* add-on paid but not active;
* account marked `past_due` incorrectly;
* affiliate commission missing after confirmed payment;
* team member cannot access assigned work;
* business profile not visible after setup.

### Severity 3 - Normal

Handle within 1-2 business days.

Examples:

* profile content updates;
* general usage questions;
* module explanation;
* onboarding guidance;
* non-urgent affiliate questions.

### Severity 4 - Low

Backlog or planned improvement.

Examples:

* copy changes;
* minor UI confusion;
* feature requests;
* advanced automation requests.

## Escalation Rules

Support should escalate to technical/admin lead when:

* a payment exists in Stripe but not in Ophyra;
* webhook event is `failed`;
* webhook event is `pending_review` and payment/access is affected;
* user level or ownership is wrong;
* customer sees another customer's data;
* raw database correction seems necessary;
* module access is active but route is blocked;
* affiliate commission logic looks inconsistent;
* endpoint/API/mobile token behavior is broken.

Escalation note must include:

```text
Customer name:
Business name:
Email:
User ID:
Route/screen:
Module:
Payment amount/date:
Stripe event ID/invoice ID if available:
Internal payment ID if available:
What support already checked:
Screenshot/error:
Urgency:
```

## Customer Onboarding Checklist

Use this for every new Ophyra customer.

### Account

* Confirm user exists.
* Confirm user is Level 2.
* Confirm `id_owner = user ID`.
* Confirm account is active.
* Confirm business profile exists.

### Business Profile

* Business name.
* Business nature.
* Operation type.
* Phone.
* Email.
* City/state.
* Logo if available.
* Public profile slug if needed.

### Billing

* Free starter workspace available.
* Paid modules checked if the customer purchased add-ons.
* Membership due date set.
* Payment appears in `payments_all`.
* Billing status is `current`.
* Card is tokenized if saved.
* No raw card data collected.

### Modules

* Confirm required modules.
* Confirm add-on price.
* Confirm add-on payment if paid.
* Confirm `user_modules.status = ACTIVE`.
* Confirm `renewal_at`.
* Confirm sidebar/dashboard access.

### First Operational Test

Ask customer to complete a simple first workflow:

```text
Create or update profile.
Create a client.
Create an order or lead.
Invite/add a team member if needed.
Open dashboard and confirm modules.
```

### Close Onboarding

Before marking onboarding complete, answer:

```text
Can the customer log in?
Is billing active?
Are paid modules active?
Can the customer perform one real workflow?
Does support know who to contact if payment fails?
Are internal notes updated?
```

## QA Tests Support Can Run

### Billing Automation QA

* Open `/panel/billing-automation`.
* Confirm no urgent `failed` events.
* Confirm no unresolved `pending_review` events.
* Confirm recent paid invoices processed.
* Confirm duplicate events did not duplicate payments.

### Membership QA

* Open `/panel/module-manager`.
* Search customer.
* Confirm membership type and due date.
* Confirm billing status.
* Confirm recent payment record.

### Add-on QA

* Confirm add-on status is `ACTIVE`.
* Confirm renewal date is not expired.
* Confirm module appears in dashboard/sidebar.
* Confirm customer can access the route.

### Affiliate QA

* Confirm affiliate is approved.
* Confirm referral exists.
* Confirm payment exists.
* Confirm commission exists.
* Confirm payout status.

### Mobile Boundary QA

* Confirm mobile signup creates/associates final clients only.
* Confirm mobile signup does not create Ophyra business owners.
* Confirm final client is associated to correct business owner.

## Support Scripts / Response Templates

### Payment Failed

```text
Hi [Name], we saw that your recent Ophyra payment did not complete. Your account data is safe, and our team is reviewing the billing status. Please confirm whether your saved payment method is still valid or add a new payment method from Billing & Modules. We can also help process the renewal manually if needed.
```

### Add-on Activated

```text
Hi [Name], your [Module Name] add-on is now active. Please refresh your dashboard and check the sidebar. If you still cannot see it, send us a screenshot of your current dashboard and we will review access immediately.
```

### Need More Info

```text
Hi [Name], we can help with this. Please send us the email on your Ophyra account, the business name, the screen where the issue happens, and a screenshot or exact error message.
```

### Affiliate Review

```text
Hi [Name], we are reviewing the referral and commission record. Please send the affiliate link/code used, the referred customer's email, and the payment date or amount if available.
```

## Weekly Support Review

Once per week, review:

* number of new signups;
* number of paid accounts;
* number of add-on activations;
* unresolved `pending_review` events;
* failed payment count;
* past due accounts;
* affiliate commission issues;
* most common support tickets;
* tickets escalated to technical lead;
* customers blocked from receiving value.

Questions leadership should answer weekly:

```text
Are customers getting value after payment?
Are billing failures being caught quickly?
Are support tickets repeating because of unclear UI?
Are add-ons activating cleanly?
Are affiliate commissions trustworthy?
Do we need more automation or more support capacity?
```

## Definition Of A Resolved Ticket

A ticket is resolved only when:

* customer issue is understood;
* account/user was identified;
* route/module/payment was checked;
* action taken is documented;
* customer can continue using the system or has a clear next step;
* internal note exists for billing/account/module issues;
* escalation was created if support could not solve it.

## Launch Support Standard

During soft launch, support should prioritize:

1. Access to paid benefits.
2. Correct billing and renewals.
3. Module activation.
4. Data ownership and privacy.
5. Customer onboarding.
6. Affiliate trust.
7. Nice-to-have UI/content requests.

The goal is not perfect automation on day one.

The goal is:

```text
No paid customer gets stuck without a human path to recovery.
```
