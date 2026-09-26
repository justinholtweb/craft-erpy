---
title: Erpy for Priority
slug: priority
order: 107
summary: Priority ERP through its OData REST interface, addressing the same forms your consultant works in, with every form name configurable because no two Priority installations are alike.
---

Erpy for Priority connects Craft Commerce to Priority Software's ERP through its OData REST
interface. The add-on is free; it needs Erpy, which is the paid part and owns the sync engine, the
identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-priority
php craft plugin/install erpy-priority
```

Then **Erpy → Connections → New connection** and pick **Priority**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | No | 100 |
| Products | ERP → Commerce | No | 100 |
| Inventory | ERP → Commerce | No | 200 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes, on `UDATE` | 100 |
| Invoices | ERP → Commerce | Yes, on `UDATE` | 100 |
| Credit | ERP → Commerce | No | 100 |

Contract prices and shipments are not synced. The connector declares multi-company support: one
connection per Priority company.

**Products, stock and customers are read in full every time.** Priority exposes no dependable
"last modified" column across those forms. Erpy's content hashing keeps that cheap: an unchanged
row costs one comparison, not an element save. Still, set the intervals with the size of your item
master in mind.

## Forms, not resources

Priority does not have an abstracted API. It exposes its **forms**, the same screens a Priority
consultant works in: `LOGPART` for parts, `CUSTOMERS`, `ORDERS`. Sub-forms are reached with
`$expand`, exactly as they are in the application. A Priority installation is customised by
definition: forms are added, fields renamed, and two implementations rarely look alike. So every
form name is a setting, and every field the connector reads can be corrected on the mapping screen.

## Connecting

| Field | What to put there |
|---|---|
| **Priority server URL** | The host only, e.g. `https://priority.example.com`. Erpy adds the OData path. |
| **Company** | The Priority company (database) name, as it appears in the OData URL. |
| **Configuration file** | Almost always `tabula.ini`. Change it only if your installation says otherwise. |
| **Username** / **Password** | A Priority user permitted to use the REST API. |
| **Warehouse** | Stock is read from this warehouse. Leave blank to read every warehouse. |
| **Price list** | The price list new orders are raised against. |

**Forms.** Change any your implementation renamed.

| Setting | Default |
|---|---|
| Items form | `LOGPART` |
| Stock balance form | `WARHSBAL` |
| Customers form | `CUSTOMERS` (also read for credit) |
| Sales orders form | `ORDERS` (also read for order status) |
| Order lines sub-form | `ORDERITEMS_SUBFORM` |
| Invoices form | `AINVOICES` |

The connector authenticates with HTTP Basic. **REST API access is granted per user in Priority**,
so a user who can sign in to the application may still be refused.

**Test connection** reads one part from the items form. A 401 means the credentials, or that the
user is not permitted to use the REST API. A 404 means the company name or the form name. A form
the user cannot see is a 404 in Priority, not a 403.

## Things to know about Priority

**Archived parts stay archived.** Priority archives a part rather than deleting it. A part with
`PARTARC` set arrives blocked and never reaches the storefront. Parts of type `S` come through
without inventory tracking.

**`OBLIGO` is the credit limit.** The word means "liability" and catches everybody reading the
schema for the first time. Credit reads `OBLIGO`, `BALANCE` and the `BLOCKED` flag from the
customers form.

**Your order number is `REFERENCE`.** The Commerce order number goes out in the order's customer
reference field (up to 32 characters). Before creating an order the connector asks whether one
with that reference already exists, and order status comes back matched on it.

**Header and lines go in one request.** Priority takes the order and its lines sub-form together.
It is the one place it is kinder than the ERPs that post lines separately and leave an orphaned
header behind when a line is refused.

**Every order needs a customer.** Link the Craft user to a Priority customer, or set a **Guest
customer code** on the order mapping: the `CUSTNAME` guest orders are booked against.

**Status flags are read from the status text.** The connector marks an order cancelled, closed or
shipped by looking for words like "cancel", "closed", "finish", "deliver" and "shipped" in its
status. If your statuses are named differently, or not in English, the status itself still comes
through, but those flags will not be set.

## Correcting a field

Because every Priority is customised, this is the page you will come back to. Say your
implementation keeps the web-facing part number in a custom column, `WEBPARTNAME`, rather than in
`PARTNAME`. On the **Products** mapping:

```
raw.WEBPARTNAME   →   sku        transform: trim
```

And if the base price lives somewhere other than `PRICE`, a rule targeting `price` corrects it the
same way. The rule changes what the connector read before it reaches Commerce, so there is no fork
and no release to wait for.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
