# Mobile API And Notifications Flow

> Avomeal web note: this mobile/API reference applies to Avomeal where the Avomeal app, public store, customer orders, subscriptions or notifications use the same endpoints. Preserve backward compatibility for mobile consumers.

## Purpose

The mobile app depends on the existing Ophyra web/backend. The app is mainly a navigation, WebView, portal and notifications layer. Business rules remain in the PHP/Twig backend.

The API files live under:

```text
src/views/api/
```

Do not rename existing API files or change endpoint URLs without a mobile compatibility review.

## Compatibility Rule

Existing JSON fields should remain available. When the mobile app needs more data, add fields instead of renaming or removing fields.

This sprint added compatible fields to auth responses and did not remove legacy fields.

## Mobile Login

## Mobile Signup Boundary

Ophyra commercial signup is web-only.

Mobile signup in branded apps is not the commercial signup flow for Ophyra. It should be used only to create or associate final clients of the business that owns that app.

Correct boundary:

```text
Web signup = Ophyra business/customer registration, memberships, modules, billing and affiliates.
Mobile signup = final-client registration for a specific brand app.
```

Mobile signup must not:

* create new Ophyra business owners,
* create new Ophyra SaaS memberships,
* activate Ophyra modules,
* create affiliate profiles,
* accept arbitrary user levels from the app.

If a mobile endpoint creates a user, it should create or associate a client, normally Level 5, and bind that client to the app/business owner through `id_user_business`, `id_owner` or the equivalent existing relationship for that branded app.

Current guarded endpoint:

```text
src/views/api/auth/signup.php
```

This endpoint is now guarded as a brand-client signup endpoint. It accepts only Level 5 final-client signup, rejects business-owner levels, does not activate Ophyra memberships/modules/affiliates, and associates the client to the business owner sent as `id_user_business`, `id_owner`, `business_id` or `owner_id`.

For legacy branded apps that do not yet send an owner, it falls back to the first active Level 1 owner so the mobile app does not create an unscoped business account. Each branded app should still be updated to send its explicit owner/business id.

The same boundary is also enforced for:

```text
src/views/api/auth/google/signup-app.php
src/views/api/auth/apple-signing/client-app.php
src/views/api/auth/change-level.php
```

Google and Apple app signup accept only `accountType = client`. `change-level` no longer allows mobile users to switch into Level 2 or Level 3 business-owner/legacy business roles.

Main endpoint:

```text
POST /api/auth/login
src/views/api/auth/login.php
```

Supported login methods:

* Email and password.
* Google.
* Apple.

The endpoint still returns the existing `token` field. It also returns `api_token` with the same value for clearer mobile use.

The `user` object includes the existing user fields plus mobile-safe context fields:

```text
id
name
lastname
email
phone
phone_validation
membership_due_date
membership_type
level
id_owner
owner_scope_id
raw_id_owner
allow_chat_with_clients
is_active
ui_language
system_language
```

The app can send an Expo token during login using either:

```text
expo_token
expo_push_token
```

If the Expo token is valid, it is saved on `users.expo_token` and the response includes:

```text
expo_token_saved
```

## Token Validation

Endpoint:

```text
POST /api/auth/validate-token
src/views/api/auth/validate-token.php
```

The endpoint accepts token from:

* JSON body `token`.
* JSON body `api_token`.
* Form body `token`.
* Form body `api_token`.
* `Authorization: Bearer <token>`.

The response preserves `success`, `message` and `user`.

## Shared API Auth Helper

Shared helper:

```text
src/Services/ApiAuthService.php
```

Responsibilities:

* Read bearer tokens from headers.
* Read token values from JSON, form body or query fallback.
* Prefer explicit API token over an existing WebView/browser session.
* Return a consistent mobile user payload.
* Validate Expo token format before saving or sending.

Important behavior:

If a request sends `Authorization: Bearer ...`, that token is used before any existing PHP session. This avoids WebView/session mixups where the app could accidentally inherit an old browser session.

## Expo Push Token Registration

Endpoint:

```text
POST /api/set-expo-push-token
src/views/api/set-expo-push-token.php
```

Accepted auth:

* `Authorization: Bearer <api_token>`.
* JSON or form field `token`.
* JSON or form field `api_token`.

Accepted Expo token fields:

```text
expo_push_token
expo_token
expoPushToken
pushToken
```

Valid token formats:

```text
ExponentPushToken[...]
ExpoPushToken[...]
```

Response fields:

```text
success
message
user_id
expo_token_saved
```

Common previous failure causes:

* The app sent `expo_token` while the backend only expected `expo_push_token`.
* The app sent form-data while the backend expected JSON only.
* The token was sent in the body while the backend only read bearer auth.
* A WebView session and a mobile token pointed to different users.
* The Expo token format was invalid but not reported clearly.

## Push Notification Service

Service:

```text
src/Services/NotificationService.php
```

The service sends to Expo using:

```text
https://exp.host/--/api/v2/push/send
```

Current behavior:

* Skips empty or invalid Expo tokens.
* Sends title, body and optional `data`.
* Logs cURL failures.
* Logs Expo rejected responses.
* Keeps existing callers compatible because the `data` parameter is optional.

Current known senders:

* WhatsApp incoming message notification to the owner when configured.
* Existing staff/user invitation notification flows.
* Future chat, order, Store and delivery events should reuse `NotificationService`.

## API Endpoint Inventory

The API folder currently contains these endpoint groups.

### General

```text
src/views/api/version.php
src/views/api/404.php
src/views/api/change-language/index.php
src/views/api/check-auth/index.php
```

Notes:

* `check-auth` is session/WebView oriented.
* `change-language` depends on the current session.

### Authentication

```text
src/views/api/auth/login.php
src/views/api/auth/validate-token.php
src/views/api/auth/change-level.php
src/views/api/auth/forgot-password.php
src/views/api/auth/signup.php
src/views/api/auth/google/signup-app.php
src/views/api/auth/apple-signing/client.php
src/views/api/auth/apple-signing/client-app.php
src/views/api/auth/apple-signing/vendor.php
src/views/api/auth/apple-signing/venue.php
```

Notes:

* `login`, `validate-token` and `change-level` are mobile-token aware.
* Apple/Google signup files include legacy client/vendor/venue flows. They were not removed because mobile compatibility may depend on them.
* Visual Google/Apple buttons may be hidden in web UI, but backend files remain for compatibility unless intentionally retired later.

### Expo

```text
src/views/api/set-expo-push-token.php
```

This is the canonical endpoint for saving Expo push tokens after login.

### Chat

```text
src/views/api/chat/messages.php
```

This endpoint now supports session or bearer token auth and verifies that the current user is one of the thread participants before returning messages.

It still uses the existing participant-thread model. It does not create a new order-specific chat schema.

### Clients

```text
src/views/api/clients/search.php
src/views/api/clients/check.php
src/views/api/clients/associate.php
```

These are panel/AJAX-oriented and session based. They are tied to owner/client association behavior and should be changed carefully.

### Orders And Public Order Access

```text
src/views/api/orders/generate-token.php
src/views/api/public/order-access.php
```

These are related to customer-facing order access links. They are separate from mobile login tokens and from Ophyra subscription billing.

Do not merge these token flows with `api_token`.

See:

```text
docs/ORDER_ACCESS_PAYMENT_FLOWS.md
```

### Daily Recommendations And Evaluator Settings

```text
src/views/api/panel/daily-recommendations/index.php
src/views/api/panel/business-evaluator-settings/index.php
```

These endpoints now support session or bearer token auth.

Recommendations should use real business data and Business Profile location. They must not invent market data or hardcode New York as a default.

### Agent / AI Support

```text
src/views/api/panel/agent-chat.php
src/views/api/panel/agent-usage/index.php
```

These are panel-oriented AI endpoints. They should remain module/permission aware when used from mobile.

### Public Catalog Legacy

```text
src/views/api/services/index.php
src/views/api/services/categories.php
src/views/api/venues/index.php
src/views/api/venues/categories.php
```

These are legacy public catalog endpoints. They may still use venue/vendor concepts. Keep routes stable until mobile/public consumers are confirmed.

### Tickets

```text
src/views/api/tickets/index.php
src/views/api/tickets/[id]/index.php
src/views/api/tickets/info/index.php
src/views/api/tickets/availability/index.php
```

These support Ticket Sales + RSVP / Event Registration.

### WhatsApp / SMS / Voice

```text
src/views/api/whatsapp/clients.php
src/views/api/whatsapp/messages.php
src/views/api/whatsapp/send.php
src/views/api/whatsapp/incoming.php
src/views/api/whatsapp/update-name.php
src/views/api/whatsapp/sms_incoming.php
src/views/api/whatsapp/sms_send.php
src/views/api/whatsapp/voice.php
```

These endpoints support Twilio-style inbound/outbound communication. Incoming message notification can use `NotificationService` when the owner has an Expo token.

### Calendar, Documents, Forum And DocuSign

```text
src/views/api/calendar/add-event.php
src/views/api/calendar/auth-callback.php
src/views/api/documents/log.php
src/views/api/docusign/document.php
src/views/api/forum/like.php
```

These are integration/module endpoints and should not be treated as generic mobile auth endpoints without review.

## Company Context Rules

Ophyra uses the business owner ID as the primary company scope in the current architecture.

General rules:

* Level 1 has global access and can operate VNV Events separately from Ophyra Global Admin.
* Level 2 operates its own business scope.
* Level 4 works in a selected company workspace when associated with multiple companies.
* Level 5 sees their own orders and purchases across companies; each row should identify the selling business.

The mobile auth payload exposes:

```text
owner_scope_id
raw_id_owner
level
```

These fields help the app understand the current user's operating context without changing existing fields.

No new hardcoded VNV owner fallback was added in this sprint. If a legacy endpoint already relies on a fixed owner or old catalog behavior, document it before changing it.

See:

```text
docs/USER_COMPANY_ACCESS_MODEL.md
```

## Chat Rules For Mobile

Mobile chat must follow the same backend rules as web:

* Admin can talk with permitted users.
* Team Members can talk with Admin and Team Members in their company context.
* Team Members can talk with clients only when `allow_chat_with_clients` allows it and the business workflow permits it.
* Clients cannot talk with other clients.
* Clients only see conversations related to their business/order relationship.

`src/views/api/chat/messages.php` now validates that the authenticated user is a participant in the thread before returning messages.

## Store, Delivery And Tracking

Store / Commerce remains documented in:

```text
docs/STORE_COMMERCE_FLOW.md
```

Team, delivery and tracking are documented in:

```text
docs/TEAM_CHAT_DELIVERY_OPERATIONS.md
```

Mobile endpoints for Store/delivery should keep these rules:

* Level 1 can test everything.
* Level 2 needs the Store module active for operational Store management.
* Level 4 only sees assigned Store or service work for the selected company.
* Level 5 only sees their own purchases/orders.
* Location tracking is task-scoped and requires permission before clock-in or delivery start.

## WebView

WebView routes use the normal panel routes and may rely on session validation after token handoff.

Do not treat WebView panel routes as pure JSON endpoints.

If the app opens a panel route after mobile login, validate:

* The mobile app has a valid `api_token`.
* Token validation succeeds.
* Session is created for the correct user.
* The panel route respects the user's level, company context and module access.

## Testing Checklist

### Mobile Login

1. Call `POST /api/auth/login` with email/password.
2. Confirm response includes `token`, `api_token`, `expo_token_saved` and `user`.
3. Confirm `user.level`, `user.owner_scope_id` and `user.is_active`.
4. Repeat with `expo_token`.
5. Repeat with `expo_push_token`.

### Token Validation

1. Call `POST /api/auth/validate-token` with JSON `token`.
2. Call it again with `Authorization: Bearer <token>`.
3. Confirm both return the same user payload.

### Expo Token Save

1. Log in and capture `api_token`.
2. Call `POST /api/set-expo-push-token` with `Authorization: Bearer <api_token>`.
3. Send `expoPushToken` or `expo_token`.
4. Confirm `success=true` and `expo_token_saved=true`.
5. Confirm `users.expo_token` updated for the correct user.

### Push Notification

1. Save a real Expo push token from a physical device or Expo simulator.
2. Trigger an existing notification sender or call `NotificationService` from a safe local test harness.
3. Confirm Expo accepts the payload.
4. Check application logs for Expo rejected responses.

### Chat

1. Call `GET /api/chat/messages?thread_id=...` as a thread participant.
2. Confirm messages return.
3. Call as a non-participant.
4. Confirm `403` response.

### WebView

1. Log in from mobile.
2. Open the expected panel WebView route.
3. Confirm the route loads the correct user context.
4. Confirm the route does not inherit a stale browser user.

## Remaining Risks

* Several legacy endpoints remain session-based because they are panel AJAX or public catalog endpoints.
* Apple/Google legacy signup endpoints still exist for compatibility even if web UI buttons are hidden.
* Full Store/order/task notification triggers are not globally wired in every workflow yet; `NotificationService` is ready for those triggers.
* Some API endpoints still need deeper role and company scoping before being used as first-class mobile endpoints.
* Real push delivery requires a real Expo token and network access; PHP lint cannot verify Expo delivery.

Final role-by-role demo QA is tracked in:

```text
docs/OPHYRA_FINAL_STABILIZATION_QA.md
```
