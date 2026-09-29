# VNV Loyalty Rewards

## Scope and accounting

- Configuration is stored per `id_owner + site_key` in `loyalty_settings`.
- `reward_percent`, `point_value`, and `release_days` are editable in Level 1 under Orders → Rewards Settings.
- Every ledger row snapshots the reward percentage and point value used. Later configuration changes are not retroactive.
- Event rewards are based on successfully recorded payments net of recorded refunds. Tips are not included in the reward basis.
- Store coupons remain independent. The intended calculation order is coupon/ordinary discount first, rewards second, then the remaining provider charge.
- Rewards used to pay an order do not generate new rewards. Only the eligible amount actually paid generates points.

## Lifecycle

1. When an event order becomes fully paid, `PaymentNotificationService` calls the idempotent `earnForEventOrder()` operation.
2. The ledger stores an `EARN` row immediately. Its status is `PENDING` until `event_date + release_days`, or `AVAILABLE` immediately when that date already passed.
3. `src/cron/loyalty-release.php` releases eligible pending rows. The operation is idempotent.
4. Redemption uses a short-lived database reservation. Balance rows and active reservations are locked inside a transaction, preventing double spending across tabs.
5. The reservation service exposes explicit commit/release operations for payment success and failure. Checkout integration must call the appropriate operation before customer redemption is enabled.
6. Manual adjustments require a reason and are appended to the ledger; balances are never silently overwritten.

## Existing payment architecture audited

- Event provider payments: `orders_payments`; legacy advances: `orders_advances`.
- Store payments: `store_payments`.
- Refunds: provider refund records plus `refunded_amount` / refund flags in payment records.
- Saved cards: `client_saved_payment_methods` through `OrderAccessSavedPaymentMethodService`.
- Discounts/coupons: order discount fields and the separate `store_coupons` subsystem.
- Stripe order access uses Stripe Card Element. ZIP was previously stored in a hidden input; it is now a visible required postal field passed to Stripe tokenization.

## Deployment

1. Back up the production database.
2. Run `db/20260927_loyalty_rewards.sql` once; it is safe to rerun.
3. Schedule `php src/cron/loyalty-release.php` at least daily.
4. Review settings in Level 1 before enabling customer redemption.
5. Historical rewards must be previewed and approved before any controlled backfill; this migration performs no automatic backfill.
