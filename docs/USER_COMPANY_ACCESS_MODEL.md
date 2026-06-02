# User, Company And Access Model

> Avomeal web note: this access model is shared architecture. For the current Avomeal direction, Avomeal operates under the confirmed VNV Events business scope `id_user_business = 2` / `id_owner = 2`.

## Purpose

This document defines how Ophyra understands business accounts, users, team members, clients, orders, modules and access.

The system remains intentionally owner-scoped. It does not introduce a full tenant or workspace architecture in this phase.

Mobile endpoints and WebView/API authentication must respect this company model. See:

```text
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

## Company / Business Account

A company is the operational unit of the system.

The current implementation represents a company through:

* `users.id` for the business owner account.
* `users.level = 2` for standard Business Owner accounts.
* `institution_profile.id_owner` for the public and operational Business Profile.
* `id_owner` columns on operational records such as orders, Store orders, payroll hours, tasks, payments and modules.

The company owns:

* Business Profile.
* Membership and renewal state.
* Active modules in `user_modules`.
* Team relationships.
* Client relationships.
* Service orders.
* Store orders.
* Operational reports.
* Chat relationships.
* Public profile and future custom-domain requests.

Level 1 can also operate company contexts such as VNV Events Operations and Avomeal Operations, but Level 1 remains the global administrator of the platform.

Avomeal now uses the confirmed VNV Events business owner scope for products, Store orders, subscriptions, payments, delivery tasks and reports:

```text
id_user_business = 2
id_owner = 2
```

Do not treat Avomeal as a separate database or separate owner in this repo unless a future migration explicitly changes that decision.

## Level 1

Level 1 is Ophyra Global Admin.

Level 1 can:

* View platform users and business accounts.
* View and manage modules by owner.
* Activate, deactivate, gift or expire modules.
* Suspend and reactivate accounts without deleting history.
* Review payments, memberships, affiliates and custom-domain requests.
* Access every operational module by default for testing, VNV Events Operations and Avomeal Operations.

Level 1 reports must not mix Ophyra SaaS revenue with Business Operations revenue.

## Level 2

Level 2 is the standard Business Owner level for new Ophyra businesses.

Level 2 operates its own company. Queries and module access should use the owner ID for that company.

Level 2 can:

* Edit its Business Profile.
* Manage clients associated to that company.
* Manage team members associated to that company.
* Create and manage orders for that company.
* Use Store, Services, Inventory, Tickets or AI Advisor only when the corresponding module is active or manually approved.
* See Business Operations reports scoped to the company.

Level 2 must not see data from another company.

## Team Members / Level 4

Level 4 represents internal team members or employees.

A Team Member can work for one or more companies through `user_institutions`.

The current company context is stored in:

```text
user_workspace_preferences
```

and mirrored in session values:

```text
current_institution_id
current_institution_role
```

`UserWorkspaceContextService::getTeamContext()` resolves the selected institution, selected owner, role and available companies.

Level 4 views should filter operational data by the selected owner/institution:

* Service/event assignments from `orders_staff_invites` and `orders_team_tasks`.
* Store assignments from `store_order_tasks`.
* Payroll hours from `payroll_hours.id_owner`.
* Chat participants from the selected owner and allowed clients.

The unified work screen is:

```text
/panel/planner-hub/team/my-work
```

It combines service/event tasks and Store fulfillment tasks without merging their tables.

Team Members can create their own Business Account by switching to Business Owner View, but they must still be able to return to Team View and their company assignments.

## Clients / Level 5

Level 5 represents end customers.

A client can be associated with multiple companies through:

```text
clients_users(client_id, id_owner_asociated)
```

The client portal does not require the client to switch companies to see orders.

Client order lists should show all orders belonging to the logged-in client and identify the selling business on each row.

Company context can still be used for focused actions such as messaging a specific business or viewing company-specific media, but orders and Store purchases should not disappear simply because another company context is selected.

Level 5 can see:

* Its own service/event orders.
* Its own Store orders by user ID or guest email.
* The business name, logo or profile tied to each order when available.
* Related contracts, files, payment links, fulfillment status and chat links.

Level 5 cannot see internal company data, other clients or global reports.

## Clients And Business Relationships

`clients_users` is the business-client association table.

Rules:

* If a client already exists by email, associate the existing user instead of duplicating when possible.
* A business can see clients associated to its owner ID.
* A client can be associated to more than one owner ID.
* Removing a client from a company should remove the association, not physically delete historical orders.
* Orders remain the audit trail of who sold to whom.

## Team And Business Relationships

`user_institutions` is the team-company association table.

Rules:

* A user may have a primary institution and secondary institutions.
* Role, hourly rate and contract detail are relationship data and belong on `user_institutions`.
* Payroll and operational tasks must remain owner-scoped.
* A Team Member should not see tasks for another company unless the selected workspace belongs to that company.

## Orders By Company

Service/event orders use:

```text
orders.id_owner
orders.id_client
```

Store orders use:

```text
store_orders.id_owner
store_orders.id_user
store_orders.guest_email
```

Rules:

* Every order should belong to a selling owner/company.
* Level 2 sees orders for its owner ID.
* Level 4 sees assigned orders for the selected owner/company.
* Level 5 sees all of its own orders across companies and each row must identify the selling company.
* Level 1 can see all or filter by company.

## Modules By Company

Modules are owner-scoped through:

```text
user_modules.id_user
```

where `id_user` is the business owner ID.

Rules:

* Level 1 bypasses module hiding for testing and administration.
* Level 2 requires active modules for paid tools.
* Level 4 inherits access from the selected company plus its own role/assignment permissions.
* Level 5 does not buy modules directly; it sees portal/order access based on its relationship to the business and order.

## Chat By Company

The current chat schema uses direct one-to-one threads:

```text
chat_threads(id_user_1, id_user_2)
chat_messages(id_thread, id_user, message)
```

There is no `chat_thread_participants` or order-specific conversation table in the current schema.

Rules:

* Admin and business owners can message related team members and clients.
* Team Members can message admins/team in the selected company.
* Team Members can message clients only when `users.allow_chat_with_clients = 1` and the client is associated to the selected owner.
* Clients can message associated businesses.
* Clients cannot message other clients.

Future order-specific chat would require a deliberate schema extension.

## Reports By Company

Business Operations reports must filter by owner/company and date range.

Ophyra Global Admin reports can aggregate platform-wide subscriptions, modules, users and affiliate payouts.

Avomeal Operations reports are Business Operations reports scoped to owner `2`. They may roll up under the VNV Events business owner, but they must not be mixed with Ophyra SaaS subscription revenue.

Do not mix:

* Ophyra subscription revenue.
* Business customer-order revenue.
* Store sales.
* Payroll.
* Affiliate payouts.

## Suspension And Deletion

Do not hard-delete suspicious users when they may have orders, payments, chats or history.

Preferred actions:

* Suspend account with `users.is_active = 0`.
* Reactivate if needed.
* Preserve payments, orders, chat and client relationships.

## SQL Used

This model uses existing tables already present in the project:

```text
users
institution_profile
user_institutions
user_workspace_preferences
clients_users
orders
orders_team_tasks
orders_staff_invites
store_orders
store_order_tasks
payroll_hours
chat_threads
chat_messages
modules
user_modules
```

No new SQL is required for this document beyond the existing workspace preference migration/script already created in this project.

Avomeal meal-prep structures are tracked separately in:

```text
db/avomeal_ophyra_integration.sql
docs/AVOMEAL_OPHYRA_INTEGRATION_MODEL.md
```

Final demo stabilization SQL is tracked in:

```text
db/final_stabilization_required.sql
docs/OPHYRA_FINAL_STABILIZATION_QA.md
```

## Pending Improvements

* Add a formal order-specific chat schema only when needed.
* Add richer company-level reporting filters in every legacy report screen.
* Audit older Level 4 management pages that still use `id_owner` from session instead of `UserWorkspaceContextService`.
* Keep replacing legacy Planner Hub labels with Ophyra labels without changing technical routes.
