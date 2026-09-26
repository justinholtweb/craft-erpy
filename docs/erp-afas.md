---
title: Erpy for AFAS
slug: afas
order: 101
summary: AFAS Profit through GetConnectors and UpdateConnectors, with every connector and field name configurable because your implementation partner chose them.
---

Erpy for AFAS connects Craft Commerce to AFAS Profit through its REST GetConnectors and
UpdateConnectors. The add-on is free; it needs Erpy, which is the paid part and owns the sync
engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-afas
php craft plugin/install erpy-afas
```

Then **Erpy → Connections → New connection** and pick **AFAS Profit**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, if a modified field is configured | 100 |
| Products | ERP → Commerce | Yes, if a modified field is configured | 100 |
| Prices | ERP → Commerce | No | 100 |
| Inventory | ERP → Commerce | No | 200 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes, if a modified field is configured | 100 |
| Credit | ERP → Commerce | No | 100 |

Shipments and invoices are not synced. Entities without a delta sync read everything each time,
and Erpy's content hashing keeps that cheap: an unchanged row costs a comparison, not an element
save.

## AFAS has no fixed schema

This is the one thing to understand before setting up. AFAS does not have an `Items` endpoint. A
GetConnector is built by your implementation partner, with whatever fields and names they chose,
and two AFAS environments rarely look alike.

So the connector does not pretend to know your field names. It ships the names AFAS's own standard
connectors use, makes every one of them a setting, and relies on Erpy's mapping screen for
anything the settings do not cover. Before you start, ask your partner three things: what the
GetConnectors are called, which field holds the modification date, and which field on the sales
orders GetConnector shows the customer reference.

## Connecting

| Field | What to put there |
|---|---|
| **Environment ID** | The number in your AFAS URL, e.g. `12345`. Erpy builds the REST host from it. |
| **Environment** | Production, Test or Acceptance. |
| **App Connector token** | The token value only (see below). |

**GetConnectors.** One per entity, defaulting to AFAS's standard names. Change any your
implementation calls something else.

| Setting | Default |
|---|---|
| Items | `Profit_Artikelen` |
| Stock | `Profit_Voorraad` |
| Customers | `Profit_Debiteuren` (also used for credit) |
| Prices | `Profit_Verkoopprijzen` |
| Sales orders | `Profit_Verkooporders` (read for order status) |

Leave one blank and that entity simply reads nothing; the run log says so.

**Field names.**

| Setting | Default | Used for |
|---|---|---|
| Modified field | `Bijgewerkt` | Delta syncs. Leave blank if your connectors have none. |
| Item code field | `Itemcode` | The SKU, on items, stock and prices. |
| Customer number field | `Debiteurnummer` | The customer code, on customers, prices and credit. |
| Order reference field | `Referentie` | The field on the sales orders GetConnector holding the order's `RfCs`: order status is matched on it, and it is checked before an order is posted. |

**Sales orders.** The **UpdateConnector** new orders are posted to (default `FbSales`), and an
optional **Warehouse**.

## Things to know about AFAS

**The token is wrapped twice.** AFAS gives you an App Connector token as a small XML document and
expects it back base64-encoded in the `Authorization` header, as `AfasToken …`. Paste only the part
between `<data>` and `</data>`; Erpy wraps it and encodes it. The bare token and the un-encoded
XML both get the same unhelpful 401.

**Delta syncing depends on your connectors.** AFAS filters server-side on any field a GetConnector
exposes, so "modified since" works, provided the connector exposes a modified field and you name it.
If it does not, leave **Modified field** blank and every sync reads everything.

**Filters are three parallel lists.** AFAS takes field ids, values and operator codes as separate,
comma-separated parameters. The connector keeps them in step. If you add your own filters to a
connection, remember that one out of step filters on the wrong field and quietly returns the
wrong rows.

**Prices are read from whichever column exists.** The connector looks for `Prijs`, `Price` and
`Verkoopprijs`, in that order. If your prices connector calls the column something else, correct
it with a mapping rule.

**The order envelope is unforgiving.** An UpdateConnector takes one element with its fields and
nested objects for the lines. A misplaced key returns a 400 about an unexpected element rather
than about the key. The Commerce order number goes out in `RfCs`, the customer's reference.

**An order is looked for before it is posted.** The connector first asks the sales orders
GetConnector for an order whose **Order reference field** equals the Commerce order number (an
AFAS "equal to" filter). If one exists, the order is recorded as already delivered rather than
posted again, so a retried job cannot leave two orders behind. The lookup is skipped, and the order
posted as normal, when there is no sales orders GetConnector, when the connector does not expose
the reference field (AFAS answers that with an error), or when the order number contains a comma,
which the filter lists cannot carry. AFAS returns the new order number in a result whose exact
shape depends on your UpdateConnector; when it cannot be found, the Commerce order number is
recorded instead.

**Every order needs a debtor.** A guest order is refused unless you set a **Guest customer code**
on the order mapping: the debtor number guest orders are booked against.

## Correcting a field

AFAS is the ERP the mapping overlay was built for. A rule whose target is a canonical field
overwrites what the connector read before anything reaches Commerce. Say your partner's prices
connector exposes the price as `PrijsExclBtw`. On the **Prices** mapping:

```
raw.PrijsExclBtw   →   unitPrice        transform: number
```

For the SKU and the customer number, use the **Item code field** and **Customer number field**
settings first. They are read on every entity at once, which is what you want for an identity.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
