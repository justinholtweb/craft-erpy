---
title: Troubleshooting
slug: troubleshooting
order: 70
summary: Where to look first, and the failures that turn out to have ordinary causes.
---

# Troubleshooting

## Start here

1. **Is it Erpy or the connector?** Make a Mock ERP connection and run the same flow. The Mock
   makes no network calls and invents deterministic data. If it works there, the problem is the
   connector, the credentials or the tenant — not the engine.
2. **Test connection.** It calls the connector's health check and names the credential to look at.
3. **Erpy → Log.** Every request and response, with secrets redacted. Filter to errors.
4. **Erpy → Problems.** A refused document is kept whole, with the reason.

## Test connection says "Not connected yet"

The ERP uses OAuth consent (Exact Online, Sage 200, Sage Business Cloud Accounting) and nobody has
approved access yet. Save the client ID and secret, then press **Connect** in the connection's
**Authorisation** field and approve access on the ERP's sign-in screen. No **Connect** button
means the connection is not saved yet, the client ID or secret is missing, or your user lacks the
*Add, edit and delete connections* permission. If it was connected before, the ERP has revoked or
expired the consent: press **Reconnect**.

## The sync says it succeeded but nothing changed

Almost always the delta watermark. The ERP was asked for "changed since X" and answered honestly
that nothing had.

```sh
php craft erpy/sync/status               # what the watermark currently is
php craft erpy/sync/reset acme product   # forget it; next run is a full one
php craft erpy/sync/run acme product --full
```

If a full run also changes nothing, check the mapping preview — a rule producing `null` writes
nothing, and a rule pointing at a field that no longer exists silently does the same.

## Products sync but stock does not

Check that the variants track inventory. A variant that does not track inventory is skipped by the
stock sync. Erpy sets the flag on variants it creates; variants created some other way may not
have it.

## Orders are not reaching the ERP

```sh
php craft erpy/orders/missing acme
```

Then, in order:

- Is `pushOrdersOnComplete` on?
- Is the queue running? Push is always queued, so a stalled queue means no orders move. This is
  the most common cause by a wide margin.
- Is `pushOrderDelaySeconds` set to something longer than you remember?
- Is the connection enabled, and does its connector declare a push direction for orders?
- Anything on **Problems**?

```sh
php craft erpy/orders/push acme 1000123 --dry-run
```

shows the exact payload without sending it.

## A run is stuck on "running"

Its worker was killed — a deploy, an OOM, a restarted container. Erpy marks a run stale after
`staleRunMinutes` (default 60) and lets the next one start. The watermark did not move, so nothing
was lost.

## The same order appeared twice in the ERP

It did not come from Erpy. The identity map has a unique index on the pairing, and a second insert
is refused by the database rather than by a check that could be raced. Look for a second
integration, a manual entry, or an ERP-side workflow that copies orders.

## Prices on the storefront are Commerce's, not the ERP's

Contract pricing fails open by design: a bad lookup falls back to Commerce's own price rather than
taking the storefront down. So a wrong price is a silent fallback, not an error page.

- Is `applyContractPricing` on?
- Has the price sync run for this connection?
- Does `craft.erpy.price(variant, 1)` return `null` for this customer? That means the ERP has no
  contract line for the pairing, which is a data question rather than a plugin one.
- Is the ERP price a quantity break or a dated promotion for everybody? Those are applied at cart
  time at their quantity and in their window, and never written to the variant — so the product
  page's Commerce price is the quantity-one, undated line. See [Orders](../orders).

## An error message contains something that looks like my API key

It should not. The transport redacts secrets out of non-2xx bodies before a connector or a health
screen ever sees them. Success bodies are left alone, because connectors have to parse them — so a
credential echoed inside a **200** response can still reach the log if `logBodies` is on. Turn it
off on a connection whose ERP does that, and tell me which ERP so the connector can strip it.

## Everything is slow

- `logBodies` on a 40,000-SKU sync writes a lot of rows. Try `logErrorsOnly`.
- `recordSkippedItems` on a delta sync records the 39,900 rows that did not change. Leave it off.
- Run `erpy/sync/due` from cron instead of the queue. Cron has no request timeout.
- Check the per-entity intervals. Inventory wants minutes; a product catalogue does not.

## Getting help

justin@justinholt.com. Useful things to include: the connector and ERP version, what
`php craft erpy/sync/status` prints, and whether the same flow works against the Mock ERP.
