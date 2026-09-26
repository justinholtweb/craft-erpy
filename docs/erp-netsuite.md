---
title: Erpy for NetSuite
slug: netsuite
order: 105
summary: Oracle NetSuite through SuiteTalk REST and Token-Based Authentication, with every bulk read done in SuiteQL so a large catalogue does not take all night.
---

Erpy for NetSuite connects Craft Commerce to Oracle NetSuite: SuiteTalk REST for writing orders,
SuiteQL for reading everything in bulk, and Token-Based Authentication throughout. It works on
standard and OneWorld accounts. The add-on is free; it needs Erpy, which is the paid part and owns
the sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-netsuite
php craft plugin/install erpy-netsuite
```

Then **Erpy → Connections → New connection** and pick **NetSuite**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, on `lastmodifieddate` | 1,000 |
| Products | ERP → Commerce | Yes, on `lastmodifieddate` | 1,000 |
| Prices | ERP → Commerce | No | 1,000 |
| Inventory | ERP → Commerce | No — always a full read | 1,000 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 1,000 |
| Shipments | ERP → Commerce | Yes | 1,000 |
| Invoices | ERP → Commerce | Yes | 1,000 |
| Credit | ERP → Commerce | No | 1,000 |

The connector declares multi-company support (subsidiaries) and that NetSuite offers a sandbox.
Point a connection at your sandbox account first.

**Prices** are NetSuite's price matrix: one row per item, price level and quantity break. That is
exactly the shape Erpy's contract pricing wants. Each price level comes through as a price list,
by name.

## Connecting

**Account ID.** Setup → Company → Company Information shows it. A sandbox looks like
`1234567_SB1`. Enter it once; the connector derives every other spelling from it.

**Token-Based Authentication.** Create an integration record under Setup → Integration → Manage
Integrations with Token-Based Authentication enabled. Then create an access token for it under
Setup → Users/Roles → Access Tokens. NetSuite shows each secret once and never again, so paste them
straight in.

| Field | What to put there |
|---|---|
| **Account ID** | e.g. `1234567`, or `1234567_SB1` for a sandbox. |
| **Consumer key** / **Consumer secret** | From the integration record. |
| **Token ID** / **Token secret** | From the access token. |
| **Subsidiary internal ID** | Required on OneWorld accounts. Leave blank on a single-subsidiary account. |
| **Location internal ID** | The location orders are raised against and stock is read from. |
| **Item category field** | Optional. The id of a custom item field holding the product category, e.g. `custitem_web_category`. Leave blank and no category is read. |
| **Order reference field** | Optional. A custom transaction body field holding the Commerce order number, for accounts where another integration also raises web orders. Erpy's own orders are matched by External ID without it. |
| **Item types to sync** | Comma-separated NetSuite item types. Defaults to `InvtPart,NonInvtPart,Assembly,Kit`. Service and other non-sellable types are usually best left out. |
| **Create orders already approved** | Off by default: orders land pending approval, which is what most finance teams want to start with. |

**The token's role needs three permissions:** REST Web Services, Log in using Access Tokens, and
SuiteAnalytics Workbook. The last one is what allows SuiteQL, and it is the one people miss.

**Test connection** counts the items the role can see and checks whether it can read
subsidiaries. When it fails:

- **`INVALID_LOGIN_ATTEMPT` or 401.** NetSuite says the same thing for every signing problem.
  Check, in this order: the account id (including any `_SB1` suffix), that all four token values
  were pasted without stray whitespace, and that the integration record has Token-Based
  Authentication ticked. A token created for a different role can authenticate and still see
  nothing.
- **403.** The token works but the role is missing one of the permissions above.

## Custom fields are optional

Nothing needs creating in NetSuite before the first sync. SuiteQL refuses a whole query that names
a field the account does not have, so the connector only selects a custom field you have named on
the connection:

- **Item category field** — a `custitem_…` field read as the product category. Blank means
  products arrive without one.
- **Order reference field** — a `custbody_…` field consulted when an order has no External ID.
  Blank is right unless another integration also creates web orders in NetSuite.

An id that does not start with `custitem_` or `custbody_` respectively is ignored, with a warning in
Craft's log, rather than sent to NetSuite.

## Things to know about NetSuite

**It still signs with OAuth 1.0a.** Token-Based Authentication is OAuth 1.0a signed with
HMAC-SHA256. A mis-signed request gets the same opaque `INVALID_LOGIN_ATTEMPT` whichever of the five
credentials or four encoding rules went wrong. Erpy's OAuth 1.0a strategy does the signing, so a
401 is almost always a value rather than a bug.

**The account id has two spellings.** NetSuite shows it, and the OAuth realm wants it, upper-case
with an underscore (`1234567_SB1`). The host name wants it lower-case with a hyphen
(`1234567-sb1.suitetalk.api.netsuite.com`). Both come from the one setting, which accepts either.

**Bulk reads are SuiteQL, not the record API.** NetSuite's REST record API returns links rather
than records: a list gives you ids, and you fetch each one. That is fine for one order and ruinous
for a 40,000-item catalogue. So every bulk read here is SuiteQL, which returns whole rows a
thousand at a time. SuiteQL pages by offset.

**Inventory is always a full read.** Stock is read per location from NetSuite's own
`quantityavailable`: on hand less what is committed to other orders. NetSuite keeps no modified
date for a stock level — a receipt, fulfilment or adjustment changes it without touching the item
record — so there is nothing reliable to filter on, and every inventory run reads every row, a
thousand at a time. That costs a few more requests; a delta that missed a stock movement would cost
a wrong stock figure.

**Booleans are `T` and `F`.** And occasionally `true`/`false` through REST. The connector reads
both.

**Credit counts unbilled orders.** Shipped-but-not-invoiced orders count against the limit in
NetSuite, and they do here too. A storefront that ignored them would let a customer overrun it. A
customer's credit hold override is `AUTO`, `ON` or `OFF`, and only `ON` is a stop.

**`externalId` is a real idempotency key.** The Commerce order number goes out as the sales
order's `externalId`, and NetSuite refuses a second create with the same one. That guarantee holds
even if two workers get past every other guard. A duplicate refusal is treated as "it already
arrived", not as a failure. The number also goes in `otherRefNum` (up to 45 characters), so staff can
search for it. Order status comes back matched on `externalId`, falling back to the **Order
reference field** if you named one, then to `otherRefNum`.

**Every order needs a customer.** NetSuite has no anonymous sale. Link the Craft user to a NetSuite
customer, or set a **Guest customer code** on the order mapping: the customer id guest orders are
booked against. Every line's SKU has to be a NetSuite item name/number.

## Correcting a field

The connector reads the SKU from the item's `itemid`. If you sell under the UPC instead, correct it
on the **Products** mapping. `upccode` is one of the columns the product query selects, so it is in
the raw payload:

```
raw.upccode   →   sku        transform: trim
```

One caveat specific to NetSuite: `raw.` holds only the columns the connector's SuiteQL query
selected, not the whole record. A custom field the query does not select cannot be reached from the
mapping screen — except the category field, which appears as `raw.category` once you name it on the
connection.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
