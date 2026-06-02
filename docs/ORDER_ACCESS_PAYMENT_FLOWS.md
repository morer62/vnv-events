# Order Access Payment Flows

> Avomeal web note: this document is active for inherited public payment behavior. Avomeal Store checkout/payment flows should stay separate from event-service order-access links unless explicitly integrated.

This document explains the public payment links under `src/views/public/order-access`.

These routes are customer-facing payment and signature screens generated from orders created by platform users. They must remain public-token based and should not depend on panel login.

## Main Order Flow

Base route:

```text
/order-access?token=...
```

Main file:

```text
src/views/public/order-access/index.php
src/views/public/order-access/index.twig
```

Purpose:

* Shows the order summary.
* Lets the client review and sign the contract.
* Shows payment buttons after signature when payment is available.
* Supports full payment, split payments, advance payments and tips.

Main payment routes:

```text
src/views/public/order-access/first/
src/views/public/order-access/second/
src/views/public/order-access/full/
src/views/public/order-access/advance/
src/views/public/order-access/tip/
src/views/public/order-access/paypal-create-order/
src/views/public/order-access/success/
```

## Suborder Flow

Base route:

```text
/order-access/suborder?token=...
```

Main files:

```text
src/views/public/order-access/suborder/index.php
src/views/public/order-access/suborder/index.twig
```

Purpose:

* Shows a suborder tied to a parent order.
* Lets the client sign the suborder contract.
* Lets the client pay first installment, second installment, full balance or an advance.

Suborder payment routes:

```text
src/views/public/order-access/suborder/first/
src/views/public/order-access/suborder/second/
src/views/public/order-access/suborder/full/
src/views/public/order-access/suborder/advance/
src/views/public/order-access/suborder/paypal-create-order/
src/views/public/order-access/suborder/success/
```

## Provider Model

The modern first/full/second payment routes use:

```text
App\Repositories\PaymentProvidersRepository
App\Services\Payment\PaymentProviderFactory
```

Supported provider types:

```text
stripe
square
paypal
```

Expected fields from the active provider:

* `provider_type`
* `public_key`
* `api_key`
* `location_id`
* `environment`
* `currency`
* `is_verified`

Provider-specific frontend values:

* Stripe uses `stripe_publishable_key`.
* Square uses `square_application_id` and `square_location_id`.
* PayPal uses `paypal_client_id`.

## Stripe Notes

Main order first/full/second routes already pass `stripe_publishable_key` and load Stripe.js when the active provider is Stripe.

Suborder first/full/second routes must do the same:

* Pass `stripe_publishable_key` from `$activeProvider->public_key`.
* Load `https://js.stripe.com/v3/`.
* Create a Stripe card token and submit it as `customer_token`.
* Let `PaymentProviderFactory` process the charge on POST.

The suborder advance route is a legacy-style Stripe-only route. It uses:

```text
App\Repositories\StripeAccountsRepository
App\Services\StripeServiceV2
App\Services\PaymentCardExtractor
```

It should not use Square repositories or Square services.

## Square Notes

Square works through the active payment provider in first/full/second flows.

Frontend requires:

```text
square_application_id
square_location_id
square_environment
```

The Twig loads Square Web Payments SDK and tokenizes the card into `customer_token`.

## PayPal Notes

PayPal uses a separate create-order endpoint because it needs to create an order with PayPal before approval:

```text
/order-access/paypal-create-order
/order-access/suborder/paypal-create-order
```

The frontend sends:

* `token`
* `payment_type`

Valid suborder payment types:

```text
first
second
full
```

PayPal appears wired for both main order and suborder flows, but should be tested in sandbox before production use.

## Contract Signature Consent

The contract signature checkbox appears in:

```text
src/views/public/order-access/index.twig
src/views/public/order-access/suborder/index.twig
```

Mobile layout must keep the checkbox visible and centered. The `.consent-checkbox-card` class intentionally avoids Bootstrap's default negative checkbox offset by using flex layout and resetting checkbox margins.

Do not remove this layout fix without mobile testing.

## Safety Rules

Do not change these public URLs without checking mobile/WebView and existing customer links:

```text
/order-access
/order-access/first
/order-access/second
/order-access/full
/order-access/advance
/order-access/suborder
/order-access/suborder/first
/order-access/suborder/second
/order-access/suborder/full
/order-access/suborder/advance
```

Do not change token structure without reviewing every generator that creates customer payment links.

Do not mix order payments, suborder payments, tips and subscription payments. These flows are for customer order payments only.

Public order-access tokens are separate from mobile `api_token` authentication. Mobile/API auth and Expo Push Token behavior are documented in:

```text
docs/MOBILE_API_NOTIFICATIONS_FLOW.md
```

## Ophyra Basic Profile And Paid Modules

The basic Business Profile is available after registration without requiring an Ophyra Base payment. This lets a business publish its basic presence and finish its profile before activating advanced tools.

Paid operational modules remain protected:

* `store_delivery_tracking` is required for Level 2 Store / Commerce administration.
* `services` is a paid module for the public services catalog and service requests. It uses the same monthly amount as Store.
* Level 1 can access every Store administration screen for platform testing and VNV Events Operations.
* Level 5 keeps customer-facing purchase history and public checkout access.

Store checkout is separate from the order-access links documented above. Do not merge Store payments into order payments or Ophyra subscription billing.

## Store Fulfillment Boundary

Store checkout remains separate from public service-order payment links. After a successful Store payment, fulfillment can be assigned to Team Members through `store_order_workflow` and `store_order_tasks`. Store delivery tracking uses `store_delivery_location_logs` only during active work. It must not alter signature, installment, tip or contract behavior under `/order-access`.

## Admin control and reporting

Level 1 manual membership control lives under `/panel/module-manager`. Admin may suspend an account, update Base renewal, gift or expire add-ons, record an external payment, or charge an existing main Stripe customer token. Admin forms must never collect card number or CVV.

Business Operations revenue must be calculated from real collections (`orders_payments`, `orders_advances`, and paid `store_payments`). Do not treat created orders as collected sales. Ophyra SaaS subscription reports remain separate from customer-order reports.

## Company And Client Scope

Service-order payment links belong to the selling business through `orders.id_owner`. Client portal views may show orders from more than one business when the same Level 5 client has relationships through `clients_users`. Do not require a client to switch companies just to see their own historical orders; instead show the business name on each order row.

See:

```text
docs/USER_COMPANY_ACCESS_MODEL.md
```
