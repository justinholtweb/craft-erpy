---
title: Erpy for Visma
slug: visma
order: 111
summary: Visma.net ERP (Financials) through the integration API, authenticated with Visma Connect and scoped to one company, with delta syncing that actually filters.
---

Erpy for Visma connects Craft Commerce to **Visma.net ERP** (Financials) through the Visma.net
integration API, authenticated with Visma Connect. The add-on is free; it needs Erpy, which is the
paid part and owns the sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-visma
php craft plugin/install erpy-visma
```

Then **Erpy → Connections → New connection** and pick **Visma.net ERP**. The add-on has no settings
screen of its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, on `lastModifiedDateTime` | 200 |
| Products | ERP → Commerce | Yes, on `lastModifiedDateTime` | 200 |
| Inventory | ERP → Commerce | No, full read | 500 items |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 200 |
| Shipments | ERP → Commerce | Yes | 200 |
| Invoices | ERP → Commerce | Yes | 200 |
| Credit | ERP → Commerce | No | 50 |

Contract prices are not synced; the base price comes from the item's default price. The connector
declares multi-company support: one connection per Visma.net company.

## Connecting

Visma.net uses OAuth 2.0 client credentials through **Visma Connect**. There is no consent screen
to click through, but your integration has to be **approved for the customer's tenant** in the
Visma Developer Portal before any of this works.

| Field | What to put there |
|---|---|
| **Client ID** / **Client secret** | From your integration in the Visma Developer Portal. |
| **Scope** | The scopes granted to your integration. Defaults to `vismanet_erp_service_api:read vismanet_erp_service_api:create`. A read-only installation can drop the create scope, but then it cannot send orders. |
| **Company ID** | Sent as the `ipp-company-id` header. |
| **Warehouse** | The warehouse stock is read from and order lines are raised against. |
| **Sales order type** | Defaults to `SO`. |
| **Create orders on hold** | On by default, so an order can be reviewed before it reaches the warehouse. |

**Test connection** reads the inventory list:

- **401.** The client id and secret, or the integration has not been approved for this customer's
  tenant.
- **403.** The token is valid but lacks the Visma.net ERP scope, or the company id belongs to a
  tenant this integration was not granted.
- **"Yes, but the company has no items".** The test passed, but the company is probably the wrong
  one. See below.

## Things to know about Visma.net

**A wrong company is a silent failure.** Every request is scoped to a company by the
`ipp-company-id` header. Omit it, or send the wrong one, and the API answers with a perfectly
successful empty list. That is worse than an error, which is why the connection test checks for it.

**Delta syncing is two parameters, not one.** Visma filters on `lastModifiedDateTime` only when it
also gets `lastModifiedDateTimeCondition`. Send the timestamp alone and it returns everything,
which looks like a working delta sync until somebody times it. The connector always sends both.

**Paging is by page number.** Visma returns no cursor; a short page means the end.

**Look-up values change shape.** Visma wraps some values in `{"value": …, "description": …}` and
sends a bare string for the same field elsewhere, depending on the endpoint. The connector accepts
both. When you reach into `raw.` on the mapping screen, check the preview to see which one you got.

**Stock is read with the item list.** The connector asks for items with their warehouse details
expanded, so one request covers a page of items and every warehouse they are stocked in: on hand,
and Visma's own available figure, per warehouse. Visma has no stock list endpoint; the inventory
summary answers one item per request, which would be a request per SKU on every sync. Set
**Warehouse** to read one warehouse only. Stock syncs are full reads: the item list's availability
date filter is not documented well enough to trust a stock level to.

**Items.** Non-stock items (service, labour, charge and expense items) come through without
inventory tracking and are skipped by the stock sync. Items with status **No sales**, **Inactive** or
**Marked for deletion** arrive blocked; **No purchases** and **No request** only stop buying the item
in, so they stay on sale. Weight comes from the item's packaging, the tax category from its VAT
code.

**Customers.** The price list code is the customer's price class, and the credit limit counts only
when the customer's credit verification uses one (**Credit limit** or **Limit and days past due**).
With verification off, Erpy treats the account as having no limit, as Visma does. Customers with
status **On hold** or **Credit hold** arrive on hold.

**Credit reads the balance separately.** The customer record carries no balance, so the credit
sync reads each customer's balance from Visma's customer balance endpoint. That is one extra
request per customer, which is why credit pages are 50 customers. If a balance cannot be read the
page fails and is retried; it is never written as zero.

**Shipments are matched through the identity map.** A Visma shipment does not carry the customer
order number: its lines name the Visma sales order. Erpy turns that back into the Commerce order
through the orders it has sent. A shipment for an order Erpy did not send is skipped and counted in
the run log, never guessed at. Only confirmed shipments are read (status **Confirmed**,
**Completed**, **Invoiced** or **Partially invoiced**); an open shipment is still a pick list.
Transfers are ignored. Tracking numbers come from the shipment's packages; the carrier is the
ship-via code. A shipment covering two orders is recorded once against each.

**Invoices.** The customer is the invoice's customer number, the due date its document due date,
and the order is found from the sales order named on its lines, through the identity map. Subtotal
is the detail total and tax the VAT total.

**Your order number is `customerOrder`.** The Commerce order number goes out in the sales order's
customer order field. Visma.net has no filter on that field (its sales order list filters by order
type, status, order number and modification date only), so before creating an order the connector
reads the customer's own sales orders modified since the Commerce order was placed and looks for
one whose customer order number is exactly the one being sent. Only an exact match counts as
"already exists"; anything else is created. The check reads up to 2,000 of the customer's recent
orders; beyond that it notes as much in the run log and creates the order. If Visma cannot be
asked at all, the push is retried rather than sent blind. Order status comes back matched on the
same field. Visma answers a create with 201 and the new order's address in the `Location` header
rather than a body.

**The v1 sales order endpoint is deprecated.** Visma marks `GET` and `POST /v1/salesorder`
deprecated in favour of its separate Sales Order Service (`salesorder.visma.net`, API v3). They
still work, and this connector still uses them.

**Orders are sent in Visma's update shape.** Every value goes as `{"value": …}`, which is what
Visma's sales order endpoint expects. If you map extra fields onto the order, give plain values; the
connector wraps them.

**Every order needs a customer number.** Link the Craft user to a Visma customer, or set a **Guest
customer code** on the order mapping: the customer number guest orders are booked against.

## Correcting a field

The connector reads the SKU from `inventoryNumber`. If your web SKU is held elsewhere, such as the
item's alternate ID or a custom attribute your partner added, correct it on the **Products**
mapping:

```
raw.alternateId   →   sku        transform: trim
```

Where the field is a look-up value, add `.value` to the path, e.g. `raw.itemClass.value`, and check
the mapping preview. Visma is not consistent about which fields are wrapped.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
