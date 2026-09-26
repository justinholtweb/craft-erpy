---
title: Erpy for MYOB
slug: myob
order: 104
summary: Two connectors in one add-on, MYOB Acumatica (formerly MYOB Advanced) through the contract-based REST API and MYOB Exo through the Exo API gateway.
---

Erpy for MYOB connects Craft Commerce to MYOB's two mid-market products: **MYOB Acumatica**
(formerly MYOB Advanced) and **MYOB Exo Business**. The add-on is free; it needs Erpy, which is the
paid part and owns the sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-myob
php craft plugin/install erpy-myob
```

Then **Erpy → Connections → New connection** and pick the connector for the product you run. The
add-on has no settings screen of its own; everything lives on the connection.

## Connectors in this package

| Connector | Pick it if you run |
|---|---|
| **MYOB Acumatica (Advanced)** | MYOB Acumatica, or MYOB Advanced before the rename. A cloud product with an instance URL like `https://example.myobadvanced.com`. |
| **MYOB Exo** | MYOB Exo Business, on-premise or hosted, reached through the Exo API. |

They share a vendor and nothing else, which is why they live in one add-on rather than two plugins
nobody could tell apart. MYOB Acumatica is also deliberately a separate implementation from
[Erpy for Acumatica](/plugins/craft-erpy/docs/acumatica): a MYOB merchant should not have to
install a plugin for a product they do not run.

## What it syncs

| Entity | MYOB Acumatica | MYOB Exo |
|---|---|---|
| Customers | ERP → Commerce, delta | ERP → Commerce, delta |
| Products | ERP → Commerce, delta | ERP → Commerce, delta |
| Inventory | ERP → Commerce, full read | ERP → Commerce, delta |
| Orders | Commerce → ERP | Commerce → ERP |
| Order status | ERP → Commerce, delta | ERP → Commerce, delta |
| Shipments | ERP → Commerce, delta | |
| Invoices | ERP → Commerce, delta | |
| Credit | ERP → Commerce, full read | ERP → Commerce |

Neither connector syncs contract prices. Both declare multi-company support. Page sizes are 200
(500 for inventory) on MYOB Acumatica and 100 on Exo.

## MYOB Acumatica

### Connecting

| Field | What to put there |
|---|---|
| **Instance URL** | e.g. `https://example.myobadvanced.com`. |
| **Endpoint version** | The **Default** endpoint's version, from Integration → Web Service Endpoints. Defaults to `20.200.001`. It differs between MYOB releases. |
| **Username** / **Password** | A dedicated integration user. |
| **Company / tenant** | Leave blank on a single-tenant instance. |
| **Branch** | Leave blank for the user's default. |
| **Warehouse** | Stock is read from, and orders raised against, this warehouse. |
| **Sales order type** | Defaults to `SO`. |
| **Create orders on hold** | On by default, so an order can be reviewed before it reaches the warehouse. |

On **Test connection**, a 401 means the username, password or tenant, and a 404 means the endpoint
version.

### Things to know

**It is Acumatica underneath.** MYOB licenses Acumatica's platform, so this is the contract-based
REST API, with the same habits:

- **A session, not a token.** The connector signs in once per run, shares the session cookie and
  signs out afterwards. MYOB counts concurrent sessions against your licence, so give the
  integration a user of its own.
- **Every value is wrapped** as `{"value": …}`. The connector unwraps them; a mapping rule reading
  `raw.` has to add `.value` itself.
- **No paging metadata.** A short page is the only signal that the end has been reached.

**Stock is available stock** from the inventory summary inquiry, which accounts for allocations.
It has no modified date, and the stock item's own does not move when stock does, so each inventory
run reads everything.

**Your order number is `CustomerOrder`.** The Commerce order number goes out in the sales order's
`CustomerOrder` field. The connector checks for it before creating an order, so a retry does not
make a second one. Order status comes back matched on it.

## MYOB Exo

### Connecting

Exo needs **three separate credentials** and gives the same 401 when any one of them is wrong or
missing. That is why each is its own labelled field.

| Field | What to put there |
|---|---|
| **API URL** | The hosted gateway (default `https://exo.api.myob.com`), or your own Exo API host for an on-premise deployment. |
| **Developer key** | Identifies your integration. Sent as `x-myobapi-key`. |
| **Exo username** / **Exo password** | The Exo user the integration acts as. |
| **MYOB account email** / **password** | Only on a hosted install, where the gateway also wants Basic credentials for the MYOB account. |
| **Branch** | The branch id new orders are raised against. |
| **Stock location** | The numeric location id stock is read from, and orders raised against. Blank means the item's total. |

On **Test connection**, a 401 means check all three credentials. On a hosted install it often means
the account email and password are missing. A 403 means the developer key is not authorised for
this Exo database.

### Things to know

**The Exo token is not the Basic header.** The Exo user's credentials go in their own
`x-myobapi-exotoken` header; the MYOB account's go in `Authorization`. Sending one in place of the
other is the usual reason a correct set of credentials still gets a 401. The connector builds both
from the fields above.

**Customers are debtors.** Exo is a debtor ledger rather than a CRM, and the customer code
everywhere else in Erpy is Exo's account number, `accno`. It is numeric. Orders are raised against
it, and a **Guest customer code** on the order mapping must be a numeric debtor account too. An
order whose customer code is not a number is refused, naming the code, rather than posted against
debtor 0, and it is not retried: fix the code and replay it from **Problems**.

**A discontinued line is not deleted.** Exo marks it with `status`. Anything but `A` arrives blocked.
Items with stock type `S` come through without inventory tracking.

**The stop flag has two levels.** `stopcredit` is 1 to warn and 2 to block. Both come through as
on hold, because either means nobody should sell on account without looking first.

**Response shapes vary with the gateway.** Exo answers with a bare array on some resources and an
`items` envelope on others, depending on the gateway version in front of it. The connector accepts
both.

**Your order number is `customerordernumber`.** The first 20 characters also go in `reference`.
Order status is matched on `customerordernumber`. Before posting, the connector asks Exo for a
sales order with that number, so a retried job finds the order it already created instead of
raising a second one.

## Correcting a field

On Exo, a product's base price is read from `sellprice1`. If your web price is a different price
band, correct it on the **Products** mapping:

```
raw.sellprice2   →   price        transform: number
```

On MYOB Acumatica the same kind of rule needs the wrapper unwrapped, e.g.
`raw.DefaultPrice.value → price`.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
