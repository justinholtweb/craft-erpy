---
title: Configuration
slug: configuration
order: 20
summary: The connection, what it syncs and how often, and the plugin settings that apply to every ERP.
---

# Configuration

Erpy splits its settings in two. Anything that belongs to **one ERP** lives on its connection.
What is left is on the plugin's settings screen, because it should be true of every ERP you talk
to.

## The connection

**Erpy → Connections → your connection.**

| | |
|---|---|
| **Name and handle** | The handle is what console commands take: `php craft erpy/sync/run acme` |
| **Connector** | Which ERP. Only an admin can change it on a saved connection |
| **Enabled** | A disabled connection never syncs, scheduled or otherwise |
| **Store** | Which Commerce store this connection feeds, on a multi-store site |
| **Credentials** | Whatever this connector declares — a URL, a key, a tenant, an OAuth flow. Literal secrets and OAuth tokens are encrypted at rest; `$ENV` references are stored as written |

### What it syncs

Each entity — products, prices, inventory, customers, orders, order status, shipments, invoices,
payments, credit — gets its own row: **direction**, **interval**, and any **filters** the
connector offers.

Only what your ERP actually supports is offered. You can narrow a direction; you cannot widen
one. If a connector declares that it can pull products but not push them, there is no setting
that will make it try — and the conformance suite that ships with Erpy is what keeps that
promise honest.

**Intervals are per entity on purpose.** Inventory usually wants fifteen minutes and the product
catalogue usually wants overnight. One interval for the whole connection means either hammering
the ERP for a catalogue that changes twice a week, or letting stock go stale.

### Changing where a connection points

Only an admin can change a saved connection's connector or any of its endpoint URLs. When one
changes, the stored secrets and OAuth tokens are dropped — they were issued for the old host — so
enter them again in the same save, or reconnect. Leaving a secret blank on any other save keeps
the stored one.

### Test connection

Press it after every credential change. It calls the connector's health check, which is a real
request to the ERP, and reports what came back — with secrets redacted.

### Connect (OAuth consent)

Some ERPs — Exact Online, Sage 200 and Sage Business Cloud Accounting — make a person approve
access once, in the ERP's own sign-in screen. For those connectors the connection screen has an
**Authorisation** field under the credentials:

1. Fill in the client ID and secret, copy the redirect/callback URL into the ERP's app
   registration, and **save** the connection. The button only appears on a saved connection with
   a saved client ID and secret, because the tokens need a row to land on.
2. Press **Connect**. Erpy sends you to the ERP's consent screen; approve access there.
3. The ERP sends you back to the connection screen with "Connected." — the refresh token is now
   stored on the connection, and **Test connection** makes a real request.

The field shows whether the connection is connected, when access was approved, and — where the
ERP says — when the current access token and the consent itself expire. Once connected the button
reads **Reconnect**: use it when the ERP has revoked access or the consent has expired.
**Disconnect** forgets the stored tokens. Connecting needs the *Add, edit and delete connections*
permission; somebody who can only view connections sees the state but not the buttons.

The consent link is valid for fifteen minutes and works once. If the ERP says the link has
expired, start again from the connection screen.

## Plugin settings

**Erpy → Settings.**

### Logging

| Setting | Default | |
|---|---|---|
| `logRequests` | on | Record requests in the connection log at all |
| `logBodies` | on | Bodies are the useful part, and also the large part |
| `logErrorsOnly` | off | A busy catalogue sync is tens of thousands of successful requests nobody will read |
| `logRetentionDays` | 14 | |
| `runRetentionDays` | 30 | |
| `recordSkippedItems` | off | On a 40,000-SKU delta sync, "unchanged" is 39,900 of the rows |
| `staleRunMinutes` | 60 | A run still marked *running* after this long had its worker killed |

A log write must never be the reason a sync fails. If the log table is unavailable, or refuses
a row, the sync carries on and Craft's own log gets a warning instead.

### Orders

| Setting | Default | |
|---|---|---|
| `pushOrdersOnComplete` | on | Queue a push when an order completes. The only automatic write Erpy makes |
| `pushOrderDelaySeconds` | 0 | Wait before pushing — useful when payment capture is asynchronous and the ERP should not see an order the gateway has not settled |
| `pushMaxAttempts` | 5 | Retries before the document dead-letters for good |

Order push is **always queued, never inline.** An ERP having a slow afternoon must not delay a
customer's confirmation, and an ERP being down must not fail a sale.

### Storefront

| Setting | Default | |
|---|---|---|
| `applyContractPricing` | on | Apply ERP contract pricing to cart line items |
| `enforceCreditLimit` | off | Refuse to complete an on-account order that would exceed the customer's credit limit |
| `creditMaxAgeMinutes` | 60 | Credit figures older than this are re-fetched before they are trusted to block a sale |

`enforceCreditLimit` is off by default because it can stop a sale, and that has to be a decision
rather than a surprise.

### Scheduling

`scheduleEnabled` lets scheduled syncs run from Craft's queue. For a large catalogue, run
`erpy/sync/due` from cron instead — cron has no request timeout. See [Syncing](../syncing).

### Alerts

Who is told when a connection gets into trouble — dead letters piling up, the ERP refusing the
credentials, a scheduled sync that has stopped — by email and optionally Slack or Teams. One
message when it starts, one when it clears. Every setting and threshold is on the
[Alerts](../alerts) page.

## Permissions

Erpy registers its own permissions, so a warehouse manager can watch activity without being able
to edit credentials:

- **View ERP connections** → add/edit/delete connections, run a sync by hand, edit field mappings
- **View sync activity and problems** → replay and dismiss failed documents
- **View log**
