# VNV Events Invoices Module

## Boundary

Invoices are independent administrative documents. They reference one existing event order, but they do not create charges or replace the order/payment workflow.

Sources of truth:

- order pricing and Sales Tax: `OrderCalculatorService`;
- assigned service lines: `orders_services_assigned` plus historical unit price;
- processor/manual collections: main-order rows in `orders_payments` and `orders_advances`;
- refunds: `refunded_amount` on those transaction rows.

The invoice module never stores card number, CVV, or expiration. It only exposes processor, transaction reference, brand, and last four digits when already available.

## Persistence

- `invoices`: one row per owner/order, immutable `INV-{order_id}` number, invoice-only billing fields, notes and line-description overrides;
- `invoice_versions`: immutable JSON snapshots for each distinct generated PDF;
- `invoice_audit_logs`: creation, edit, PDF, email, and void history.

The unique `(id_owner, id_order)` key and deterministic invoice number make webhook/retry creation idempotent.

## Lifecycle

1. A fully paid order is reconciled automatically when payment notification runs or when the invoice center opens.
2. An administrator may create an invoice for any order before it is paid.
3. Status is derived from net real collections: Draft, Unpaid, Partially Paid, Paid, Refunded, or Voided.
4. Generating a PDF finalizes a Draft as Unpaid and creates an immutable version only when the snapshot changed.
5. Invoice-specific edits do not update the underlying order.

## Routes

- `/panel/planner-hub/management/invoices`
- `/panel/planner-hub/management/invoices/view?id={invoice_id}`
- `/panel/planner-hub/management/invoices/pdf?id={invoice_id}`

## Deployment

After the Git deployment, apply:

```bash
php tools/run-sql.php 20261028_invoices_module.sql
```

Run local non-destructive QA with:

```bash
php tools/invoices-module-qa.php
```

The QA creates a temporary invoice, verifies idempotency and PDF output, confirms that the order hash is unchanged, and removes the temporary invoice afterward.
