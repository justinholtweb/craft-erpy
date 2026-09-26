---
title: Erpy for Acumatica
slug: acumatica
order: 100
summary: Acumatica Cloud ERP through the contract-based REST endpoint, with one shared session per run so the integration does not spend your licence on logins.
---

Erpy for Acumatica connects Craft Commerce to Acumatica Cloud ERP through its contract-based REST
API. The add-on is free; it needs Erpy, which is the paid part and owns the sync engine, the
identity map, mapping, the queue and the log.

Running MYOB Acumatica (formerly MYOB Advanced)? That has its own connector in
[Erpy for MYOB](/plugins/craft-erpy/docs/myob). It speaks the same API but is versioned
separately.

## Install

```sh
composer require justinholtweb/craft-erpy-acumatica
php craft plugin/install erpy-acumatica
```

Then **Erpy → Connections → New connection** and pick **Acumatica**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, on `LastModifiedDateTime` | 200 |
| Products | ERP → Commerce | Yes, on `LastModifiedDateTime` | 200 |
| Inventory | ERP → Commerce | No, full read each run (see below) | 500 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 200 |
| Shipments | ERP → Commerce | Yes | 200 |
| Invoices | ERP → Commerce | Yes | 200 |
| Credit | ERP → Commerce | No | 200 |

The connector declares multi-company support and that Acumatica offers a sandbox, so you can point
a connection at a sandbox tenant before going live. It does not sync contract prices: the base
price comes from the stock item's `DefaultPrice`.

Inventory is read from the inventory summary inquiry, which carries no modified date, so every
inventory run reads the whole warehouse. The stock item's own `LastModifiedDateTime` is no
substitute: a shipment or a receipt changes the warehouse quantity without touching the item, so
filtering on it would miss exactly the changes a stock sync exists for. Erpy's content hashing
makes the unchanged rows cost a comparison each rather than an element save.

## Connecting

| Field | What to put there |
|---|---|
| **Instance URL** | Your Acumatica address, e.g. `https://example.acumatica.com`, with no trailing path. Erpy adds the endpoint itself. |
| **Endpoint version** | The version of the **Default** endpoint, from System → Integration → Web Service Endpoints. Defaults to `20.200.001`. |
| **Username** / **Password** | A dedicated integration user (see below). |
| **Company / tenant** | Leave blank on a single-tenant instance. |
| **Branch** | Leave blank to use the user's default branch. |
| **Warehouse** | The warehouse stock is read from and orders are raised against. |
| **Sales order type** | The order type new orders are created as. Defaults to `SO`. |
| **Create orders on hold** | On by default. An order on hold can be reviewed before it is committed to a warehouse. |

**Use a dedicated integration user.** Acumatica counts concurrent sessions against your licence,
and sharing a person's login means their next password change breaks the storefront.

Press **Test connection** before switching anything on. It reads one stock item. A 401 means the
username, password or tenant is wrong, or the user is locked out, which Acumatica reports exactly
as it does a wrong password. A 403 means the user signed in but the endpoint is not exposed to their
role, or the endpoint version is wrong.

## Things to know about Acumatica

**It authenticates with a session, not a token.** The connector signs in once, caches the session
cookie for its life, shares it across a whole run and signs out explicitly afterwards. An
integration that logs in per request will use up a small licence. Acumatica also sets two cookies
and needs both back; anything less and the next request is answered as an anonymous one.

**Every value is wrapped.** In a contract-based payload a field is not a scalar but an object:
`{"value": "WIDGET-01"}`. The connector unwraps them for you. When you reach into the raw payload
on the mapping screen, you have to unwrap it yourself (see below).

**There is no paging metadata.** Acumatica does not say how many records there are or whether
another page exists. A short page is the only sign that the end has been reached, and that is
what the connector uses.

**Only two item statuses are sellable.** Acumatica has four ways of saying "do not sell this":
Inactive, No Sales, No Request and Marked for Deletion. Only **Active** and **No Purchases** come
through as enabled; everything else arrives blocked. A **Non-Stock Item** is synced without
inventory tracking.

**Stock is what you can sell, not what is on the shelf.** The inventory summary inquiry answers
"how many can I sell?", with on-hand, available and allocated quantities per warehouse. The stock
item's own figure is company-wide and ignores allocations. Set **Warehouse** to read one warehouse
only.

**Customers on hold.** A customer whose status is Hold, Credit Hold or One-Time arrives on hold.
Credit reads the limit and balance from the customer record, and Hold or Credit Hold count as a
stop.

**Your order number is the customer order.** The Commerce order number goes out in the sales
order's `CustomerOrder` field. Before creating an order the connector asks whether one with that
reference already exists, so a retried job does not become a second sales order. Order status
comes back matched on the same field.

## Correcting a field

If a field the connector reads is not the one your tenant uses, correct it on the mapping screen
with a rule whose target is the canonical field. Say your endpoint has been extended with a
`WebSKU` field that holds the code you sell under. On the **Products** mapping:

```
raw.WebSKU.value   →   sku        transform: trim
```

Note the `.value` on the end. The raw payload is Acumatica's untouched response, so every field in
it is still wrapped, and `raw.WebSKU` on its own is an object rather than a string.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
