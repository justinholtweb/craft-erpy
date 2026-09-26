---
title: Erpy for Unit4
slug: unit4
order: 110
summary: Unit4 ERPx and Business World, with every resource path configurable and both authentication schemes offered, because no two Unit4 estates expose the same API.
---

Erpy for Unit4 connects Craft Commerce to Unit4 ERP: **ERPx** in the cloud, or the older
**Business World** on-premise. The add-on is free; it needs Erpy, which is the paid part and owns
the sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-unit4
php craft plugin/install erpy-unit4
```

Then **Erpy → Connections → New connection** and pick **Unit4 ERP**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes | 100 |
| Products | ERP → Commerce | Yes | 100 |
| Inventory | ERP → Commerce | No | 100 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 100 |
| Credit | ERP → Commerce | No | 100 |

Contract prices, shipments and invoices are not synced. The connector declares multi-company
support: one connection per Unit4 company.

## The most customer-shaped ERP here

Two Unit4 estates rarely expose the same resources. The API surface depends on which modules are
licensed, how your partner configured them, and whether you are on ERPx or Business World. No set
of endpoint names would be right for everybody.

So this connector does not guess. Every resource path is a setting, the defaults are the ERPx
conventions, both authentication schemes are offered, and every field it reads can be corrected on
the mapping screen. That is not a shortcut; it is the only design that survives contact with a real
Unit4 implementation. **Ask your implementation partner which resources exist on your estate, and which query parameter
filters them by change date,** before you start.

## Connecting

| Field | What to put there |
|---|---|
| **API base URL** | Everything up to, but not including, the resource name, e.g. `https://example.unit4cloud.com/api`. |
| **Company** | Sent as the `companyId` parameter on every request. |
| **Scheme** | **OAuth 2.0 client credentials** for ERPx cloud, or **HTTP Basic** for Business World on-premise. |
| **Token endpoint** | OAuth only: your Unit4 Identity Services token URL. |
| **Scope** | OAuth only, if your Identity Services requires one. |
| **Client ID / username** | The OAuth client id, or the Business World username. |
| **Client secret / password** | The OAuth client secret, or the Business World password. |
| **Changed-since parameter** | The query parameter your estate filters on for records changed since a moment. Default `lastChangedFrom`. Leave blank if there is none (see below). |

**Resources.** Change any your deployment names differently.

| Setting | Default |
|---|---|
| Products | `v1/products` |
| Stock | `v1/inventory/balances` |
| Customers | `v1/customers` (also read for credit) |
| Sales orders | `v1/salesorders` (also read for order status, and posted to) |
| Resource to test with | `v1/products` |

Leave one blank and that entity reads nothing; the run log says why.

**Test connection** reads from the test resource, so point that at anything the user can read.
With OAuth, a 401 can mean the token endpoint or the scope, not only the client id and secret:
Identity Services rejects an unknown scope the same way it rejects a bad secret. With Basic, it
means the credentials, or that the Business World user is not allowed to use the web services. A
404 means the base URL or the resource name.

## Things to know about Unit4

**Delta syncing uses the Changed-since parameter.** Customers, products and order status ask for
records changed since the watermark by sending it, as a UTC timestamp (`2026-09-26T08:00:00Z`), in
the query parameter named by **Changed-since parameter**: `lastChangedFrom` unless you change it.
If your estate has no such parameter, leave the setting blank. Every sync then reads everything,
which is slower but cannot miss a record, and the run log notes it. A parameter your resources do
not recognise is usually ignored, which also means a full read; one they misread could return too
little, so confirm the name with your partner rather than guessing.

**Collections are wrapped differently.** Unit4 wraps a list in `data`, `items`, `value` or `results`,
or not at all, depending on the resource and the release. The connector tries each. Paging is by
page number, and a short page means the end.

**Field names vary too.** The connector reads the first of several likely names: the SKU from
`productId`, `id` or `code`; the customer code from `customerId` or `id`; stock from `quantity` or
`balance`; the order reference from `customerReference` or `externalReference`. When none of them
is right for your estate, use a mapping rule (below).

**Your order number is `customerReference`.** The Commerce order number goes out in the order's
`customerReference` (up to 50 characters), and order status is matched back on it. Before posting,
the connector asks the sales orders resource for an order with that reference; if one exists, the
order is recorded as already delivered rather than posted again, so a retried job cannot leave two
sales orders behind. The lookup sends `customerReference` as a filter and also checks each returned
order's reference itself, so an estate that ignores the filter cannot make an undelivered order
look delivered. If the lookup fails, the order is posted as normal.

**A product's status decides whether it is enabled.** The connector reads whichever status fields
the products resource has: Unit4's one-letter `status` (`N` active, `P` parked, `C` closed, or the
same words spelled out), `active` / `isActive`, and `blocked` / `closed`. Any one that says no
wins: parked disables a product, closed or blocked disables and blocks it. A product with none of
these fields is treated as enabled. If your estate marks it somewhere else, map that field onto
`enabled`.

**Custom fields travel with the order.** Anything you add to an order's `customFields` in a
`Push::EVENT_BEFORE_PUSH` handler is sent as a field on the Unit4 order. That is the way to fill
a mandatory field your estate added, such as a project or cost-centre code.

**Every order needs a customer id.** Link the Craft user to a Unit4 customer, or set a **Guest
customer code** on the order mapping.

**Status flags are read from the status text.** Cancelled, shipped, invoiced and closed are set by
looking for those words in the order's status. The status itself always comes through, but those
flags will not be set if your statuses are named differently or not in English.

## Correcting a field

On Unit4 this is not an edge case, it is part of setting up. Say your products resource calls the
item code `articleCode`. On the **Products** mapping:

```
raw.articleCode   →   sku        transform: trim
```

If the list price is `salesPrice` rather than `price`, `raw.salesPrice → price` with `number` fixes
that the same way. The rule corrects what the connector read, before it reaches Commerce, so there
is no fork and no release to wait for.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
