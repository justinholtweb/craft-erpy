# Release Notes for Erpy

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
