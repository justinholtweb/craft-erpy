---
title: Connectors
slug: connectors
order: 30
summary: The supported ERPs, why the add-ons are free, and how to write one for an ERP that is not on the list.
---

# Connectors

Each ERP is a separate **free add-on plugin** that registers a connector with Erpy. Install the
one for your ERP and it appears in the connector list.

| ERP | Package | Plugin handle |
|---|---|---|
| [Microsoft Dynamics 365 Business Central](/plugins/craft-erpy/docs/business-central) | `justinholtweb/craft-erpy-businesscentral` | `erpy-businesscentral` |
| [Oracle NetSuite](/plugins/craft-erpy/docs/netsuite) | `justinholtweb/craft-erpy-netsuite` | `erpy-netsuite` |
| [Acumatica](/plugins/craft-erpy/docs/acumatica) | `justinholtweb/craft-erpy-acumatica` | `erpy-acumatica` |
| [SAP Business One](/plugins/craft-erpy/docs/sap-business-one) | `justinholtweb/craft-erpy-sapb1` | `erpy-sapb1` |
| [Sage Intacct, Sage 200, Sage X3, Sage Accounting](/plugins/craft-erpy/docs/sage) | `justinholtweb/craft-erpy-sage` | `erpy-sage` |
| [Odoo](/plugins/craft-erpy/docs/odoo) | `justinholtweb/craft-erpy-odoo` | `erpy-odoo` |
| [Exact Online](/plugins/craft-erpy/docs/exact-online) | `justinholtweb/craft-erpy-exactonline` | `erpy-exactonline` |
| [AFAS Profit](/plugins/craft-erpy/docs/afas) | `justinholtweb/craft-erpy-afas` | `erpy-afas` |
| [Visma.net ERP](/plugins/craft-erpy/docs/visma) | `justinholtweb/craft-erpy-visma` | `erpy-visma` |
| [MYOB Acumatica, MYOB Exo](/plugins/craft-erpy/docs/myob) | `justinholtweb/craft-erpy-myob` | `erpy-myob` |
| [Unit4 ERP](/plugins/craft-erpy/docs/unit4) | `justinholtweb/craft-erpy-unit4` | `erpy-unit4` |
| [Priority](/plugins/craft-erpy/docs/priority) | `justinholtweb/craft-erpy-priority` | `erpy-priority` |

Each ERP's page covers what its connector syncs, the credentials it asks for and where to find
them, and the vendor's own traps. The add-ons have no documentation of their own; it all lives here.

One package may register several connectors — Sage registers four, MYOB two.

Two connectors ship with Erpy itself:

- **[File / URL exchange](../files)**, for an ERP that has no API but exchanges files. It reads CSV,
  XML or JSON from a folder, a URL, SFTP or an upload. It writes orders as files, or serves them at
  a URL the ERP fetches.
- **Mock ERP**, which makes no network calls and invents deterministic data. See
  [Installation](../installation).

## Why the add-ons are free

A connector's whole job is to turn one page of one vendor's payloads into canonical documents. It
does not queue, retry, page, dedupe, map, log, schedule or write to Commerce — because Erpy does,
once, for all of them. There is not enough left in a connector to charge for, and pricing them
separately would mean a merchant on an unusual ERP paying more for less.

It is the same arrangement Imager-X uses for its transformers, for the same reason: the
interesting work is not in any one integration.

## What a connector declares

Every connector publishes a capability list, and Erpy's screens are built from it. That is why
you are never offered a direction your ERP cannot do.

```php
public static function capabilities(): Capabilities
{
    return Capabilities::make()
        ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 200)
        ->supports(Entity::ORDER, Direction::PUSH);
}
```

**Whatever it declares, it must implement.** Erpy ships a conformance suite — a TCK — that drives
every installed connector through the same checks against a recorded transport. It is what catches
a connector advertising a flow it never wrote, a delta sync sending no filter, paging that repeats
a cursor, credentials that never reach the request, a refusal marked retryable so the queue
hammers the ERP, and a credential echoed back to the merchant.

## Writing one

A connector is one class. Register it and it appears everywhere — the connection form, the
capability matrix, the mapping screen, the conformance suite.

```php
Event::on(
    Connectors::class,
    Connectors::EVENT_REGISTER_CONNECTORS,
    static function(RegisterComponentTypesEvent $event) {
        $event->types[] = MyErpConnector::class;
    },
);
```

```php
class MyErpConnector extends Connector
{
    public static function handle(): string { return 'my-erp'; }
    public static function displayName(): string { return 'My ERP'; }
    public static function vendor(): string { return 'Acme'; }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::ORDER, Direction::PUSH);
    }

    public static function settingsFields(): array
    {
        return [
            Field::url('baseUrl', 'API URL', ['required' => true]),
            Field::secret('apiKey', 'API key', ['required' => true]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new ApiKeyAuth();
    }

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        $response = $this->transport()->get('items', ['limit' => $criteria->limit]);

        return new Page(
            array_map(fn(array $row) => new ErpProduct([...]), $response->at('items', [])),
            $response->at('nextCursor'),
        );
    }

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        // …
    }
}
```

Erpy provides the auth strategies most ERPs need — API key, HTTP basic, session, OAuth 1.0a
two-legged, OAuth 2 client credentials and authorization code — so a connector picks one rather
than writing a token refresh.

### Settings per entity

`settingsFields()` describes the connection. When something belongs to one entity, such as which
file holds the stock list or which ERP view to read customers from, declare it in
`entityFields()`. Erpy renders it under that entity's row on the connection screen, and the
connector reads it from `$criteria->filters`:

```php
public static function entityFields(string $entity): array
{
    return [Field::text('view', 'ERP view', ['placeholder' => 'WebItems'])];
}

protected function fetchProducts(FetchCriteria $criteria): Page
{
    $view = $criteria->filters['view'] ?? 'Items';
    // …
}
```

A field can carry `'when' => ['someSetting' => ['a', 'b']]` to appear only for those values of a
connection setting.

### A record you cannot read

When the ERP returns something that can't become a document, such as a malformed row or a record
with the wrong shape, don't throw and don't drop it silently. Report it on the page:

```php
$page->reject($bestGuessAtTheNaturalKey, 'Row 57 has 4 columns; the header has 6.', $raw);
```

Erpy records it as a failed item and a dead letter, and the rest of the page is still applied. A
later sync that brings the same key back successfully clears the dead letter.

### An ERP that collects documents itself

Some ERPs don't take orders. They come and fetch them. A connector answering `true` from
`deliversOnRequest(Entity::ORDER)` is never handed an order at checkout, by a retry or by the
console. Its orders are still delivered through `Push::deliver()`, but only on the export trigger.
The File / URL exchange connector's export URL works this way.

### The transport redacts secrets

An ERP will echo your credential back inside its own error message. Erpy's transport strips
secrets out of non-2xx bodies before a connector — or a merchant's health screen — ever sees them.
Success bodies are left alone, because connectors have to parse them.

Logged bodies get more. Fields with credential names, such as `access_token`, `refresh_token`,
`SessionId`, `apiKey` and `password`, are masked in JSON, form and XML bodies, so a login response
never reaches the log with its token. Any value your auth strategy puts on a request is also
redacted wherever the ERP echoes it back. A credential your connector handles itself, outside an
auth strategy, can be registered with `$this->transport()->addSecretValue($token)`.

### The transport does not repeat a POST

`GET`, `PUT` and `DELETE` are retried on a timeout, a 408, a 429 or a 5xx. `POST` and `PATCH` are
retried only when the ERP provably didn't act on them: the connection never opened, or the answer
was 425 or 429. A `POST` that times out after it was written, or that a proxy answers 502 or 504,
may already have created the order, so it comes back to your connector as a failure. Return a
retryable `PushResult` and the queue tries again later, after the duplicate check in your
`pushOrder()` has looked for the order.

If your ERP de-duplicates on its own, opt the request in:

```php
$this->transport()->post('salesorders', $payload, ['retry' => true]);
$this->transport()->post('salesorders', $payload, ['headers' => ['Idempotency-Key' => $document->reference]]);
```

`'retry' => false` turns retrying off for any request.

## When the connector's field name is wrong

Several of these ERPs are configured per customer: [AFAS](/plugins/craft-erpy/docs/afas),
[Priority](/plugins/craft-erpy/docs/priority), [Unit4](/plugins/craft-erpy/docs/unit4) and a
published [Business Central](/plugins/craft-erpy/docs/business-central) page can all name the same
concept differently on two tenants. A connector cannot be right
about that in advance.

That is what the mapping overlay is for. A mapping rule whose target is a **canonical** field
overwrites what the connector read, before the document reaches Commerce — so a wrong field name
is an afternoon's fix rather than a bug report. See [Field mapping](../mapping).
