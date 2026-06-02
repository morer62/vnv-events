# Ophyra Admin User, Modules and Reports Control

> Avomeal web note: this is inherited Ophyra/platform reference material. In this repo, apply it only when Avomeal needs the same admin/reporting behavior under owner `2`. Do not let Ophyra SaaS billing language override Avomeal store/food workflows.

## Two reporting contexts

Level 1 has two clearly separated contexts:

* **Ophyra Global Admin** reports platform users, paid memberships, active modules, subscription payments, failed autopay attempts, affiliate payouts and custom-domain requests.
* **Business Operations** reports CRM, service orders, Store orders, collected operational revenue, pending payments, payroll hours, tasks, delivery work and abandoned carts for the selected operating owner.

Never mix Ophyra subscription revenue with revenue collected by a business from its customers.

Company/user ownership and cross-company client/team behavior are defined in `docs/USER_COMPANY_ACCESS_MODEL.md`. Admin reports can aggregate across companies, but operational reports should always be scoped to a selected owner/company unless the screen is explicitly global.

## Level 1 account control

The Level 1 module manager is the primary manual account-control surface:

```text
/panel/module-manager
```

It supports:

* search by owner name, email or company
* filter by active or suspended account
* filter by active add-on
* edit Ophyra Base renewal date
* suspend or reactivate an account without deleting history
* activate, deactivate, gift, expire or cancel add-ons
* set add-on expiration dates
* record an external/manual membership payment
* save internal notes
* view tokenized saved-card metadata and recent admin activity

Administrative actions are stored in:

```text
admin_account_actions
```

Apply:

```text
db/admin_user_module_reports_control.sql
db/final_stabilization_required.sql
```

## Safe payment handling

Never store card number or CVV in admin forms.

`user_cards` stores processor identifiers plus display metadata such as brand and last four digits. Level 1 can charge the main saved Stripe customer token for Ophyra Base through the existing Stripe service. The action requires an explicit amount and renewal date and writes an auditable `payments_all` record after a successful charge.

Square and PayPal admin-side tokenized charging remain manual until a verified reusable-token flow exists for those providers. Use external payment collection and the manual payment record when needed.

## Reliable Business Operations reports

`BusinessOperationsReportService` supports:

* today
* this week
* this month
* last month
* custom date range

Collected revenue is based on real payment records:

* service/event collections: `orders_payments` and `orders_advances`
* Store collections: `store_payments` with `status = PAID`

Pending revenue stays separate from collected revenue. The dashboard also shows open leads, unsigned contracts, abandoned carts, payroll hours and open service/Store tasks.

## Recommendation behavior

Daily insight is intentionally compact. It is refreshed on login from internal business data and links to the relevant module.

Location comes from `institution_profile`. Do not use a hardcoded city or a fallback market location. When profile location is missing, recommend completing Business Profile.

## Account removal

Do not hard-delete suspicious users from admin. Suspend the account with `users.is_active = 0`. Preserve orders, payments, cards, chats and operational history for review.

## Future work

* Global automatic subscription renewal cron remains out of scope.
* Module-payment ledger records can be expanded when add-on checkout is connected.
* Square and PayPal reusable admin-charge support requires verified provider-token flows.
