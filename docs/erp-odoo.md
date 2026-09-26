---
title: Erpy for Odoo
slug: odoo
order: 106
summary: Odoo Community or Enterprise through the external JSON-RPC API, with an API key, delta syncing on write_date, and errors read from the body because Odoo answers everything with HTTP 200.
---

Erpy for Odoo connects Craft Commerce to Odoo, Community or Enterprise, self-hosted or Odoo
Online, through Odoo's external JSON-RPC API. The add-on is free; it needs Erpy, which is the paid
part and owns the sync engine, the identity map, mapping, the queue and the log.

## Install

```sh
composer require justinholtweb/craft-erpy-odoo
php craft plugin/install erpy-odoo
```

Then **Erpy → Connections → New connection** and pick **Odoo**. The add-on has no settings screen of
its own; everything lives on the connection.

## What it syncs

| Entity | Direction | Delta sync | Page size |
|---|---|---|---|
| Customers | ERP → Commerce | Yes, on `write_date` | 200 |
| Products | ERP → Commerce | Yes, on `write_date` | 200 |
| Prices | ERP → Commerce | Yes, on `write_date` | 200 |
| Inventory | ERP → Commerce | Yes, on `write_date` | 500 |
| Orders | Commerce → ERP | | |
| Order status | ERP → Commerce | Yes | 200 |
| Shipments | ERP → Commerce | Yes | 200 |
| Invoices | ERP → Commerce | Yes | 200 |
| Credit | ERP → Commerce | No | 200 |

`write_date` is indexed on every Odoo model, which makes delta syncing cheap and reliable here in
a way it is not on several of the older ERPs. The connector declares multi-company support and
that Odoo offers a sandbox: a staging database or a trial instance is a good first target.

## Connecting

| Field | What to put there |
|---|---|
| **Odoo URL** | e.g. `https://example.odoo.com`. |
| **Database** | On Odoo Online this is usually the subdomain. |
| **Login** | The email address of a dedicated integration user. |
| **API key** | Generated under that user's Preferences → Account Security. |
| **Warehouse ID** | The numeric id of the stock location stock is read from, including its child locations. Leave blank for all internal locations. |
| **Base pricelist ID** | Usually your public pricelist. Its items are left out of the contract price sync, because the Commerce base price already comes from each product's **Sales Price**. Every other pricelist becomes contract pricing. |
| **Sales team ID** | Optional. Web orders are often given their own team so they can be reported on separately. |
| **Confirm orders on arrival** | Off by default: orders arrive as quotations for somebody to review. On confirms them, which reserves stock straight away. |

**Use an API key, not the password.** An API key works everywhere a password does and can be
revoked on its own. Some Odoo Online instances refuse password logins over RPC entirely.

If **Test connection** says Odoo would not accept the credentials, check the database name as well
as the key. Odoo answers a wrong database exactly as it answers a wrong password. A passing test
shows the server version and how many sellable products the user can see.

## Things to know about Odoo

**It is not a REST API.** There are no endpoints, only models and methods. Everything the connector
reads is a `search_read` against a model with a domain, and an order is a `create` on
`sale.order`.

**Errors arrive as HTTP 200.** A missing model, a permission failure and a Python traceback all
come back with a success status and an `error` object in the body. So the connector reads the
body, not the status. A failed login is `false` with a 200, too. The log shows the real error.

**The SKU is `default_code`.** Odoo's **Internal Reference** is the only field that behaves like a
SKU. A product without one arrives with an empty SKU and cannot be matched to Commerce. Only
products marked **Can be sold** are read, and only storable products have their stock tracked.

**Relations are `[id, "Display Name"]`.** A many-to-one field is a two-element array, not a scalar.
The connector unpacks them; a mapping rule reading one from `raw.` gets the array. Use
`raw.categ_id.1` for the name and `raw.categ_id.0` for the id.

**Stock is what is not already reserved.** Inventory reads `stock.quant` in internal locations and
uses Odoo's `available_quantity`, not the on-hand figure.

**Only two kinds of pricelist rule can be honoured.** A pricelist item is a fixed price, a
percentage off, or a formula. Only rules set on a product variant are read, and of those:

- A **fixed price** comes through as that price.
- A **discount** (percentage) comes through as a percentage off, applied at cart time to the
  Commerce price, but only when it is based on the **Sales Price**. That is the price the product
  sync writes to Commerce. A discount off the cost or off another pricelist has no Commerce
  equivalent and is left out.
- A **formula** is left out. Honouring it would mean re-implementing Odoo's pricing engine.

Rules that are left out are filtered in the query, so they never become a contract price at all,
least of all a zero one. A customer whose only rules are formulas pays the Commerce price. If a
formula pricelist matters to you, have Odoo compute it into fixed prices on a pricelist of its own.

**Odoo stores `0` for "no credit limit".** That is not the same as a limit of zero, and it is not
read as one.

**Times are UTC with no marker.** Odoo stores `write_date` in UTC without saying so. The connector
converts the watermark before comparing, because formatting it as-is would make a site in a
negative offset skip several hours of changes on every sync.

**Customer codes are `ref`.** A partner's **Reference** is its customer code. A partner with no
reference falls back to its numeric id, so it can still be addressed. Customers, credit and
invoices all derive the code the same way, so an invoice lands on the same account as the
customer it belongs to. When an order is pushed, the customer is found by linked id, then by
code, then by email address. A code is looked up as a reference first; a purely numeric code that
no partner has as a reference is taken as the id of a partner with no reference, which is the
fallback run in reverse. Give your B2B partners a reference all the same: an id changes if a
partner is ever recreated, and a reference does not.

**Credit is always read in full.** A partner's balance is computed from journal items, so it moves
without the partner's `write_date` changing. A delta read would miss every payment.

**Your order number is `client_order_ref`.** The Commerce order number goes out as the
quotation's customer reference. A retried job asks for it before creating, so it does not leave
two quotations behind. Order status and shipments come back matched on it. Every line's SKU must
match a product's Internal Reference, or the order is refused and names the SKU.

## Correcting a field

If your catalogue keys on the barcode rather than the Internal Reference, correct the SKU on the
**Products** mapping. `barcode` is one of the fields the product read requests, so it is in the
raw payload:

```
raw.barcode   →   sku        transform: nullif: | trim
```

The `nullif:`, with nothing after the colon, is there because Odoo sends an empty field as
`false`, not as an empty string. It turns that `false` into nothing, and a rule that resolves to
nothing is skipped. So a product with no barcode keeps its Internal Reference as its SKU rather than
losing it.

See [Field mapping](/plugins/craft-erpy/docs/mapping) for the rule syntax and the full transform
list, [Syncing](/plugins/craft-erpy/docs/syncing) for runs and watermarks, and
[Troubleshooting](/plugins/craft-erpy/docs/troubleshooting) when something does not arrive.
