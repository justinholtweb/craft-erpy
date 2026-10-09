---
title: Syncing
slug: syncing
order: 50
summary: Runs, delta watermarks, dry runs, dead letters and replay — and the console commands to put on cron.
---

# Syncing

Every inbound sync — the control panel button, the queue job, the scheduled run, the console
command, a webhook — ends in the same place. That is not tidiness for its own sake: it is what
makes paging, delta watermarks, the identity map, dry runs, dead letters and the run log behave
identically no matter how a sync was started.

## Running one

**Erpy → Connections → your connection → Sync now**, or:

```sh
php craft erpy/sync/status               # where every connection has got to
php craft erpy/sync/run acme             # everything this connection syncs
php craft erpy/sync/run acme product     # one entity
php craft erpy/sync/run acme product --full
php craft erpy/sync/due                  # the one to put on cron
php craft erpy/sync/reset acme product   # forget the watermark; next run is a full one
```

For a large catalogue, run `erpy/sync/due` from cron rather than relying on the queue. Cron has no
request timeout.

```
*/5 * * * * cd /path/to/site && php craft erpy/sync/due >> /dev/null 2>&1
```

## Dry runs

**Preview** reads a page and reports exactly what it would create, update or skip — and changes
nothing. Do this first, every time, on a connection you have just changed.

It is the same code path as a real run with the writes withheld, so what it reports is what would
happen, not an estimate of it.

## Delta watermarks

After a successful run, Erpy remembers where it got to, and the next run asks the ERP only for
what changed since.

**The watermark advances to the *start* of a run, never to the end, plus a two-minute overlap.**
A record modified while a run is in flight would otherwise fall into the gap between "when I
started asking" and "when I finished", and never be seen again. Re-reading a few records costs one
content-hash comparison; missing one costs a wrong price for as long as nobody notices.

**A failed run does not move the watermark at all.**

**Only entities the connector declares delta for get one.** The connection screen's *Delta* column
says which: an entity marked *full* is read in its entirety on every run, and Erpy never hands its
connector a "changed since" date, even though the run still records when it happened. A few ERPs
cannot filter some records by modification date at all — MYOB Exo's debtor balances, for example —
and asking them to would quietly turn a full read into a partial one. `erpy/sync/status` prints
"full read every run" for those entities rather than a watermark.

Change detection is by content hash, so a nightly 40,000-SKU pull costs one comparison per
unchanged record rather than one element save. That is also why `recordSkippedItems` is off by
default — on a delta sync, "unchanged" is almost every row.

## The identity map

Every pairing between an ERP record and a Craft or Commerce element is stored, with a unique index
on `(connection, entity, natural key)`.

**That index is the duplicate-order guarantee** — not a remembered check, not a flag on the order.
A retried queue job cannot become a second sales order in your warehouse, because the database
will not let it.

Shipment rows carry a quantity, so "has this line been fulfilled?" is answerable across several
partial deliveries rather than only the last one.

Nothing in Erpy's tables has a foreign key to a Commerce or Craft element except a user's B2B
account. Elements get deleted, and losing a sync history because somebody tidied up a product is
worse than holding an id that no longer resolves.

## Problems: dead letters and replay

**Erpy → Problems.**

A document the ERP refused is kept whole, with the request, the response and the reason. Fix the
cause and press **Retry**: the original goes again — rebuilt from Commerce if the order still
exists, from the stored copy if it does not.

```sh
php craft erpy/orders/retry acme    # resend everything on the Problems screen
```

A refusal the connector marked as permanent is not retried automatically. One marked retryable is,
up to `pushMaxAttempts`, and then it dead-letters for good rather than queueing forever.

Nobody has to remember to look: when documents start piling up here, [Alerts](../alerts) emails
the people in `alertRecipients`, once, with a link to this screen filtered to that connection.

## The log

**Erpy → Log** is every request and response, with credentials redacted at the transport — so
there is no path a connector could take that would miss it, including the error bodies that echo
your API key back at you.

Turn `logBodies` off on a busy catalogue if the table grows faster than you want, or
`logErrorsOnly` on. A log write is never allowed to be the reason a sync fails.

## Webhooks

A connector that supports them exposes a webhook URL on its connection. A webhook does not do
anything different from a scheduled run — it starts the same sync, scoped to what the ERP said
changed. That is deliberate: a webhook path with its own write logic is a second implementation
that drifts.

The ERP must `POST` to the URL and send the connection's webhook secret in an `X-Erpy-Secret`
header. A secret in the query string or the body is refused — query strings end up in access
logs.

```http
POST /erpy/webhook/acme?entity=inventory
X-Erpy-Secret: <the connection's webhook secret>
```
