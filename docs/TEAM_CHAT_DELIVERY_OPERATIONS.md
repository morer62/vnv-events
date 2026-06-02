# Team, Chat And Delivery Operations

> Avomeal web note: this document is active where Avomeal uses team chat, customer chat, Store/catalog fulfillment, delivery or Level 4 work. Keep visible labels and permissions aligned with Avomeal and owner `2`.

## Purpose

Ophyra presents service/event work and Store fulfillment in one Team Member experience while preserving their existing data models.

```text
Service and event tasks: orders_team_tasks
Store fulfillment tasks: store_order_tasks
Store workflow summary: store_order_workflow
Store tracking history: store_delivery_location_logs
```

The unified Level 4 screen is:

```text
/panel/planner-hub/team/my-work
```

Technical `/planner-hub/` routes remain unchanged for compatibility.

## Team Member My Work

Level 4 sees the selected company workspace and two coordinated work sources:

* Service and event tasks continue opening the existing service-order task flow.
* Store work items support preparation, assistance, delivery, fulfillment, customer support and other operational tasks.
* A Team Member can belong to more than one company. The current workspace controls which assignments appear.
* Payroll clock-in remains separate but is linked from `My Work`.
* The selected workspace is resolved through `UserWorkspaceContextService` and persisted in `user_workspace_preferences`.

## Store Delivery Flow

Store payment status and operational status are intentionally separate. A paid order can move through preparation and delivery without changing its payment audit history.

Operational statuses:

```text
NEW
CONFIRMED
PROCESSING
IN_PREPARATION
READY
READY_FOR_DELIVERY
OUT_FOR_DELIVERY
DELIVERED
COMPLETED
CANCELLED
```

Level 1 and Level 2 use Store Orders to:

* Assign a preparation user.
* Assign a delivery user.
* Add additional operational tasks.
* Allow or deny delivery closure by the assigned Team Member.
* Allow contextual customer communication.
* Review latest delivery coordinates.
* Open delivery proof when present.

When a Team Member closes an allowed delivery, the order becomes `DELIVERED`, the task becomes `COMPLETED`, coordinates are logged and an optional JPG, PNG or WEBP proof image up to 5 MB is stored through the existing Cloudinary upload pattern.

If delivery closure is not allowed, the Team Member requests review and the task becomes `WAITING_REVIEW`. The owner approves that delivery from the Store Orders board.

## Location Permission And Tracking

Location access is required before Level 4 clock-in and before starting Store tasks marked as requiring location. Delivery actions always require location.

The browser message is:

```text
You need to allow location access to start this task.
```

While a delivery task is active and the Team Member keeps `My Work` open, the browser sends a location update at most once per minute. Tracking is task-scoped. It is not intended to track employees outside active work.

Admin and customer views show the latest known location only when relevant. Historical rows remain in `store_delivery_location_logs`.

## Chat Rules

Existing chat repositories remain in use:

```text
ChatThreadRepository
ChatMessageRepository
```

No parallel Store chat system was created.

Mobile chat/API token behavior and Expo notification registration are documented in:

```text
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

Rules:

* Admin can communicate with all permitted workspace users.
* Team Members can communicate with Admin and other Team Members in their selected workspace.
* Team Members can communicate with clients only when their user permission `allow_chat_with_clients` is enabled and the Store workflow permits contextual customer communication.
* The client must be associated with that business through `clients_users`.
* Clients can communicate with an associated business from their client portal.
* Clients cannot communicate with other clients.

The current chat schema stores participant threads, not order-specific channels. Store order buttons open the permitted participant thread in context. A future per-order conversation model would require an explicit schema extension and migration.

## Access By Level

* Level 1 has complete operational access for VNV Events testing and administration.
* Level 2 manages Store assignments only when the Store add-on is active.
* Level 4 sees assignments for the currently selected company workspace and updates only tasks assigned to that user.
* Level 5 sees Store purchases, fulfillment status, delivery location while relevant, proof when available and a link to message the associated business.

Team Members use a company workspace. Clients do not need a mandatory company workspace to see orders; they see their own orders across associated businesses, with the selling business identified on each order.

See:

```text
docs/USER_COMPANY_ACCESS_MODEL.md
```

## SQL

Run:

```text
db/store_team_delivery_operations.sql
db/final_stabilization_required.sql
```

The Store delivery script preserves legacy Store statuses, adds operational statuses, extends `store_order_workflow`, creates `store_order_tasks` and creates `store_delivery_location_logs`. The final stabilization script adds conservative support tables referenced by legacy reports/services. Both are safe to run again.

## Manual Test Checklist

1. Create or reuse a paid Store order.
2. Assign preparation and delivery from Level 1 or Level 2 Store Orders.
3. Enable or disable Team Member delivery closure.
4. Enable contextual customer communication.
5. Log in as the assigned Level 4 user and open `My Work`.
6. Deny location permission and confirm clock-in or delivery start is blocked.
7. Allow location, start delivery and confirm admin sees a latest-location link.
8. Close delivery with a proof image and confirm Level 5 sees the proof.
9. Disable delivery closure and confirm the task changes to `WAITING_REVIEW`.
10. Confirm a Level 4 user without `allow_chat_with_clients` cannot start a customer chat.

## Pending Risks

* Provider-specific Store checkout sandbox testing remains required.
* Location updates require HTTPS or localhost browser context.
* The current implementation stores latest-location links, not a continuously updating map canvas.
* Existing service/event tasks remain in their original tables and screens by design.

## Dashboard reporting

Business dashboards count open `store_order_tasks` together with open `orders_team_tasks` so service and Store execution appear as one operational workload. Collected revenue remains separate: Store collections come from paid `store_payments`, while service collections come from order payment records.
