---
title: Alerts
slug: alerts
order: 55
summary: One email (and optionally a Slack or Teams message) when a connection is in trouble, one when it recovers — and a Dashboard widget.
---

# Alerts

The Problems screen knows everything that has gone wrong, but nobody opens an integration's admin
screen on a day it seems to be working. Erpy tells you instead, when one of three things happens
on an enabled connection:

| Incident | Opens when | Clears when |
|---|---|---|
| **Documents failing** | `alertDeadLetterThreshold` documents (default 5) land on the Problems screen within `alertDeadLetterWindowMinutes` (default 60) | a whole window passes with none |
| **Authentication failed** | the ERP answers 401 after Erpy has already tried to re-authenticate, or refuses an OAuth token refresh or client-credentials grant | the next authenticated request succeeds |
| **Scheduled sync stalled** | an entity with an interval has had no successful pull for `alertStallHours` (default 6), or for twice its own interval if that is longer | it syncs successfully again — by schedule, cron or the button |

You get **one message when an incident starts and one when it clears**, never one per failure.
Each connection and incident type has a single latch row in the database: checking it a hundred
times while it is still open sends nothing new. A connection that keeps flapping is held by
`alertCooldownMinutes` (default 60): if it reopens within that long of its recovery message, you
hear about it when the quiet period ends, and only if it is still happening.

A network failure is not an authentication failure, and a 500 is not one either. Those retry, and
if they keep failing they become dead letters, which is the first incident.

## Setting it up

**Erpy → Settings → Alerts.**

| Setting | Default | |
|---|---|---|
| `alertRecipients` | empty | Addresses separated by commas, or an `$ENV` reference. Empty means no email |
| `alertWebhookUrl` | empty | A Slack or Teams incoming-webhook URL, or an `$ENV` reference. Keep it in an environment variable: the URL is the credential |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, or `json` for your own receiver |
| `alertWebhookSecret` | empty | When set, each webhook carries `X-Erpy-Timestamp` and `X-Erpy-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnDeadLetters` | on | |
| `alertDeadLetterThreshold` | 5 | |
| `alertDeadLetterWindowMinutes` | 60 | |
| `alertOnAuthFailure` | on | |
| `alertStallHours` | 6 | 0 switches stall alerts off |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | off | Config file only — see below |

Mail goes through Craft's own mailer, so it uses whatever **Settings → Email** is set to. Press
**Send a test alert** on the settings screen (or run `php craft erpy/alerts/test`) after saving to
check that both channels arrive.

Nothing here is required. A site with no recipients and no webhook still records incidents, and
shows them on the Dashboard widget. If you add a recipient later, any incident that is still open
is sent at the next check.

## When alerts are checked

- **At the end of every run**, sync or order push: dead letters and stalls. No cron needed.
- **The moment the ERP refuses the credentials**: authentication.
- **`php craft erpy/sync/due`**, after its syncs, and **`php craft erpy/alerts/check`** on its
  own: everything, for every enabled connection.

A stall is the absence of runs, so only the last of these can notice one. If your schedule runs
from cron, `erpy/sync/due` already covers it. If it does not, add the check:

```sh
*/15 * * * * cd /path/to/site && php craft erpy/alerts/check >> /dev/null 2>&1
```

A disabled connection is never checked. Switching one off is neither a new incident nor a
recovery.

## What an alert says

A plain-text email: the site, the connection, the incident, what was seen, and links straight to
the right screen — the connection for authentication, Activity for a stall, and the Problems
screen filtered to that connection for everything. The Slack message, Teams card and JSON event
carry the same.

What was seen is redacted before it leaves the site: the connection's secret settings and OAuth
tokens are removed by value, anything shaped like a credential (`Bearer …`, `password=…`,
`"client_secret": …`) by pattern, markup is stripped and the line is capped at 500 characters.
Alerts never include a document, a customer or an order.

## The webhook

Slack and Teams incoming webhooks work as they are. The `json` format posts:

```json
{
  "event": "erpy.alert.opened",
  "incident": "auth",
  "connection": "acme",
  "site": "My Store",
  "title": "Authentication failed on Acme ERP",
  "detail": "The ERP refused the credentials: HTTP 401",
  "url": "https://example.com/admin/erpy/connections/3",
  "problemsUrl": "https://example.com/admin/erpy/problems?connection=3",
  "at": "2026-10-09T08:15:00+00:00"
}
```

`event` is `erpy.alert.recovered` when it clears.

The URL is checked every time it is used, not only when it is saved. It must be `http` or
`https` with no username or password in it, every address the host resolves to must be public —
not private, loopback, link-local (the cloud metadata service) or carrier-grade NAT — and the
request is pinned to those addresses so DNS cannot be switched between the check and the send.
Redirects are never followed. For a self-hosted Mattermost on your own network, set
`allowPrivateAlertWebhookHosts` in `config/erpy.php`. The scheme and redirect rules still apply.

If every channel fails, the alert is not marked sent, and the next check tries again.

## The Dashboard widget

**Dashboard → New widget → ERP health** shows each connection with its latest run, its unresolved
problems, and any open incident. Hover an incident to see what was seen. It reads the same latch
rows the alerts come from, so the widget and your inbox cannot disagree. Only people with *View
sync activity and problems* can add it.

## Changing or suppressing an alert

```php
use justinholtweb\erpy\events\AlertEvent;
use justinholtweb\erpy\services\Alerts;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->connection, $e->incident, $e->recovered, $e->detail
    $e->subject = '[ERP] ' . $e->subject;

    // Swallow it. The latch still counts it as sent.
    if ($e->connection?->handle === 'sandbox') {
        $e->isValid = false;
    }
});
```
