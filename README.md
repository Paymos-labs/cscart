# Paymos for CS-Cart

Official Paymos add-on for CS-Cart, Multi-Vendor and Ultimate. It registers a payment processor
called Paymos: the customer places the order, is redirected to the Paymos hosted checkout, and pays
in a stablecoin from their own wallet. The order status moves on a signed webhook, once the transfer
has confirmed on-chain.

## Requirements

- CS-Cart, Multi-Vendor or Ultimate on the 4.x line. `addon.xml` declares add-on scheme `3.0` and
  auto-installs on the Multi-Vendor and Ultimate editions
- PHP 7.4 or newer with the `curl`, `hash`, `json` and `openssl` extensions — the floor the bundled
  Paymos PHP SDK declares
- A storefront on HTTPS; the connect flow refuses anything else
- CS-Cart's `crypt_key` configured, because the stored credentials are keyed from it
- A Paymos account, and the project the storefront should bill through selected in the dashboard

## Install

Take `paymos-cscart-<version>.zip` from
[Releases](https://github.com/Paymos-labs/cscart/releases/latest), or the package from the
**CMS integration** panel in the Paymos dashboard, and upload it under
**Add-ons → Manage add-ons → + → Local**.

Three trees come out of the archive, and all three matter:

- `app/addons/paymos` — the add-on itself, with the Paymos PHP SDK vendored inside it
- `design/backend/…` — the processor settings template
- `var/langs/…` — the language catalogues. CS-Cart resolves an add-on's `.po` files from the **store
  root**, never from inside the add-on, so an upload that loses `var/` renders every admin label as
  its own raw key

Installation creates `paymos_config`, `paymos_invoices` and `paymos_events`, and inserts `Paymos`
into the payment processors table. The archive is the same file for every merchant and holds no API
key, API secret, project id, webhook secret, OAuth token or device code.

## Connect

1. Have the right project open in the Paymos dashboard before you start. The add-on takes that one
   and never asks you to pick.
2. In the admin panel go to **Administration → Payment methods**, add or edit a payment method, and
   set its processor to **Paymos**.
3. On the processor settings, press **Connect Paymos** and approve the storefront URL and project in
   the tab that opens. If the browser blocks that tab, the approval link and the code are printed
   next to the button.
4. Save the payment method.

Sandbox and Live are both provisioned by that single approval. Your one active Payment key is
reused, or created if none exists, and an Invoice webhook is registered at

```text
https://your-store.example/index.php?dispatch=payment_notification.notify&payment=paymos&mode=webhook
```

`mode=webhook` is part of the address rather than decoration: the add-on reads it to tell a Paymos
callback from an ordinary payment-notification dispatch, and CS-Cart's own `$mode` says `notify` on
that route. An existing webhook is reused only when callback URL, category and project all match.

Credentials are written to `paymos_config` as an AES-256-GCM envelope keyed from the store's
`crypt_key`, and are never printed back into the admin page.

## Order statuses

Every state maps to a CS-Cart status code you choose in the processor settings. The defaults are the
built-in codes:

| Processor setting | Default | Applied when |
|---|---|---|
| Order status while awaiting payment | `O` — Open | The invoice is created and the customer is sent to the hosted checkout |
| Order status while the payment is confirming on-chain | `O` — Open | `invoice.confirming` |
| Order status when paid | `P` — Processed | `invoice.paid`, `invoice.paid_over` |
| Order status when the payment is underpaid | `F` — Failed | `invoice.underpaid` |
| Order status when the invoice is cancelled or expired | `D` — Declined | `invoice.expired`, `invoice.cancelled` |

Any code the store defines is accepted, including statuses you created yourself. The transaction id
written onto the order is the on-chain hash of the confirmed transfer, or the Paymos invoice id when
the payload carries no transfers — which is always the case in Sandbox.

Two things never happen. An order already sitting at your configured paid status, or at the built-in
`C` (Complete) that merchants often advance paid orders to, is not rolled back by a late
`confirming`, `underpaid`, `expired` or `cancelled` — the event is skipped and, with debug logging
on, recorded. And an order whose total or currency no longer matches the invoice is not marked paid
at all.

## Multi-Vendor and Ultimate

Both editions get the same add-on, and it behaves the same on each: a store-level payment processor
whose connection belongs to the storefront and whose settings belong to the payment method. A store
can run several Paymos methods side by side with different modes or different status codes.

Every payment settles to the merchant balance of the connected Paymos project. There are no split
payments and no sub-merchant accounts, so dividing an order's proceeds between vendors stays inside
CS-Cart's own vendor bookkeeping.

## Test before going live

1. Set **Mode** to Sandbox on the payment method and place an order in the storefront.
2. Open that invoice in the Paymos dashboard while the dashboard is in Sandbox, and use
   **Pay Full**, **Pay 50%**, **Pay 150%** or **Cancel**. They emit the same events a real payment
   would; nothing touches a blockchain.
3. Check the order status and the payment information block on the order.
4. Set **Mode** to Live. Both credential sets came out of the one approval, so no reconnection and
   no second setup are involved.

## Webhooks

Deliveries are `POST` requests to the dispatch above. The `X-Webhook-Signature` header is the trust
boundary — hex HMAC-SHA256 over `{timestamp}.{body}` — and a bad signature or a stale timestamp
answers `401` without loading an order. A repeated `event_id` answers `200` and does nothing.
Before a terminal event is applied, the invoice is read back from the Merchant API and checked
against the snapshot in `paymos_invoices`: environment, project, external order id and invoice id
all have to agree.

This add-on ships no local reconciliation job, so redelivery is the recovery path. One cycle is 11
attempts over roughly 16 hours, which absorbs a storefront that was briefly down. An event that
outlives the ladder can be replayed by hand from the Paymos dashboard.

## Troubleshooting

**Admin labels show as raw keys** such as `paymos.mode`. The `var/langs` tree did not survive the
upload. Re-upload the release archive whole; language catalogues live in the store root, not in the
add-on directory.

**Orders never leave the awaiting-payment status.** Check the registered callback still carries
`mode=webhook`. Without it the add-on rejects every delivery as an invalid callback mode, the server
keeps retrying, and nothing completes. Reconnecting re-registers the correct address.

**Connect refuses to start.** The storefront URL is what gets approved and what the webhook is
registered against, and it has to be `https://`. Fix the storefront URL, then connect again.

**Everything breaks after a config change.** The credential envelope is keyed from `crypt_key`.
Changing it, or restoring the database into a store with a different one, makes the envelope
unreadable — press **Connect Paymos** again to have a fresh one issued.

## Links

- Documentation: [paymos.io/docs/cms-cscart](https://paymos.io/docs/cms-cscart)
- Source and releases: [Paymos-labs/cscart](https://github.com/Paymos-labs/cscart)
- Support: [support@paymos.io](mailto:support@paymos.io)
