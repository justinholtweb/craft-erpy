# Erpy — Craft CMS 5 Plugin

## Project Overview

Erpy is an **ERP gateway for Craft Commerce**. It owns the sync engine, the identity map, field
mapping, the connection log and the control panel; each ERP is a separate **free add-on plugin**
that registers a connector. Distributed as `justinholtweb/craft-erpy`. **$149, $129 renewal.**
Single edition — there is no Lite.

The add-on model is deliberately the Imager-X one: a paid host plugin with free extension
packages, registered through a `RegisterComponentTypesEvent`.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks

## Architecture

### Namespace & package

- Namespace: `justinholtweb\erpy`
- Package: `justinholtweb/craft-erpy`
- Handle: `erpy`

Add-ons: `justinholtweb/craft-erpy-<vendor>`, namespace `justinholtweb\erpy<vendor>`, handle
`erpy-<vendor>`. One package may register several connectors — Sage registers four, MYOB two.

### The two invariants

1. **`services\Sync::run()` is the only place an inbound sync happens.** The CP button, the queue
   job, the scheduled run, the console command and a webhook all end there, so paging, delta
   watermarks, the identity map, dry runs, dead letters and the run log cannot behave differently
   depending on how a sync was started.
2. **`services\Push::deliver()` is the only place an outbound document reaches an ERP**, and
   `services\Orders::build()` is the only place a Commerce order becomes one. That is what makes
   the CP's payload preview trustworthy: it is not a rendering of what would be sent, it is the
   thing that gets sent.

### Data model

- `{{%erpy_links}}` — the identity map. Unique on `(connectionId, entity, naturalKey)`; **that
  index is the duplicate-order guarantee**, not a remembered check. Shipment rows carry a
  `quantity` so partial fulfilment is answerable across several deliveries.
- `{{%erpy_prices}}` — contract pricing, resolved at cart time rather than mirrored into
  Commerce's catalog pricing rules. A mid-market ERP holds tens of thousands of price lines; one
  pricing rule per line would make catalog price generation the slowest thing on the site.
- `{{%erpy_accounts}}` — a Craft user's B2B standing: price list, terms, credit limit, balance.
- `{{%erpy_connections}}`, `_maps`, `_runs`, `_runitems`, `_log`, `_deadletters`, `_cursors`.

Connections live in the **database, not project config**. That is a considered break with the
family habit: project config would push a staging site's ERP credentials into production on the
next deploy, and a sandbox connection quietly becoming a live one is the worst thing this plugin
could do.

Nothing has a foreign key to a Commerce or Craft element except `erpy_accounts.userId`. Elements
get deleted, and losing a sync history because somebody tidied up a product is worse than holding
an id that no longer resolves.

### Credentials (5.1.1)

- `helpers\Secret` encrypts with the security key (`enc:` + base64). The `tokens` column is one
  encrypted JSON bag; literal secret settings are encrypted per value, `$ENV` references stored as
  written. Every reader goes through `Secret::decrypt`/`decodeTokens`, which still accept a
  plaintext legacy row — `m261005_000000_encrypt_credentials` encrypts those.
- **Repointing is admin-only.** A field declared with `Field::url()` (or `'endpoint' => true`) is
  an endpoint. `ConnectionsController::requireAdminToRepoint()` refuses a non-admin who changes the
  connector or any endpoint; `Connections::mergeSettings()` never carries a blank secret over a
  repoint, and `save()` drops the tokens. Otherwise "Manage connections" + Test connection sends
  the stored key to any host.
- Webhooks: POST only, secret in `X-Erpy-Secret`, never the query string.

### Delta watermarks

The watermark advances to the **start** of a run, never to the end, plus a two-minute overlap. A
record modified while a run is in flight would otherwise fall in the gap and never be seen again.
Re-reading a few records costs one content-hash comparison; missing one costs a wrong price. A
failed run does not move the watermark at all.

### Mapping can correct the connector

A mapping rule whose target is a **canonical** field (`sku`, `unitPrice`, `customerCode`) is not a
Craft mapping — it overwrites what the connector read, before the document reaches Commerce.
`Mapping::overlay()` does that and coerces to the property's declared type; `Mapping::apply()`
skips those rules so they are not also looked for as Craft fields. This is what makes a
connector's wrong field name an afternoon's fix rather than a bug report, and it is essential for
the ERPs whose API is configured per customer (AFAS, Priority, Unit4, a published BC page).

### Fail-open rules

- Order push is **always queued**, never inline. An ERP having a slow afternoon must not delay a
  customer's confirmation, and an ERP being down must not fail a sale.
- Contract pricing runs on every cart request; a bad lookup falls back to Commerce's own price
  rather than taking the storefront down.
- A log write must never be the reason a sync fails.

## Failure alerts (reference for the connector family)

Built in Erpy first (2026-10-09) as the Theme 1 reference; my, lexies, zo, bird, sager, vismaz and
exactly copy it. There is no shared package — copy the files and change the namespace.

**What it does.** Three incidents per connection: *dead letters* (N failures in a window, closes
after a whole quiet window: hysteresis), *auth* (a final 401 or a refused OAuth grant, pushed as a
signal and cleared by the next authenticated 2xx), *stalled* (no successful pull of a scheduled
entity for `max(alertStallHours, 2 × interval)`). Each is one latch row, unique on
`(connectionId, incident)`: one message when it opens, one when it clears, `quietUntil` holds a
reopening inside `alertCooldownMinutes`. A send is *claimed* with a conditional UPDATE and released
if every channel failed, so two checkers cannot both send and a mail outage delays rather than
loses. Disabled connections are skipped, never "recovered".

**Files to copy:**

| File | Adapt |
|---|---|
| `src/services/Alerts.php` | the three `measure()` cases to your plugin's tables (dead letters / failed-sync log, run history, intervals); `redact()`'s secret lookup; CP URLs in `compose()` |
| `src/events/AlertEvent.php` | namespace |
| `src/helpers/Ip.php` | namespace only — keep identical to craft-eye/Control Tower/Fjord |
| `src/migrations/m261009_000000_alerts.php` | table name + FK target; call `createAlertsTable()` from `Install` too; bump `schemaVersion` |
| `src/widgets/HealthWidget.php` + `src/templates/_widgets/health.twig` | permission, columns |
| `src/console/controllers/AlertsController.php` | handle (`<plugin>/alerts/check`, `/test`) |
| `src/controllers/AlertsController.php` | admin-only "Send a test alert"; reads **saved** settings, never a URL from the request |
| `Settings` alert block + `recipientList()`/validators, and the settings-template section | nothing `required`; `$ENV` via `autosuggestField` |
| `tests/integration/alerts.php` | fixture connection, how a dead letter / 401 / run is produced |

**Hooks to add:** `Alerts::afterRun()` where a run finishes (here `Runs::finish()`);
`noteAuthFailure()`/`noteAuthSuccess()` where the HTTP client sees a final 401 / an authenticated
2xx (here `Transport::signalAuth()`) and where an OAuth grant is refused (`BaseAuth::grantRefused()`,
4xx only — status 0 is the network, not auth); `check()` from the plugin's scheduler/cron command
(here `erpy/sync/due` and `ScheduledSyncJob`). Register the widget and the `alerts` component.
A single-connection plugin (most of the family) uses a pseudo-connection id or drops the column.

**Do not change when porting:** the conditional-UPDATE claim/release, redaction before anything
leaves the site, the webhook through `webhookTarget()` (scheme, no userinfo, `Ip::resolvePublic`,
`CURLOPT_RESOLVE` pin, `allow_redirects => false`, curl-only client), the HMAC header when a secret
is set, and every alert path being fail-open (a sync must never fail because an alert could not).

**Known gap found here:** nothing in Erpy queues `ScheduledSyncJob` (it is defined, never pushed),
so `scheduleEnabled` alone does not schedule anything — `erpy/sync/due` on cron is what runs.
The stall alert is what would tell a merchant that.

## Traps found while building this

- **Twig cannot call a static method on a class name.** Passing a `class-string` to a template and
  writing `class.displayName()` throws "Impossible to invoke a method on a string variable".
  `Connectors::describe()` exists so templates only ever see plain arrays.
- **A Craft model must declare every column its table returns**, or hydrating it throws
  `UnknownPropertyException`. Cost four separate failures here (`sortOrder`, `dateUpdated`, `uid`).
- **`['like', '…']` is not an element-query parameter.** Craft treats the array as a value set, so
  the query silently matches nothing.
- **Craft stores datetimes as bare UTC strings.** `new DateTime($row['watermark'])` reads them as
  site-local and shifts every comparison by the site's offset; name the timezone.
- **Commerce 5 attaches an inactive user to every guest checkout**, so `Order::getCustomer()` being
  non-null does not mean anybody registered. `active` is the flag that separates them.
- **A variant Erpy creates has to be told to track inventory**, or the first stock sync skips it.
- **`craft\base\Plugin::settingsResponse()` namespaces the settings template's inputs**, so a field
  named `settings[foo]` posts as `settings[settings][foo]` and saves nothing while reporting
  success. Settings fields carry no prefix.
- **An ERP will echo your credential back inside its own error message.** The transport now
  redacts secrets out of non-2xx bodies before a connector — or a merchant's health screen — sees
  them. Success bodies are left alone because connectors have to parse them.
- **A curly quote straight after an interpolated variable eats it**: `"“$handle”"` interpolates
  `$handle”`, an undefined variable — which turned the webhook's 403 into a 500. Fourteen of them
  shipped. Always brace: `{$handle}`. phpstan catches it.

Vendor-specific traps live in each connector's class docblock, where somebody debugging that ERP
will actually find them: NetSuite's three spellings of an account id, SAP B1's `tNO` string
booleans and split date/time columns, Acumatica's `{"value": …}` wrappers, Odoo answering every
error with HTTP 200, Exact's rotating refresh tokens, AFAS's base64-wrapped XML token, Intacct's
two sets of credentials and `resultId` paging.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-erpy/tests/integration/checks.php      # 144 engine checks
ddev exec php /var/www/craft-erpy/tests/integration/connectors.php  # 306 conformance checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/security.php  # 12: encryption at rest, repoint guard over HTTP, webhook
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/alerts.php    # failure alerts: latch, mail, SSRF, webhook, widget, test action over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/replay.php    # 9: dead-letter retry never double-books (MockHandler ERP, POSTs counted per endpoint)
docker exec -w /sites/craft-erpy ddev-phpstan-runner-web bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
ddev exec bash -c 'find /var/www/craft-erpy/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`checks.php` runs the whole engine against the built-in Mock ERP, so a green run means paging,
delta watermarks, the identity map, mapping, dry runs, dead letters and replay actually work.

`connectors.php` is the **conformance suite** — a TCK. Every installed connector is driven through
the same checks against a recorded transport. It proves the request a connector builds and the
contract it honours; it cannot prove a vendor's field is spelled the way the connector expects.
Only a live tenant can do that, which is what the mapping overlay is for. What it does catch is
the class of bug that ships: a connector advertising a flow it never implemented (it found eight),
a delta sync sending no filter on *any* entity declared delta (and a non-delta entity that filters
anyway), paging that repeats a cursor, credentials that never reach the request, a refusal marked
retryable so the queue hammers the ERP, a credential echoed back to the merchant, and a
duplicate-order lookup that trusts the first row it gets back. That last one found ten: an ERP
that ignores the lookup's filter would otherwise make every order after the first "already
delivered" and never create it. A lookup must compare the returned reference exactly, using the
same helper that produced the value it wrote.

**Harness note:** `craft-penny` breaks every element save and `craft-lyfe` breaks
`markAsComplete()` in this shared harness. Both are detached in-process by `checks.php`; neither
is a bug in Erpy.

## Coding conventions

- `Craft::t('erpy', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- A connector implements exactly what its `capabilities()` declares, and the conformance suite
  enforces it
- Anything that runs during checkout fails **open**
