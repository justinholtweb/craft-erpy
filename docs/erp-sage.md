---
title: Erpy for Sage
slug: sage
order: 108
summary: Four connectors in one add-on, for Sage Intacct, Sage 200, Sage X3 and Sage Business Cloud Accounting, four unrelated products that happen to share a name.
---

Erpy for Sage connects Craft Commerce to four Sage products: **Sage Intacct**, **Sage 200**
(Standard and Professional), **Sage X3** and **Sage Business Cloud Accounting**. The add-on is free;
it needs Erpy, which is the paid part and owns the sync engine, the identity map, mapping, the queue
and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-sage
php craft plugin/install erpy-sage
```

Then **Erpy → Connections → New connection**. All four connectors appear in the picker; choose the
one for the product you actually run. The add-on has no settings screen of its own; everything
lives on the connection.

## Connectors in this package

Sage sells four products under one name, and they share almost nothing: not an API, not an
authentication scheme, not a data model.

| Connector | Pick it if you run | API |
|---|---|---|
| **Sage Intacct** | Sage Intacct, the cloud financials product | The XML gateway |
| **Sage 200** | Sage 200 Standard or Professional (UK and Ireland) | The Sage 200 API |
| **Sage X3** | Sage X3 | The X3 REST web services |
| **Sage Business Cloud Accounting** | Sage Accounting, the small-business bookkeeping product | The Sage Accounting API |

## What it syncs

| Entity | Intacct | Sage 200 | X3 | Accounting |
|---|---|---|---|---|
| Customers | Pull, delta | Pull, delta | Pull, delta | Pull, delta |
| Products | Pull, delta | Pull, delta | Pull, delta | Pull, delta |
| Inventory | Pull, full read | Pull, delta | Pull, full read | |
| Orders | Push | Push | Push | Push, as a sales invoice |
| Order status | Pull, delta | Pull, delta | Pull, delta | |
| Invoices | Pull, delta | Pull, delta | | Pull, delta |
| Credit | Pull | Pull | Pull | |

"Pull" is ERP → Commerce; "Push" is Commerce → ERP. None of the four syncs contract prices or
shipments. Intacct, Sage 200 and X3 declare multi-company support.

## Sage Intacct

**Connecting.** Intacct authenticates with **two sets of credentials at once**, and sending only one
produces an error that mentions neither.

| Field | What to put there |
|---|---|
| **Sender ID** / **Sender password** | Issued to the integration by Sage, not by the customer. The same sender id is used for every Intacct company you connect to. |
| **Company ID** | The customer's Intacct company. |
| **User ID** / **User password** | A **Web Services** user in that company. A normal user will not do. |
| **Entity** | For multi-entity companies. Leave blank to work at the top level. |
| **Items** / **Stock** / **Customers** | The Intacct objects to read. Defaults: `ITEM`, `ITEMWAREHOUSEINFO`, `CUSTOMER`. They suit a company running Order Entry and Inventory Control. |
| **Sales order document type** | The Order Entry transaction definition new orders are created as. Default `Sales Order`. It must match exactly, capitalisation included. |
| **Warehouse** | The warehouse stock is read from. |

**The sender id must be authorised in the company**, under Company → Security → Web Services
Authorizations. Without that, correct credentials still fail. **Test connection** says so, and
reports how many customers the user can see when it passes.

**Things to know.**

- **There is no REST API.** Everything goes through one XML endpoint, and authentication lives
  inside the XML envelope rather than in a header.
- **Intacct answers HTTP 200 for failures.** Authentication failures and rejected documents alike
  come back as 200; the status element in the body is the only thing that says which.
- **Paging is by `resultId`.** The first query returns a handle, and every page after it is a
  `readMore` against that handle. The handle expires, so the connector feeds it straight back
  rather than re-issuing the query.
- **Query dates are `MM/DD/YYYY HH:MM:SS` in the company's own timezone.** Not ISO 8601 and not
  UTC. Either of those silently returns nothing. The connector converts the watermark for you.
- **Only items of type Inventory are stock-tracked.** Everything else is a service or a
  non-inventory line.
- **Your order number is `CUSTOMERDOCNO`.** The Commerce order number goes out as the document's
  customer document number and reference. A retry asks for it before creating.

## Sage 200

**Connecting.** Sage 200 needs three separate things on every request, and gives an unhelpful
answer when any one is missing: an OAuth bearer token, a subscription key, and the site.

| Field | What to put there |
|---|---|
| **Edition** | Sage 200 Professional or Sage 200 Standard. The two answer on different API paths (`sage200extra` and `sage200`). There is no region setting: Sage serves UK and Irish companies from the same endpoint and one sign-in audience. |
| **Callback URL** | Read-only. Copy it into your app's callback on the Sage developer portal, exactly. |
| **Client ID** / **Client secret** | From your app on the Sage developer portal. |
| **Subscription key** | The `Ocp-Apim-Subscription-Key` from your Sage developer account. It identifies the application, not the customer. |
| **Site ID** | Sent as the `X-Site` header. |
| **Warehouse** | The warehouse stock is read from. |

Save the connection, then use the **Connect** button to approve access once on Sage's sign-in
screen. Until then **Test connection** reports "Not connected yet". A 401 afterwards means the token
has expired or the subscription key is wrong; they fail identically, so check the key and then use
**Reconnect**. A 404 usually means the wrong edition is selected.

**Things to know.**

- **A missing `X-Site` returns nothing.** It is the usual reason a correctly authenticated request
  comes back empty rather than failing. Some releases refuse a wrong site id with a 403.
- **Stock is free stock.** Sage 200 publishes it directly, and it already accounts for allocations
  against other orders.
- **Status codes are numeric.** A product is 0 active, 1 suspended, 2 discontinued. A customer
  account is 0 active, 1 on hold, 2 closed.
- **Your order number is `customer_document_no`.** A retry checks it before creating a second
  sales order.

## Sage X3

**Connecting.**

| Field | What to put there |
|---|---|
| **X3 server URL** | Including the port the Syracuse web server listens on, e.g. `https://x3.example.com:8124`. |
| **Folder** | The X3 folder, e.g. `SEED`. |
| **Username** / **Password** | An X3 user with a web services role. Sent as HTTP Basic. |
| **Products** / **Stock** / **Customers** / **Sales orders** | The representations to read. Defaults `ITMMASTER`, `ITMMVT`, `BPCUSTOMER`, `SORDER`. Change any your implementation replaced with a custom representation. |
| **Sales site** | The X3 site orders are raised against, and stock is read from. |

On **Test connection**, a 401 means the credentials or a missing web services role. A 404 means the
folder or the representation: a folder the user cannot reach is a 404 in X3, not a 403.

**Things to know.**

- **X3 is strongly folder-scoped.** The folder is in the URL, and the same credentials against two
  folders are two entirely separate datasets. A test folder and a live folder are two connections.
- **Representations, not resources.** The names are the table codes an X3 consultant works in every
  day. The `.$query` or `.$details` suffix is part of the request, and the wrong one returns a
  schema rather than data.
- **Delta is by date.** X3 is filtered on `UPDDAT`, which holds a date, so a delta run re-reads
  everything changed on the watermark's day. Erpy's content hashing discards the rows that did not
  change.
- **Paging counts from one.** An index of 0 returns the same first page forever, which Erpy would
  see as a repeated cursor and stop on. The connector handles it.
- **Status codes.** `ITMSTA` is 1 active, 2 not usable, 3 not renewable. `FLGSTOFCY` says whether an
  item is stock-managed. Delivery is tracked separately from invoicing: 1 not delivered, 2 partly,
  3 fully.
- **Your order number is `CUSORDREF`, as its first 20 characters.** X3 defines `CUSORDREF` as 20
  characters and SORDER has no longer reference field, while Commerce's order numbers are 32. The
  connector writes the first 20 characters, looks for exactly that value before creating an order
  (so a retry never makes a second one), and on order status turns it back into the full Commerce
  number through Erpy's identity map, which knows the order by its X3 order number `SOHNUM`.
  Commerce order numbers are random, so 20 characters of one are still unique. In X3, search for an
  order by the start of its Commerce number.

## Sage Business Cloud Accounting

**Be clear about what this one is.** Sage Accounting is a bookkeeping product, not an ERP. It has
no sales orders, no warehouses and no stock ledger. So this connector deliberately does not
advertise inventory or order status. A connector that pretended otherwise would succeed and do
nothing on a merchant's busiest day.

What it does have is products, contacts and sales invoices. That covers what merchants actually
ask for: keep the catalogue and customer list in step, and **post each completed Commerce order
into the ledger as a sales invoice**.

**Connecting.**

| Field | What to put there |
|---|---|
| **Callback URL** | Read-only. Copy it into your app's callback on the Sage developer portal, exactly. |
| **Client ID** / **Client secret** | From your app on the Sage developer portal. |
| **Country** | The country of the Sage business. It picks which country's Sage sign-in the **Connect** button opens: `gb`, `ie`, `us`, `ca`, `fr`, `es` or `de`. |
| **Sales ledger account** | The ledger account id invoice lines are posted to. Leave blank to use each product's own. |
| **Default tax rate** | Used when a line has no tax rate of its own. Sage refuses an invoice line without one. |

Save the connection, then use the **Connect** button to approve access once on Sage's sign-in
screen. A 401 later means the token expired and could not be refreshed: Sage refresh tokens are
valid for a limited period, so use **Reconnect**. The connection screen shows when the consent expires, when Sage says. A 403 means the Sage user who approved access
cannot read the business, or its subscription does not include API access; connect again as a user
of the business you mean.

**Things to know.**

- **Delta uses Sage's own parameter.** `updated_or_created_since` is the only delta filter Sage
  Accounting honours. A `$filter` on `updated_at` is silently ignored.
- **Nothing is stock-tracked.** Sage Accounting keeps no stock ledger.
- **Customers are contacts of type Customer.** The customer code is the contact's reference, or its
  Sage id when it has none. Invoices use the same rule (the invoice's copy of the contact
  reference, or the contact's id), so an invoice lands on the same account as its customer. The SKU
  is the product's item code, or its Sage id when it has none.
- **Your order number is the invoice reference.** A retry checks it before posting a second invoice
  into somebody's ledger, and invoices pulled back are matched to their order on it.

## Correcting a field

A mapping rule targeting a canonical field overrides what any of the four connectors read. On
Sage 200 the SKU is the product's `code`. To sell under the barcode instead, on the **Products**
mapping:

```
raw.barcode   →   sku        transform: trim
```

On X3 the equivalent is `raw.EANCOD → sku`. On Intacct, note that `raw.` holds only the fields the
connector asked for in its query, not the whole record.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
