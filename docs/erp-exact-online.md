---
title: Erpy for Exact Online
slug: exact-online
order: 103
summary: Exact Online through its REST API, with the OAuth consent step, the rotating refresh token, the daily rate limit and division scoping handled for you.
---

Erpy for Exact Online connects Craft Commerce to Exact Online through its REST API. It works with
every country installation Exact runs: the Netherlands, Belgium, Germany, France, Spain, the United
Kingdom and the United States. The add-on is free; it needs Erpy, which is the paid part and owns
the sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-exactonline
php craft plugin/install erpy-exactonline
```

Then **Erpy → Connections → New connection** and pick **Exact Online**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, on `Modified` | 60 |
| Products | ERP → Commerce | Yes, on `Modified` | 60 |
| Prices | ERP → Commerce | Yes, on `Modified` | 60 |
| Inventory | ERP → Commerce | No | 60 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 60 |
| Shipments | ERP → Commerce | Yes | 60 |
| Invoices | ERP → Commerce | Yes | 60 |
| Credit | ERP → Commerce | No | 60 |

The connector declares multi-company support: one connection per division. The page size of 60
is Exact's own, and paging follows the `__next` link Exact returns.

**Prices** are Exact's sales item prices. A price with no account is the item's standard sales
price and becomes the Commerce base price; a price for one account becomes contract pricing for
that customer, matched by account code. Exact's price-list module (Wholesale and Manufacturing
packages only) is not read.

## Connecting

Exact uses the OAuth 2.0 authorization code flow: somebody approves access once in a browser, and
Erpy keeps it alive from then on.

1. **Pick the region.** Exact runs a separate installation per country, and they do not share
   data. Choose the one your login belongs to (`start.exactonline.nl`, `.be`, `.de`, `.fr`, `.es`,
   `.co.uk` or `.com`).
2. **Register an app in the Exact App Centre.** Copy the **Redirect URI** shown on the connection
   form into the app's redirect URI. Exact compares it character for character.
3. **Fill in the Client ID and Client secret** from that app, and save the connection.
4. **Approve access once.** Press **Connect** under the credentials (it appears once the client
   ID and secret are saved). Erpy sends you to Exact's consent screen and stores the tokens it gets
   back on the connection. Until that has happened, **Test connection** reports "Not connected
   yet".

| Field | What to put there |
|---|---|
| **Region** | The country installation your login belongs to. |
| **Redirect URI** | Read-only. Copy it into your Exact app. |
| **Client ID** / **Client secret** | From your App Centre registration. |
| **Division** | The numeric division code. Blank means the login's current division, looked up once per run. |
| **Warehouse code** | Stock is read from this warehouse. Leave blank for the company total. |

**Set the division explicitly before going live.** Left blank, the connector asks Exact for the
login's current division (`current/Me`) once per run and uses that number in every request. That
is whichever division the user last had open in Exact, so it can change under you. **Test
connection** shows both the login's current division and the one in use, so you can check.

## Things to know about Exact Online

**Refresh tokens rotate.** Every refresh invalidates the previous refresh token and issues a new
one. Keep the old one and the connection dies the second time it is used. Erpy writes the rotated
token back to the connection. It also means two integrations sharing one app registration will keep
logging each other out. If **Test connection** reports a 401 saying the refresh token is no
longer valid, that is almost always the cause. Give Erpy its own app and press **Reconnect** to approve access again.

**A wrong division is a silent failure.** Everything in Exact is scoped to a division, and asking
the wrong one returns an empty, entirely successful response. That is worse than an error, which is
why the setting's instructions say so twice.

**The rate limit is real, and daily.** Exact allows roughly one call a second and a hard cap per
day, and answers 429 beyond either. The transport stays under the per-minute limit and honours
Exact's `Retry-After` for the rest. Schedule large syncs accordingly: a full catalogue pull spends
daily allowance a delta sync does not.

**Stock is economic stock.** Exact publishes on-hand less what is already committed to sales
orders, which is the figure a storefront should quote.

**An account is active between its start and end dates.** A customer arrives disabled when today
is before its **Start date** or after its **End date**. **Blocked** is not the same thing: a blocked
account stays enabled and arrives on hold, because Exact uses it to stop new sales, not to end the
relationship. The account's **Status** (none, suspect, prospect, customer) is a sales stage and
does not affect either.

**Order status is numeric.** 12 is open, 20 partially delivered, 21 complete, 45 cancelled.

**Your order number is `YourRef`.** The Commerce order number goes out in the sales order's
`YourRef` field (up to 50 characters). A retry checks it before creating a second sales order, and
order status comes back matched on it.

**Every order needs an account and every line needs an item.** The connector finds the Exact
account by the linked customer, then by customer code, then by email address. An order that
matches none is refused. So is a line whose SKU is not an Exact item code. Set a **Guest customer
code** on the order mapping if guests check out.

**Credit reads the sales side, and the balance from receivables.** The credit limit is the
account's sales credit line (`CreditLineSales`), and the customer's default discount is
`DiscountSales` — the `…Purchase` fields describe the account as a supplier. The balance is the
account's total outstanding receivables from Exact's aging receivables list, read once per credit
sync rather than once per customer, so it spends a handful of calls of the daily allowance rather
than thousands. An account not on that list owes nothing. If the list cannot be read, the credit
sync fails rather than reporting a zero balance.

**Invoice balances come from receivables.** A sales invoice in Exact carries no outstanding
amount and no customer code. The connector resolves the customer from the invoice's `InvoiceTo`
account, and reads what is still owed from Exact's receivables list (the Outstanding Items report),
one call per page of invoices, matched on journal and invoice number. A processed invoice with
nothing outstanding is paid; a draft or open one has not been booked yet and owes its full total.
Amounts are in the invoice's own currency, VAT included.

**Dates look strange in the raw payload.** Exact's OData v2 dates arrive as
`/Date(1596499200000)/`: milliseconds since the epoch, wrapped. The connector converts them, but
a mapping rule that reads a date from `raw.` gets the wrapped string.

## Correcting a field

The connector reads the SKU from the item's `Code`. If your web shop sells under the barcode
instead, correct it on the **Products** mapping. `Barcode` is one of the fields the product sync
selects, so it is in the raw payload:

```
raw.Barcode   →   sku        transform: trim | nullif:
```

The same pattern fixes any other field: the connector's value is overwritten before the document
reaches Commerce.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
