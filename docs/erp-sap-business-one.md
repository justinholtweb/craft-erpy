---
title: Erpy for SAP Business One
slug: sap-business-one
order: 109
summary: SAP Business One through the Service Layer, with one shared session so the licence is not spent on logins, and the tYES/tNO booleans and day-granular deltas handled for you.
---

Erpy for SAP Business One connects Craft Commerce to SAP Business One, on HANA or SQL Server,
through the Service Layer. The add-on is free; it needs Erpy, which is the paid part and owns the
sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-sapb1
php craft plugin/install erpy-sapb1
```

Then **Erpy → Connections → New connection** and pick **SAP Business One**. The add-on has no
settings screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, by day, on `UpdateDate` | 100 |
| Products | ERP → Commerce | Yes, by day | 100 |
| Prices | ERP → Commerce | No | 100 |
| Inventory | ERP → Commerce | Yes, by day | 100 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes, by day | 100 |
| Shipments | ERP → Commerce | Yes, by day | 100 |
| Invoices | ERP → Commerce | Yes, by day | 100 |
| Credit | ERP → Commerce | No | 100 |

The connector declares multi-company support: one connection per company database.

**Prices** are B1's special prices, the customer-specific price table, which is exactly the shape
Erpy's contract pricing wants. The Commerce base price comes from the item's price on the base
price list, read with the item itself. B1's other price lists are not synced: a business partner
assigned a different price list sees base prices on the storefront unless they also have special
prices, and the order reaches B1 at the price the storefront charged.

## Connecting

| Field | What to put there |
|---|---|
| **Service Layer URL** | Including the `/b1s/v1` path, e.g. `https://sap.example.com:50000/b1s/v1`. Port 50000 is the Service Layer's default. |
| **Company database** | e.g. `SBODemoUS`. Case-sensitive. |
| **B1 user** / **Password** | A dedicated integration user. |
| **Warehouse code** | Stock is read from this warehouse and order lines are raised against it. Leave blank for the company-wide figure. |
| **Base price list number** | The price list whose prices become the Commerce base price. Default `1`. Other lists are not synced. |
| **Document series** | The numbering series new sales orders use. Leave blank for the default. |
| **This Service Layer uses a self-signed certificate** | Turns off certificate verification for this connection only (see below). |

**Test connection** reads one item and shows the company name and version. A 401 usually means the
company database name, not the password: it is case-sensitive, and a wrong one fails exactly like a
wrong password. A request that gets no answer at all means the host or port is not reachable from
your web server, or the certificate is not trusted.

**Self-signed certificates.** On-premise installs often run the Service Layer with a self-signed
certificate, which fails the connection test with nothing answering. Turn on **This Service Layer
uses a self-signed certificate** and the connector skips certificate verification for this
connection's requests — the login, every read and every order — and nowhere else on the site. It
also means the connection can no longer tell your Service Layer from something impersonating it, so
a certificate your server trusts remains the better fix.

## Things to know about SAP Business One

**Sessions are licensed.** Every `/Login` takes a licence slot until it times out. The connector
signs in once, caches the session, shares it across a whole run and closes it explicitly. An
integration that logs in per request will exhaust a small licence in an afternoon. Give Erpy a user
of its own.

**Load-balanced installs need both cookies.** B1 sets a session cookie and `ROUTEID`. On a
load-balanced Service Layer the session only exists on one node, and `ROUTEID` is what gets the
next request back to it. The connector sends both.

**Booleans are strings.** B1 answers `tYES` and `tNO`, and `(bool)'tNO'` is `true` in PHP. That is
how integrations end up publishing every frozen item. The connector reads them properly: an item
that is not **Valid**, or is **Frozen**, arrives blocked. If you map a B1 flag yourself from `raw.`,
Erpy's `bool` transform does not recognise `tYES` as yes. Use `replace:tYES:yes | bool`.

**Delta syncing is by day.** B1 keeps `UpdateDate` and `UpdateTime` in separate columns, and only
the date is filterable. So a delta sync asks for everything changed on or after the watermark's
**date**, and Erpy's content hashing throws away the rows that did not change. Re-reading a day's
items is cheap; missing an afternoon's price change is not.

**The Service Layer ignores `$top` unless told otherwise.** Without an explicit page-size
preference it returns 20 rows whatever you ask for. The connector sets it.

**Stock is per warehouse when you name one.** The company-wide figures on the item header ignore
which site the stock is actually at. With a **Warehouse code** set, stock comes from the item's own
warehouse collection instead.

**Credit counts open deliveries.** B1 tracks the value of open deliveries separately from the
account balance, and it counts against the limit just as an unpaid invoice does. So it does here.

**Your order number is `NumAtCard`.** B1 has no idempotency key, so the Commerce order number goes
out as the customer reference number. It is indexed, and it is what your staff will search for.
Before creating an order the connector asks whether one with that reference already exists. Order
status comes back matched on it. B1 closes a sales order when it has been fully delivered, so
"closed" is the signal that the goods have gone.

**Every order needs a business partner.** Link the Craft user to a B1 customer, or set a **Guest
customer code** on the order mapping: the `CardCode` guest orders are booked against.

## Correcting a field

The connector reads the SKU from `ItemCode`. If your web shop sells under the barcode, correct it
on the **Products** mapping:

```
raw.BarCode   →   sku        transform: trim | nullif:
```

For your own user-defined fields, B1 prefixes them `U_`, so a web SKU kept in a UDF is
`raw.U_WebSKU → sku`.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
