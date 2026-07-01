# Mobile App Broadcast Notifications

This document explains the backend changes for VNV Events mobile app broadcast notifications and how the React Native app should consume them.

## What Exists In The Backend

Level 1 CMS / Growth Content now includes a **Mobile app push** block at:

`/panel/cms`

Admins can send:

- Title
- Message body
- Optional link

When sent, the backend:

1. Creates a row in `mobile_app_broadcasts`.
2. Finds active users with a saved `expo_token`.
3. Creates one row per recipient in the existing `notifications` table.
   - These rows are marked internally with `link` starting with `mobile-app-broadcast://`.
   - Mobile notification APIs only expose rows with that internal marker.
   - Private notifications, payment alerts, contract alerts, and operational messages are not returned by the mobile broadcast APIs.
4. Sends an Expo push notification to each recipient.
5. Includes navigation metadata in the Expo push `data` payload.

## Expo Push Payload

The backend sends Expo pushes shaped like:

```json
{
  "to": "ExponentPushToken[...]",
  "sound": "default",
  "title": "New VNV Sessions mix is live",
  "body": "Tap to listen now.",
  "data": {
    "type": "mobile_app_broadcast",
    "broadcast_id": 12,
    "notification_id": 345,
    "link": "https://vnvevents.com/vnv-sessions/",
    "screen": "NotificationDetail"
  }
}
```

The app should use `data.notification_id` to open the notification detail screen.

## API Authentication

Use the logged-in user's API token from mobile login.

Preferred header:

```http
Authorization: Bearer API_TOKEN_HERE
```

The backend also accepts `?token=API_TOKEN_HERE`.

## List Notifications

```http
GET /api/mobile/notifications
Authorization: Bearer API_TOKEN_HERE
```

Response:

```json
{
  "success": true,
  "data": [
    {
      "id": 345,
      "title": "New VNV Sessions mix is live",
      "body": "Tap to listen now.",
      "message": "New VNV Sessions mix is live\nTap to listen now.",
      "link": "https://vnvevents.com/vnv-sessions/",
      "raw_link": "mobile-app-broadcast://12?link=https%3A%2F%2Fvnvevents.com%2Fvnv-sessions%2F",
      "is_read": false,
      "created_at": "2026-07-01 15:30:00",
      "type": "mobile_app_broadcast"
    }
  ]
}
```

The backend returns the newest notification first. This endpoint is not a global inbox; it only returns notifications sent from the **Mobile app push** block in CMS / Growth Content.

## Notification Detail

```http
GET /api/mobile/notifications/detail?id=345
Authorization: Bearer API_TOKEN_HERE
```

Response:

```json
{
  "success": true,
  "data": {
    "id": 345,
    "title": "New VNV Sessions mix is live",
    "body": "Tap to listen now.",
    "message": "New VNV Sessions mix is live\nTap to listen now.",
    "link": "https://vnvevents.com/vnv-sessions/",
    "raw_link": "mobile-app-broadcast://12?link=https%3A%2F%2Fvnvevents.com%2Fvnv-sessions%2F",
    "is_read": true,
    "created_at": "2026-07-01 15:30:00",
    "type": "mobile_app_broadcast"
  }
}
```

Opening detail marks the notification as read.

## Mark As Read Without Opening Detail

```http
POST /api/mobile/notifications
Authorization: Bearer API_TOKEN_HERE
Content-Type: application/json

{
  "notification_id": 345
}
```

## React Native Implementation Notes

Recommended app behavior:

1. Add a mobile Notifications screen.
2. Fetch `GET /api/mobile/notifications` on screen focus.
3. Sort defensively by `created_at DESC`, although backend already does this.
4. Show unread state using `is_read`.
5. On notification press:
   - Open a detail screen or modal.
   - Call `GET /api/mobile/notifications/detail?id={id}`.
   - Render `title`, `body`, `created_at`.
   - If `link` is present and starts with `http`, show an "Open Link" action.
6. For Expo push response/tap:
   - Read `notification.request.content.data.notification_id`.
   - Navigate to `NotificationDetail` with that ID.
   - If `notification_id` is missing but `link` exists, open link fallback.

## Permissions

Push delivery still depends on:

- User granted notification permissions.
- App submitted the Expo push token during login via `expo_token` or `expo_push_token`.
- Token is still valid.

If Expo returns `DeviceNotRegistered`, the backend clears that user's stale `expo_token`.
