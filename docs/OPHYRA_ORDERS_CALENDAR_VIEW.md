# Ophyra Orders Calendar View

> Avomeal web note: this calendar reference is useful only where Avomeal uses Store orders, subscriptions, delivery or production scheduling. Avomeal Store scope and owner `2` take priority over Ophyra naming.

## Purpose

Sprint 3 adds a visual calendar/agenda view for operational orders without replacing the existing order list.

Primary routes:

```text
/panel/planner-hub/management/orders/calendar
/panel/planner-hub/team/orders/calendar
```

The management route is available for Level 1, Level 2 and Level 3 route folders. The team route is available for Level 4 assigned-order workflows.

## Data Used

The calendar uses existing order data:

```text
orders.event_date
orders.start_time
orders.end_time
orders.status_workflow
orders.id_client
orders.address
users.name / users.lastname / users.email for client display
```

No new database fields are required for the initial version.

## Time Rule

The primary display time is selected in this order:

1. `execution_time` if present in a future payload.
2. `install_time` if present in a future payload.
3. `start_time`.
4. `event_time` if present in a future payload.
5. If no usable time exists, the order appears under `No time assigned`.

The current order table mainly uses `start_time` and `end_time`.

## Access And Scope

The management calendar reuses the same scope as the current management order list:

```text
OrdersRepository::getFiltered2([...LoginService::getUserIdAsArray(true), 'is_archived' => 0])
```

That means the calendar does not broaden access beyond what the current list already shows.

The team calendar uses:

```text
OrdersRepository::getOrdersByInvitation()
OrdersRepository::getOrdersByInvitationForOwner()
```

That keeps Level 4 users limited to assigned/invited orders and the selected workspace/company context.

## User Experience

The calendar supports:

* weekly navigation;
* Today, Previous and Next week controls;
* Week and Agenda display modes;
* status filtering;
* order cards by day and time;
* a clear `No time assigned` area;
* links back to the order list;
* click-through to the edit/details workflow for managers;
* click-through to tasks for team members.

## Files Added

```text
src/Services/OrdersCalendarService.php
src/views/panel/shared/orders-calendar/index.twig
src/views/panel/level1/planner-hub/management/orders/calendar/index.php
src/views/panel/level2/planner-hub/management/orders/calendar/index.php
src/views/panel/level3/planner-hub/management/orders/calendar/index.php
src/views/panel/level4/planner-hub/team/orders/calendar/index.php
```

## Files Updated

```text
src/views/panel/level1/planner-hub/management/orders/orders/index.twig
src/views/panel/level2/planner-hub/management/orders/orders/index.twig
src/views/panel/level3/planner-hub/management/orders/orders/index.twig
src/views/panel/level4/planner-hub/team/orders/orders/index.twig
```

## Future Improvements

Recommended next improvements:

1. Add a true day timeline with hour rows.
2. Add drag-and-drop rescheduling only after permission and audit rules are clear.
3. Include service-level execution/install times from `orders_services_notes`.
4. Add team-member filters for managers.
5. Add export/sync support after the internal view is stable.
