# Erpy — plan and decisions

## What it is

An ERP gateway for Craft Commerce: one paid host plugin that owns the sync engine, and a free
add-on per ERP that owns nothing but the translation. Modelled on Imager-X and its transformers.

**Erpy $149 / $129 renewal. Every connector free.**

## Decisions taken 2026-08-28

| Question | Decision |
|---|---|
| "Unit" in the brief | Unit4 ERP (ERPx and Business World) |
| Sage | One `craft-erpy-sage` package registering four connectors — Intacct, 200, X3, Accounting |
| MYOB | Same treatment: one package, two connectors (Acumatica, Exo) |
| Scope | Gateway core **and** all thirteen providers in one pass |
| Sync surface | Full B2B set — products, prices, inventory, customers, orders out, order status, shipments, invoices, payments, credit |
| Editions | Single edition. No Lite. |
| Connection storage | Database, not project config |

## Stated assumption

The connectors were written against published API contracts and exercised against recorded
transports. There were no live ERP tenants. The conformance suite proves the request each
connector builds and the contract it honours; it cannot prove a vendor's field is spelled the way
a connector expects. Two things make that acceptable rather than reckless:

1. **The mapping overlay.** A rule targeting a canonical field overrides what the connector read.
   A wrong field name is a merchant's afternoon, not a release cycle.
2. **The conformance suite refuses over-claiming.** A connector cannot advertise a flow it has not
   implemented. It caught eight cases of exactly that during the build.

Verification against real tenants is the outstanding work, and it is per-connector rather than
architectural.

## Architecture

- **Canonical documents** (`models/canonical/Erp*`) are the vocabulary. Deliberately dumb: no
  validation, no database, no element behaviour. The moment a document knows about Commerce or
  about one ERP, sixteen connectors start disagreeing.
- **`Connector`** declares capabilities and translates one page. It does not queue, retry, page,
  dedupe, map, log or write to Commerce.
- **`Transport`** is the only path to the network: retries, throttling, `Retry-After`, redaction,
  and a swappable double that is what makes credential-less connectors testable at all.
- **Auth strategies** cover the six schemes these thirteen vendors use between them: OAuth 2.0
  client credentials and authorization code, OAuth 1.0a (NetSuite), API key, Basic, and
  session-cookie login (SAP B1, Acumatica).
- **`Sync::run()`** and **`Push::deliver()`** are the two invariants. Everything funnels through
  them.
- **`erpy_links`** is the identity map and the single most important table. A unique index on both
  sides of every pairing is the duplicate-order guarantee.

## Built and verified

- Gateway: 8 tables, 14 services, 6 web controllers, 3 console controllers, 3 queue jobs,
  10 CP templates, a Twig variable, a built-in Mock ERP.
- 16 connectors across 12 free packages.
- **141 engine checks** against the Mock ERP: paging, delta watermarks, identity map, mapping,
  contract-pricing precedence, dry runs, dead letters, replay, partial fulfilment, redaction,
  cascade deletion.
- **306 conformance checks** across all 17 connectors.
- CP screens smoke-tested with a real session — connections list, editor, all six mapping screens,
  activity, problems, log, settings — plus the test and sync AJAX actions.

## Bugs the suites found during the build

Worth keeping, because they are the ones that would otherwise have shipped:

- Eight connectors advertised a customer push none of them implemented.
- The mock advertised a payment pull it had not written.
- Business Central treated an unresolvable company as retryable, so the queue would have resent
  the order until it gave up.
- Odoo and Intacct classified a refused document as retryable for the same reason.
- Error bodies could echo a credential back to the merchant's health screen.
- Four Craft models omitted columns their tables return, throwing on hydration.
- A `class-string` passed to Twig — `Impossible to invoke a method on a string variable`.
- A variant created by a sync did not track inventory, so the first stock sync skipped it.
- `Order::getCustomer()` is never null in Commerce 5, so every order looked like a registered one.

## Still to do

- GitHub repos and tags for thirteen packages; Packagist; Plugin Store submissions.
- Verification against real tenants, per connector.
- The file / URL exchange connector — 5.1.0, below.

## 5.1.0 — the file / URL exchange connector (GitHub #3)

Decided 2026-09-26: ship 5.0.0 as it is, build this for 5.1.0, and **require**
`phpseclib/phpseclib` ^3 for SFTP rather than making it optional.

Requested by a team moving several south-east European stores onto Erpy. Their ERPs (Pantheon,
Synesis, Minimax, 4D, Luceed) are file-first, and the pattern is the same everywhere: the ERP exports
stock and prices as CSV or XML, and it imports orders by polling an XML URL the shop exposes,
filtered by date, with a token, often in Windows-1250. The value is not the transport but that the
identity map, cursors, dead letters and mapping overlay apply to files exactly as they do to NetSuite.
It also gives every merchant without a "real" ERP a first step onto Erpy.

It ships **inside Erpy**, like the Mock connector, not as an add-on: it is the generic piece, and
the requester intends to write local-API connectors as free add-ons on top of the gateway.

**Inbound (ERP → Commerce):** products, prices, inventory, customers from CSV or XML.
- Sources: a URL (with optional basic/bearer auth), an SFTP path, a local path, or a CP upload.
- CSV: delimiter, enclosure, header row on/off; XML: a record path (a small XPath subset, e.g.
  `/Export/Items/Item`). Encodings: UTF-8, Windows-1250, ISO-8859-2 (plus anything `mb_convert_encoding`
  knows), converted before parsing.
- Columns/elements map to canonical fields on the existing mapping screen. Every file field arrives
  in `raw.`, so the overlay is the whole mapping story: the connector only needs a default column
  guess per canonical field and lets canonical rules correct it.
- Delta: by the file's modification time (skip an unchanged file — cheapest and most common), or by
  a date column compared with the watermark. Paging is by row offset within the file, streamed, so a
  100k-line stock file does not have to fit in memory.

**Outbound (Commerce → ERP), two modes:**
- *Write*: each completed order (or a batch per run) as XML or CSV to SFTP or a local path, from the
  canonical `ErpOrder` as-is or through a Twig template. Written atomically (temp name, then rename),
  because ERPs poll directories and will read half a file.
- *Serve*: `erpy/export/<connection>/orders?from=…&to=…&token=…` returns canonical orders as XML or
  CSV in a chosen encoding. Constant-time token compare, token rotatable in the CP, never in project
  config (connections are in the database, which is the point). Serving an order records it in the
  identity map the way a push does, so a poll does not export it twice unless `?all=1` or a date
  range explicitly asks; the order push queue treats a served connection as "pull-delivered" and
  does not also try to push.

**Coming back:** order status and shipments as a file (order number, status, tracking) through the
same inbound machinery — cheap once inbound exists, and it closes the loop.

**What the engine needs:** a transport abstraction for non-HTTP sources (the connector should not
fake HTTP), a public front-end route for *serve* alongside the webhook one, and conformance checks
that drive the file connector against fixture files in each encoding and both formats, including a
malformed row (dead letter, not a failed run) and a mid-file encoding error.

