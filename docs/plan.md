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
- **127 engine checks** against the Mock ERP: paging, delta watermarks, identity map, mapping,
  contract-pricing precedence, dry runs, dead letters, replay, partial fulfilment, redaction,
  cascade deletion.
- **272 conformance checks** across all 17 connectors.
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
- Marketing site (see `[[project_craft_plugin_websites]]`), registry entry in
  `[[project_craft_plugin_registry]]`.
- Verification against real tenants, per connector.
- A generic REST/CSV connector for the ERPs with no add-on, deferred deliberately.
