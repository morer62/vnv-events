# VNV Events Mobile Navigation and Clock Contract

Updated: 2026-06-29

This file defines the mobile WebView navigation contract for VNV Events and the bridge required by the level 4 clock when the user is inside the React Native app.

## Source of Truth

Use `docs/vnv-mobile-navigation.json` as the machine-readable file for the mobile app.

Every `route` in that JSON is an internal web route without a leading slash. The mobile app should open it through the token WebView pattern:

```text
https://vnvevents.com/Panel/Tokenapi/{token}/{route}
```

The token route validates the user, creates the web session, marks the request as mobile, and redirects to the requested internal route.

## User Levels

### Level 1: Owner / Admin

Main buttons:

- Event Orders: `panel/planner-hub/management/orders`
- Order Calendar: `panel/planner-hub/management/orders/calendar`
- CRM: `panel/planner-hub/management/crm`
- Team: `panel/planner-hub/management/users`

Secondary buttons:

- Payroll: `panel/planner-hub/management/payroll`
- Storage: `panel/planner-hub/management/storage`
- Team Chat: `panel/planner-hub/team/chat`
- Planning Tools: `panel/planning-tools`
- CMS: `panel/cms`
- SEO Center: `panel/seo-center`
- Settings: `panel/settings`

### Level 4: Team Member

Main buttons:

- Time Clock: `panel/planner-hub/team/payroll/clock`
- Assigned Jobs: `panel/planner-hub/team/orders/orders/`
- My Work: `panel/planner-hub/team/my-work`
- Order Calendar: `panel/planner-hub/team/orders/calendar`

Secondary buttons:

- Pending Hours: `panel/planner-hub/team/payroll/pending`
- Contracts: `panel/planner-hub/team/contracts`
- Team Chat: `panel/planner-hub/team/chat`
- Planning Tools: `panel/planning-tools`
- VNV Sessions: `search/multimedia-sessions/main`
- Settings: `panel/settings`

### Level 5: Client

Main buttons:

- My Orders: `panel/planner-hub/orders/orders`
- VNV Sessions: `search/multimedia-sessions/main`

Secondary buttons:

- Planning Tools: `panel/planning-tools`
- Chat: `panel/chat`
- Forums: `forums`
- Settings: `panel/settings`

## Clock Location Bridge

The level 4 clock must receive location from the native app because React Native WebView does not reliably expose browser geolocation to the page.

The clock now accepts location through any of these paths:

1. Injected global variable:

```js
window.OPHYRA_MOBILE_LOCATION = {
  latitude: 26.123,
  longitude: -80.123,
  accuracy: 12,
  platform: 'ios',
  source: 'vnv_mobile_app',
  permission_status: 'granted',
  device_id: 'device-id',
  updated_at: new Date().toISOString()
};

window.dispatchEvent(new CustomEvent('ophyra:mobile-location', {
  detail: window.OPHYRA_MOBILE_LOCATION
}));
```

2. WebView message:

```json
{
  "type": "OPHYRA_MOBILE_LOCATION",
  "location": {
    "latitude": 26.123,
    "longitude": -80.123,
    "accuracy": 12,
    "permission_status": "granted"
  }
}
```

3. Query parameters on the WebView URL:

```text
?mobile_lat=26.123&mobile_lng=-80.123
```

Accepted latitude names: `mobile_lat`, `lat`, `location_lat`.

Accepted longitude names: `mobile_lng`, `lng`, `long`, `location_long`.

## Location Ping Endpoint

The app can also keep the server updated with the latest native location:

```text
POST /api/mobile/location/ping
Authorization: Bearer {token}
Content-Type: application/json
```

Body:

```json
{
  "latitude": 26.123,
  "longitude": -80.123,
  "accuracy": 12,
  "platform": "ios",
  "source": "vnv_mobile_app",
  "permission_status": "granted",
  "context": "payroll_clock",
  "event_type": "LOCATION_UPDATE",
  "id_owner": 1
}
```

For the clock, the app should still inject or message the location into the WebView before the user taps clock in/out. The endpoint is for constant updates and for keeping an open payroll row refreshed when one exists.

## Level 4 Task Order

The current level 4 task list uses event-first ordering:

- Event date ascending.
- Install time when available, otherwise event start time.
- Pending tasks before completed tasks.
- Newer task records after that.

That keeps assigned event work in a predictable mobile order and improves on the older VNV_venues clock/task ordering.
