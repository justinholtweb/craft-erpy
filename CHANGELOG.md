# Release Notes for Erpy

## 5.2.0 - 2026-10-09
### Added

- Failure alerts. Erpy now emails the addresses in **Settings → Alerts** — and can post to a Slack
  or Teams incoming webhook, or a signed JSON one — when a connection gets into trouble: documents
  piling up on the Problems screen, the ERP refusing the credentials or an OAuth refresh, or a
  scheduled sync that has stopped producing successful runs. One message when it starts, one when
  it clears, with a quiet period for a connection that flaps. Bodies are redacted and link to the
  Problems screen for that connection. The webhook URL is held to the family SSRF rules: public
  hosts only, the connection pinned to the checked address, no redirects.
- An **ERP health** Dashboard widget: each connection's latest run, unresolved problems and open
  incidents.
- `php craft erpy/alerts/check` and `php craft erpy/alerts/test`. `erpy/sync/due` now runs the
  check after its syncs, and every run checks for dead-letter and stall incidents when it
  finishes, so alerts work without any cron for everything except a stall.
- `Alerts::EVENT_BEFORE_NOTIFY`, to reword or suppress an alert.

### Fixed

- Retrying a problem could book a second sales order, invoice or payment in the ERP. Every retry
  was sent as a forced push, which hands the add-on the ERP's id for the document — and every
  add-on reads that as "skip the duplicate check and post another". A **Retry** button on a
  Problems page left open while the queue's own retry got the order through, a **Retry
  everything** or `erpy/orders/retry` pass that listed a problem just before it cleared, or a
  retry of a forced resend that had failed all posted the document again. A retry is now a second
  attempt rather than a resend: it re-reads the problem and sends nothing if it has been resolved,
  and it reports a document the identity map already has as already there. A failed payment or
  refund is still retried as itself, never by re-exporting its order. A deliberate resend is
  `erpy/orders/push --force`.

## 5.1.1 - 2026-10-05

> {warning} A user with **Add, edit and delete connections** can no longer change a connection's
> connector or any of its endpoint URLs — only an admin can. When a connection is repointed, its
> stored secrets and OAuth tokens are dropped and have to be entered again. Webhooks now need a
> `POST` with the secret in an `X-Erpy-Secret` header; update any ERP-side webhook that sends
> `?secret=`. Run `php craft up` after updating: a migration encrypts the credentials already
> stored.

### Security

- A user who could edit a connection could point it at a host of their own with the secret field
  left blank, press **Test connection**, and receive the stored API key or token. Changing the
  connector or an endpoint is now admin-only, and a repointed connection never carries its old
  secrets or tokens over.
- OAuth tokens and literal secrets are now encrypted at rest with the site's security key. `$ENV`
  references are stored as they are. Rows written before 5.1.1 keep working and are encrypted by
  the migration.
- The webhook endpoint accepted its secret as `?secret=` on a GET, which writes it into access and
  proxy logs. It now accepts `POST` only, with the secret in the `X-Erpy-Secret` header.

### Fixed

- A webhook with a wrong secret answered 500 instead of 403. A curly quote straight after an
  interpolated variable swallowed the variable name; thirteen other log and error messages had the
  same bug.

## 5.1.0 - 2026-09-26

### Fixed

- Products pulled from an ERP were saved without their variants on single-site installs, so every
  later stock pull skipped them with "No variant with the SKU". Commerce's `setVariants()` does not
  mark the variants as changed, and a multi-site install saved them anyway, which hid it.
  ([#1](https://github.com/justinholtweb/craft-erpy/issues/1))
- **Test connection** reported "Credentials are incomplete" for any connector without a sign-in,
  including the Mock ERP, however its settings were filled in. It now reports only a sign-in that
  isn't set up, or a required field left blank.
  ([#2](https://github.com/justinholtweb/craft-erpy/issues/2))

## 5.0.0

Initial release.

- The ERP gateway: connections, canonical documents, the sync engine, the identity map, delta
  watermarks, field mapping, dry runs, dead letters with replay, and a redacted connection log.
- Products, prices (including customer, group and quantity-break contract pricing), inventory,
  customers, orders out, order status, shipments, invoices, payments and credit standing.
- Contract pricing resolved at cart time, so a catalogue of negotiated prices does not have to be
  mirrored into Commerce's pricing rules.
- A built-in Mock ERP connector, so the whole plugin can be tried — and supported — without a
  live ERP tenant.
- Free add-ons for Business Central, NetSuite, Acumatica, SAP Business One, Sage (Intacct, 200,
  X3 and Accounting), Odoo, Exact Online, AFAS, Visma.net, MYOB (Advanced and Exo), Unit4 and
  Priority.
