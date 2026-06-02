# Mobile Apps Ecosystem

> Avomeal web note: this is ecosystem-level mobile guidance. In this repo, prioritize Avomeal customer, store, subscription and notification behavior while preserving VNV Events and mobile compatibility.

## Purpose

This document explains the role of the mobile apps connected to the Ophyra/VNV/Avomeal ecosystem.

The mobile apps are brand-specific mobile experiences. They generally do not contain the core business logic. The web/backend systems remain the source of truth for users, orders, panels, chat, payments, notifications and operational data.

## Repositories

| App | Repository | Observed package name | Brand |
| --- | --- | --- | --- |
| Avomeal app | `morer62/vnv-gourmet-app` | `avomeal-app` | Avomeal |
| VNV Events app | `morer62/vnv-mobile-app` | `vnv-events-app` | VNV Events |

Both apps are Expo/React Native projects and use dependencies such as:

* Expo,
* React Native,
* React Navigation,
* WebView,
* AsyncStorage,
* Axios,
* Expo Notifications,
* Google Sign-In where configured.

## General App Responsibilities

The apps generally provide:

* login,
* signup,
* initial dashboard,
* mobile navigation,
* WebView into the web system,
* stored token/session bridge,
* push notification registration,
* branded mobile shell for the project.

The backend/web system provides the core business behavior.

## Token And WebView Pattern

The apps store a backend token locally, commonly through `AsyncStorage` with a key such as:

```text
Token
```

That token is used to keep the user connected between the native app and the backend/web experience.

Before changing backend login, API token validation, WebView routes or JSON responses, inspect the relevant mobile app repository.

## Push Notifications

The apps include Expo Notifications support.

Push notification reliability depends on:

* the app obtaining a valid Expo push token,
* the backend accepting and saving that token,
* the backend associating the token with the correct user,
* the backend sending notifications to the correct recipient,
* API URL and environment matching the deployed backend,
* token validation not rejecting a valid logged-in user.

Do not rename or remove existing Expo token endpoints without compatibility work.

## Brand Separation

The Avomeal app and VNV Events app may share patterns, but they are not the same product.

Avomeal mobile should support the food/delivery/customer-order experience.

VNV Events mobile should support the event/services/team/client experience.

Shared technical structure does not remove brand-specific navigation, copy, colors, assets or user flows.

## Rules Before Editing Mobile-Related Backend Code

Before changing anything in `src/views/api/` or mobile login/session handling:

* identify which app consumes the endpoint,
* confirm existing JSON fields,
* add fields instead of renaming/removing,
* preserve route names,
* preserve HTTP behavior where possible,
* verify WebView routes still load,
* verify Expo token save/update still works,
* verify Level 1, Level 4 and Level 5 flows where applicable.

Detailed backend API behavior is documented in:

```text
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```
