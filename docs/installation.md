---
title: Installation
slug: installation
order: 10
summary: Requirements, the two packages you install, and the first connection.
---

# Installation

Erpy is two installs: the gateway, and the free add-on for your ERP.

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

## Install

```sh
composer require justinholtweb/craft-erpy
composer require justinholtweb/craft-erpy-businesscentral   # or whichever ERP you run

php craft plugin/install erpy
php craft plugin/install erpy-businesscentral
```

The add-on registers a connector with Erpy and does nothing else. It has no settings screen of
its own, no nav item, and nothing to configure — everything it needs lives on the connection you
are about to make.

## Editions and price

Erpy has a single edition. There is no Lite.

| | |
|---|---|
| **Erpy** | $149, $129 to renew annually |
| **Every connector add-on** | Free |

The connectors are free because they are the easy part. A connector turns one page of one
vendor's payloads into canonical documents; everything that has to be true of *all* of them —
the identity map, delta watermarks, dead letters, mapping, logging, scheduling — is Erpy's job,
written once. See [Connectors](../connectors) for the full list.

## Your first connection

Go to **Erpy → Connections → New connection**, pick your ERP from the list, and fill in the
fields it asks for. Every connector asks for something different; the form is built from what
that connector declares.

Then press **Test connection** before you switch anything on. A failed test names the credential
to look at rather than the class that threw, and it costs nothing — no sync runs, nothing is
written to Commerce.

Connections are stored in the **database, not project config.** This is a deliberate break with
the usual Craft habit: project config would push a staging site's ERP credentials into production
on the next deploy, and a sandbox connection quietly becoming a live one is the worst thing this
plugin could do.

## Trying it without an ERP

A **Mock ERP** connector ships with Erpy itself. It invents deterministic data and makes no
network calls, so you can walk the whole product — connections, mapping, dry runs, the run log,
dead letters and replay — before anyone buys an ERP licence.

Make a connection, pick **Mock ERP**, and there are no credentials to fill in.

It is also the fastest way to answer "is this Erpy or is this the connector?" If the same flow
works against the Mock and fails against your ERP, the problem is in the connector or the
credentials — and that is a much shorter conversation with support.
