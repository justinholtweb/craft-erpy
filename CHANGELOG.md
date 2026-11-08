# Release Notes for Erpy

## 5.4.0 - 2026-11-08
### Added

- **Add-on connectors can serve orders at the export URL.** A connector that implements
  `ServesOrders` (`renderServed()`, `servedContentType()`, `servedExtension()`) and answers true
  from `deliversOnRequest(Entity::ORDER)` is fetched from `erpy/export/<handle>/orders` in its own
  format. It gets the identity map, the Problems screen and range re-serving like the built-in file
  connector, which now uses the same path. `OrderExport::fields()` gives an add-on the same export
  settings. A served order never reaches the connector's `pushOrder()`. (#3)
- **Allowed IP addresses** for the export URL. A request from a listed address or range can fetch
  without the token, for an ERP that polls from a fixed address and can't send one. The token may
  be left empty when an address is the only way in. `X-Forwarded-For` is ignored while Craft's
  `trustedHosts` is left at its default of every host, so the list can't be bypassed with a header. (#3)
- **Date format in the URL**, for an ERP that writes `from` / `to` its own way (`d.m.Y`,
  `Y-m-d-H-i`). (#3)
- The canonical address now carries `firstName`, `lastName` and `taxId` (from Commerce's
  `organizationTaxId`), on orders and on pushed customers. (#3)

### Fixed

- `from=2026-11-03-00-00` was read as midnight UTC rather than in the site's timezone, because the
  trailing `-00` parsed as an offset. (#3)
- A wildcard file name (`stock_*.csv`) in a local folder could pick a symlink pointing outside the
  folder. Wildcards now pick only regular files, and the match is resolved inside the folder again.
- An XML file in UTF-16, or one whose DOCTYPE came after the first 64 KB, got past the check that
  refuses DOCTYPEs and entity declarations. The parser now refuses them as it reads.
- `from`, `to`, `all` and `limit` captured by a site route (`'export-orders/<from>' =>
  'erpy/export/orders'`) were ignored. Craft doesn't merge route parameters into the query string. (#3)

## 5.3.0 - 2026-11-03
### Added

- **File / URL exchange**, a connector built into Erpy for an ERP that exchanges files instead of
  offering an API. It reads products, prices, stock, customers, order statuses and shipments from
  CSV, XML or JSON. The files can be in a folder on the server, at a URL, on an SFTP server, or
  uploaded on the connection screen. It reads UTF-8, Windows-1250, ISO-8859-2 and the other common
  single-byte encodings, plus UTF-16. Orders are either written as one file per order, atomically,
  to a folder or SFTP, or served at `erpy/export/<handle>/orders` for the ERP to fetch. A served
  order is recorded as delivered, so it isn't served twice, and `?from=…&to=…` serves a range
  again. The identity map, delta watermarks, dead letters, the run log and the mapping overlay
  all apply. A malformed row becomes one entry on the Problems screen rather than a failed run.
  See [File / URL exchange](https://justinholt.com/plugins/craft-erpy/docs/files). (#3, #7)
- SFTP needs `phpseclib/phpseclib` ^3.0, which Erpy now requires. Erpy won't log in to an SFTP
  server whose host key fingerprint hasn't been saved.
- A `decimal:,` mapping transform reads `1.234,56` and `12,5` as numbers.
- For connector authors: `Connector::entityFields()` for settings that belong to one entity,
  `Page::reject()` for a record a connector can't read, `Connector::deliversOnRequest()` for an ERP
  that collects documents itself, `Field::upload()`, and `Run::TRIGGER_EXPORT`.

- An inventory mapping can now set Commerce's **on-hand** stock instead of its available stock
  (**The ERP's quantity sets → On hand**). Use it when the ERP owns stock and reports its physical
  count: Commerce books the difference to available and keeps the units committed to unshipped
  orders, so those units cannot be sold twice. **Available** is still the default. (#8)
- `Catalog::EVENT_BEFORE_APPLY_STOCK`. It receives each page of a stock pull before any of it is
  written, so a site with a bulk inventory writer can take the page. Erpy writes whatever the
  handler leaves, and records every document in the run log, the identity map and the Problems
  screen whoever wrote it. (#8)
- `Transport::addSecretValue()`, plus the `'retry'` request option and `Idempotency-Key` support
  described below.

### Fixed

- The transport could post the same order to an ERP up to four times. A `POST` or `PATCH` that
  timed out, or got a 502/503/504 from a proxy, was sent again automatically, even though the
  ERP may already have booked it. The identity map and the 5.2.0 retry guard couldn't see those
  copies, because they all happened inside one request. `POST` and `PATCH` are now only repeated
  when the ERP provably didn't act on them: the connection never opened, or the ERP answered
  425/429. A connector can opt a request back in with `'retry' => true` or an `Idempotency-Key`
  header, and `'retry' => false` turns retrying off for any method. A document that fails this
  way goes to the queue's retry, which checks the ERP for it before sending again. (#5)
- Tokens an ERP issued at login were written to the request log. That covered `access_token` and
  `refresh_token` from an OAuth exchange, a `SessionId`, an `apiKey` and an XML `<sessionid>`.
  Redaction only knew the secrets the merchant had typed in. Logged bodies now have
  credential-named fields masked, in JSON, form and XML, on success and failure. Any value an
  auth strategy puts on a request is also redacted wherever the ERP echoes it back. (#6)

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
