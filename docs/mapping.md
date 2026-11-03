---
title: Field mapping
slug: mapping
order: 40
summary: Rules, the transform vocabulary, and the overlay that lets a mapping correct the connector itself.
---

# Field mapping

The connector fills in everything canonical already. Mapping is for the rest — your custom fields,
and the places where your ERP tenant does not match the connector's assumptions.

**Erpy → Connections → your connection → Mapping.** Rules are per connection, per entity, per
direction.

## A rule

A rule reads a **source** out of the document and writes it to a **target**, optionally through
one or more **transforms**, with a **default** when the source is empty.

The source can be any path in the canonical document — and that includes `raw.`, the untouched ERP
payload the connector received. If your ERP sends a field no connector knows about, `raw.` is how
you reach it.

```
raw.Item_Group_Code   →   productType        transform: trim | upper
raw.Web_Description   →   myRichTextField    transform: striptags
unitPrice             →   myPriceField       transform: round:2
```

## The overlay: a mapping can correct the connector

A rule whose target is a **canonical** field — `sku`, `unitPrice`, `customerCode` — is not a Craft
mapping at all. It overwrites what the connector read, before the document reaches Commerce, and
coerces the value to the property's declared type.

This is the escape hatch that makes the whole add-on model work. A connector that guessed the
wrong field name on your tenant is a mapping rule, not a support ticket and a release cycle. It is
essential for the ERPs whose API is configured per customer — AFAS, Priority, Unit4, a published
Business Central page.

Canonical rules are applied by the overlay and then deliberately skipped by the Craft-field pass,
so `sku` is never also looked for as a Craft field on your product.

## Transforms

The vocabulary is small and non-Turing-complete on purpose. A mapping screen that can run
arbitrary code is a mapping screen that can take a site down at 3am.

Chain them with `|`, and pass an argument after `:`.

| | |
|---|---|
| `trim` | Trim whitespace |
| `upper` `lower` `ucfirst` `title` | Case |
| `slug` | Slugify |
| `striptags` | Strip HTML |
| `int` `number` `abs` `round:2` | Numbers |
| `decimal:,` | A number written with a decimal comma — `1.234,56`, `1 234,56`, `12,5`. `decimal:.` reads `1,234.56`. Anything that is not a number becomes empty rather than zero. |
| `bool` `not` | Yes/no — and an ERP expresses "no" as an alarming number of things |
| `prefix:` `suffix:` `truncate:255` | |
| `replace:find:replace` | Find and replace |
| `date:Y-m-d` | Format as a date |
| `join:, ` `split:,` `first` `count` | Lists |
| `json` | Encode the value |
| `default:` | Fall back to a value when empty |
| `nullif:` | Treat a particular value as empty |

`nullif:` earns its place more often than it looks like it should. ERPs are full of sentinel
values — a `0001-01-01` date, a `0` customer code, an `N/A` description — that mean "empty" and
will otherwise be written into Commerce as if they were data.

## Per-entity options

Each entity's mapping screen carries its own switches — "create missing products", and so on.
They live with the rules because they are the same kind of decision: what this connection should
do with this entity, on this site.

### Available or on hand

An inventory mapping chooses what the ERP's quantity sets in Commerce.

- **Available** (the default) writes the ERP's sellable figure into Commerce's available stock.
  That is the ERP's own availability when it reports one, or on-hand minus allocated.
- **On hand** writes the ERP's physical count as Commerce's on-hand stock. Commerce books the
  difference to available and keeps the units it has committed to orders that haven't shipped.
  Choose it when the ERP owns stock. With **Available**, those committed units would be counted
  once in the ERP's figure and again in Commerce's commitments, so they could be sold twice.

The **Hold back** buffer is subtracted in either mode.

For tens of thousands of stock lines, a site can take each page of a stock pull through
`Catalog::EVENT_BEFORE_APPLY_STOCK` and hand it to a bulk writer. Put an `ApplyResult` in
`$event->results` for each document you write. Erpy writes the rest and logs all of them:

```php
use justinholtweb\erpy\events\ApplyStockEvent;
use justinholtweb\erpy\services\Catalog;
use yii\base\Event;

Event::on(Catalog::class, Catalog::EVENT_BEFORE_APPLY_STOCK, function(ApplyStockEvent $event) {
    foreach ($event->documents as $key => $stock) {
        // $event->quantity($stock) is what Erpy would write; $event->target is 'available' or 'onHand'.
        $event->results[$key] = MyBulkWriter::queue($stock->sku, $event->quantity($stock), $event->target);
    }
    MyBulkWriter::flush();
});
```

### A variant Erpy creates has to be told to track inventory

If you let Erpy create variants that do not exist in Commerce yet, note that a new variant does
not track inventory by default — and a variant that does not track inventory is skipped by the
first stock sync. Erpy sets the flag on variants it creates for exactly this reason. If you are
creating variants some other way and their stock never arrives, that is the thing to check.

## Preview before you trust it

The mapping screen's preview reads a real page from the ERP and shows what each rule produces,
against real values. Do that before a first full run — a `round:2` on a field that turns out to be
a string is much cheaper to find here than in 40,000 product saves.
