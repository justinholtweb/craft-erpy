# Release Notes for Erpy

## 5.1.0 - 2026-09-26

### Added

- A **Connect** button on the connection screen for ERPs that sign in through OAuth (Exact Online,
  Sage 200, Sage Accounting). It shows whether the connection is approved, when access and consent
  expire, and offers **Reconnect** and **Disconnect**. There was previously no way to start the
  consent step from the control panel.
- Documentation for every add-on, at [justinholt.com/plugins/craft-erpy/docs](https://justinholt.com/plugins/craft-erpy/docs).
  Each add-on's Plugin Store listing links to its own page there.
- `tests/tools/dump-connectors.php`, which prints every installed connector's capabilities and
  connection fields as JSON.

### Changed

- A new icon.
- Only entities a connector declares as delta-capable are given a watermark. Every other entity is
  read in full on every run, and `erpy/sync/status` says so instead of showing a watermark.
- A price line for everybody is written to the variant only when it applies from a quantity of one
  and has no end date. Quantity breaks and dated promotions stay in Erpy's price table and are
  resolved at cart time, so a promotion no longer outlives its end date on the variant.
- At cart time, a customer's own price list now wins over a quantity break that applies to
  everybody, and a price-break table no longer lists other customers' prices.
- Saving a connection no longer writes its OAuth tokens back, so a routine save from a page opened
  before a sync cannot restore a refresh token the ERP has since rotated.

### Fixed

- Products pulled from an ERP were saved without their variants on single-site installs, so every
  later stock pull skipped them. ([#1](https://github.com/justinholtweb/craft-erpy/issues/1))
- **Test connection** reported "Credentials are incomplete" for any connector without a sign-in,
  including the Mock ERP, however its settings were filled in. It now reports a missing sign-in or
  a blank required field, and nothing else. ([#2](https://github.com/justinholtweb/craft-erpy/issues/2))
- **Test connection** on an OAuth connection awaiting consent now says to use the Connect button,
  instead of asking for a required field that does not exist.
- A watermark was sent for entities declared without delta support, so a connector that filters
  whenever it is given a date could silently skip records.
- A quantity-break or dated price line for everybody could overwrite a variant's regular price.
- A failed log write could fail the sync it was logging. Log writes now fail open.
- An OAuth error from the ERP now shows the ERP's own description, and the connection only reports
  success once a refresh token has actually been stored.

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
