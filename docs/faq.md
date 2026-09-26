---
title: FAQ
slug: faq
order: 80
summary: Licensing, the add-on model, what happens to your data, and the questions that come up before a purchase.
---

# FAQ

## Do I have to pay for the connector too?

No. Erpy is $149 with a $129 annual renewal. Every connector add-on is free, for every ERP on the
list, forever.

## Why is the connector free?

Because there is not much in it. A connector turns one page of one vendor's payloads into canonical
documents. Everything that has to be true of all of them — the identity map, delta watermarks,
retries, dead letters, mapping, logging, scheduling, paging — is Erpy's, written once. Charging
separately for connectors would also mean a merchant on an unusual ERP paying more for less.

## My ERP is not on the list.

A connector is one class, and the interface is documented in [Connectors](../connectors). If you
would rather not write it, email me — the list grows in the order people ask.

## Can I try it without ERP credentials?

Yes. A **Mock ERP** connector ships with Erpy, makes no network calls, and invents deterministic
data. You can walk connections, mapping, dry runs, the run log, dead letters and replay before
anyone buys an ERP licence.

## Is there a Lite edition?

No. Single edition, one price.

## Will it overwrite my Commerce data?

Only what you tell it to. Direction is per entity, you can narrow what a connector offers but
never widen it, and **Preview** reads a page and reports exactly what it would create, update or
skip while changing nothing. Run that first.

## Can the same order be sent twice?

No. The identity map has a unique database index on the pairing between a Commerce order and its
ERP document. A retried queue job, a double-clicked button and a webhook arriving twice all hit the
same constraint. It is not a remembered check that could be raced.

## What happens if the ERP is down when someone checks out?

Nothing visible to the customer. Order push is always queued, never inline — the sale completes,
and the document goes when the ERP answers again. If it refuses the document permanently, it lands
on **Problems** whole, ready to replay once the cause is fixed.

Everything Erpy runs during checkout fails open, including contract pricing: a bad lookup falls
back to Commerce's own price rather than taking the storefront down.

## Why are connections in the database instead of project config?

Because project config deploys. A staging site's ERP credentials arriving in production on the
next deploy — or worse, a sandbox connection quietly becoming a live one — is the worst thing this
plugin could do. That risk is not worth the convenience.

## Why not mirror ERP prices into Commerce pricing rules?

A mid-market ERP holds tens of thousands of negotiated price lines. One catalog pricing rule per
line would make catalog price generation the slowest thing on the site. Erpy resolves contract
prices at cart time instead.

## My ERP names its fields differently from what the connector expects.

That is what the mapping overlay is for. A rule whose target is a canonical field overwrites what
the connector read, before the document reaches Commerce. Several of these ERPs are configured per
customer — AFAS, Priority, Unit4, a published Business Central page — so a connector cannot be
right about it in advance. See [Field mapping](../mapping).

## Does it work with multi-store Commerce?

Yes. A connection targets one store, so a site with two stores and two ERP companies is two
connections.

## Will it slow my site down?

The storefront work is a price lookup and, optionally, a credit check — both cached, both failing
open. The heavy work is syncing, which runs in the queue or from cron, never in a web request.

## What are the requirements?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## How do I get support?

justin@justinholt.com.
