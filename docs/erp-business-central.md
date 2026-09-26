---
title: Erpy for Business Central
slug: business-central
order: 102
summary: Microsoft Dynamics 365 Business Central through the standard API v2.0 and an Entra ID app registration, with optional published pages for the prices and shipments Microsoft never exposed.
---

Erpy for Business Central connects Craft Commerce to Microsoft Dynamics 365 Business Central
through the standard API v2.0, authenticated with an Entra ID application. The add-on is free; it
needs Erpy, which is the paid part and owns the sync engine, the identity map, mapping, the queue
and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-businesscentral
php craft plugin/install erpy-businesscentral
```

Then **Erpy → Connections → New connection** and pick **Dynamics 365 Business Central**. The
add-on has no settings screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, on `lastModifiedDateTime` | 100 |
| Products | ERP → Commerce | Yes, on `lastModifiedDateTime` | 100 |
| Prices | ERP → Commerce | No. Needs a published page (see below) | 100 |
| Inventory | ERP → Commerce | Yes | 100 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 100 |
| Shipments | ERP → Commerce | Yes, on `lastModifiedDateTime`. Needs a published page | 100 |
| Invoices | ERP → Commerce | Yes | 100 |
| Credit | ERP → Commerce | No, full read each run | 100 |

The connector declares multi-company support and that Business Central offers a sandbox: point a
connection at a sandbox environment first.

## What the standard API does not have

Business Central's API v2.0 covers items, customers, orders, invoices and financials. It does
**not** expose sales prices or posted shipment tracking numbers; those live on pages Microsoft has
never surfaced. Both are optional settings here:

- **Sales prices endpoint.** Publish a page as an API in your own extension and name it here,
  relative to the company. It must expose `itemNo` and `unitPrice`, and optionally `customerNo`,
  `customerPriceGroup`, `minimumQuantity`, `startingDate` and `endingDate`.
- **Posted shipments endpoint.** The same, exposing `orderNo`, `no` and `lastModifiedDateTime`,
  and optionally `externalDocumentNo`, `packageTrackingNo`, `shippingAgentCode` and
  `postingDate`. Map `lastModifiedDateTime` to the record's `SystemModifiedAt`; delta syncs filter
  on it. Not on `postingDate`: tracking numbers are usually added after posting with **Update
  Document**, which leaves the posting date alone, so a posting-date filter would never see them.

Leave either blank and Erpy does not sync that entity. The run log says why rather than reporting
an empty success.

Credit needs no extra page. The limit comes from the standard customers API's `creditLimit`, and
the balance and overdue amount from the customer's `customerFinancialDetail`, which the connector
expands in the same request. A limit of 0 is Business Central's way of saying "no limit", and it
arrives as no limit rather than as zero credit. Credit is read in full each run, because a
payment moves a balance without touching the customer's modified date.

## Connecting

**Entra ID application.** Register an application in Entra ID and grant it the Business Central
API permission. Then grant that application access **inside Business Central too**, under
Microsoft Entra Applications. Registering it in Entra ID alone is not enough.

| Field | What to put there |
|---|---|
| **Tenant ID** | The GUID of your Microsoft 365 tenant. |
| **Application (client) ID** | From the app registration. |
| **Client secret** | From the app registration. Secrets expire; note when. |
| **Environment name** | As it appears in the Business Central admin centre: `Production`, `Sandbox`, or whatever yours is called. It is case-sensitive. |
| **Company** | The company to read and write, exactly as it appears in the company list. |
| **Sales prices endpoint** | Optional; see above. |
| **Posted shipments endpoint** | Optional; see above. |

Erpy polls Business Central on its schedule. Business Central's change notifications are API
subscriptions with a validation handshake of their own, which Erpy's webhook endpoint does not
speak, so the connector does not offer webhooks.

**Test connection** interprets the common failures for you:

- **401.** The client secret has expired, or the application was never granted access inside
  Business Central.
- **403.** The application exists but has no permission set in Business Central. Give it
  `D365 BUS FULL ACCESS`, or a narrower set that covers the entities you sync.
- **404.** The environment name is wrong. It is the name from the admin centre, not the company
  name.

## Things to know about Business Central

**Client credentials, no consent screen.** The connector uses the OAuth 2.0 client credentials
grant, which is the one Microsoft steers integrations towards. Nobody sits in front of a nightly
stock sync, so there is nobody to click through a consent screen.

**Throttling is per environment.** Business Central answers 429 with a `Retry-After` it means, and
the transport honours it.

**Stock is company-wide.** The item record carries its own inventory figure, so stock costs no
extra request. Only items of type **Inventory** are stock-tracked; Service and Non-Inventory items
come through without it.

**`blocked` is not a yes/no.** On a customer it is blank when not blocked, and Ship, Invoice or All
for different degrees of stop. Any of the three brings the customer through on hold.

**Your order number is the external document number.** Business Central has no idempotency key,
so the Commerce order number goes out as `externalDocumentNumber` (up to 35 characters). It is
indexed and it is what your staff will search for. Before creating an order the connector asks
whether one with that number already exists. Order status and shipments come back matched on the
same field.

**Lines are separate requests.** The order header is created first and each line is added after
it. A line Business Central refuses leaves the header behind, so the failure names the line, and
somebody has to finish that order in Business Central.

**Shipping is an item line.** A Business Central sales order has no shipping field of its own. On
the order mapping, turn on **Send shipping as an order line** and set **Shipping SKU** to the item
that represents it.

**A wrong company is refused, not retried.** If the company name cannot be resolved, the order is
marked as refused with a message naming the setting, so the queue does not resend it until it
gives up.

## Correcting a field

A published page is yours, so its field names are whatever your developer chose. If your sales
prices page calls the price `salesPrice` rather than `unitPrice`, correct it on the **Prices**
mapping instead of republishing the page:

```
raw.salesPrice   →   unitPrice        transform: number
```

The same works for the item: the connector reads `itemNo`, then `itemNumber`. If yours is `itemCode`,
map `raw.itemCode → sku`.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
