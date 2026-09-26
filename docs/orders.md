---
title: Orders and B2B
slug: orders
order: 60
summary: Pushing completed orders, contract pricing at cart time, credit limits, and the Twig helpers.
---

# Orders and B2B

## Pushing an order

When an order completes, Erpy queues a push. That is the only automatic write it makes.

**Order push is always queued, never inline.** An ERP having a slow afternoon must not delay a
customer's confirmation, and an ERP being down must not fail a sale. If you need the ERP to see
only settled orders, set `pushOrderDelaySeconds` rather than making the push synchronous.

One Commerce order becomes one ERP document in exactly one place, and one place delivers it. That
is what makes the control panel's payload preview trustworthy: it is not a rendering of what
*would* be sent, it is the thing that gets sent.

```sh
php craft erpy/orders/missing acme                  # completed orders the ERP never received
php craft erpy/orders/push acme 1000123
php craft erpy/orders/push acme 1000123 --dry-run   # show the payload, send nothing
php craft erpy/orders/retry acme                    # resend everything on the Problems screen
```

`erpy/orders/missing` is the command to run after any incident. It compares completed Commerce
orders against the identity map and lists what never landed.

### It cannot send twice

The identity map has a unique index on the pairing. A retried job, a double-clicked button and a
webhook arriving twice all hit the same constraint. See [Syncing](../syncing).

## Contract pricing

A mid-market ERP holds tens of thousands of negotiated price lines. Erpy resolves them **at cart
time** rather than mirroring them into Commerce's catalog pricing rules — one pricing rule per
line would make catalog price generation the slowest thing on the site.

With `applyContractPricing` on, cart line items are priced from the customer's contract
automatically. The Twig helpers below are for *showing* a price, not for applying it.

**Which price wins.** The most specific audience, not the cheapest price: a price for this
customer beats their group's, which beats their price list's, which beats a price for everybody.
Within one audience, the largest quantity break the cart qualifies for wins.

**Only the plain price for everybody goes onto the variant.** A price line with no customer, group
or price list (or on the base price list you name in the price mapping) becomes the variant's
Commerce price — but only if it is for a quantity of one and has no end date. A "buy 10" line for
everybody is a quantity break and a dated one is a promotion; both stay in Erpy's price table and
are applied at cart time, so a quantity break is never charged to somebody buying one and a
promotion never outlives its window.

**Contract pricing fails open.** A bad lookup falls back to Commerce's own price rather than
taking the storefront down. Everything Erpy runs during checkout does.

```twig
{# The customer's negotiated price, or null when the ERP has no opinion #}
{% set price = craft.erpy.price(variant, 1) %}

{# The "buy 10 and save" table, for this customer only #}
{% for tier in craft.erpy.priceBreaks(variant) %}
    {{ tier.minQuantity }}+ — {{ tier.unitPrice|commerceCurrency('USD') }}
{% endfor %}
```

## Accounts and credit

A Craft user's B2B standing — price list, payment terms, credit limit, balance — is synced from
the ERP and available on the storefront.

```twig
{% set account = craft.erpy.account() %}

{% if account %}
    <p>Terms: {{ account.paymentTerms }}</p>
    <p>Available credit: {{ (account.creditLimit - account.balance)|commerceCurrency('USD') }}</p>
{% endif %}

{% if account and not craft.erpy.canSpend(cart.totalPrice) %}
    <p>This order would take you over your credit limit. Please pay by card.</p>
{% endif %}
```

`enforceCreditLimit` makes that refusal binding at checkout. It is off by default because it can
stop a sale, and that has to be a decision rather than a surprise. Credit figures older than
`creditMaxAgeMinutes` are re-fetched before they are trusted to block one.

## Order status back from the ERP

Released, picking, shipped, cancelled — plus shipments with carriers and tracking numbers, and
invoices with balances and due dates.

```twig
{% set status = craft.erpy.orderStatus(order.number) %}
```

Shipment rows carry a quantity, so partial fulfilment across several deliveries is answerable
rather than approximated.

## Guest checkout

Commerce 5 attaches an inactive user to every guest checkout, so an order having a customer does
not mean anybody registered. Erpy uses the `active` flag to tell them apart — worth knowing if you
are writing your own rules around `craft.erpy.account()`.

## Events

```php
// Adjust or drop an inbound document before it reaches Commerce
Event::on(Sync::class, Sync::EVENT_BEFORE_APPLY_DOCUMENT, function(ApplyDocumentEvent $e) {
    $e->document->sku = strtoupper($e->document->sku);
});

// Adjust an outbound document before the connector sees it
Event::on(Push::class, Push::EVENT_BEFORE_PUSH, function(BuildDocumentEvent $e) {
    $e->document->customFields['ProjectCode'] = $e->source->myProjectField->value;
});
```
