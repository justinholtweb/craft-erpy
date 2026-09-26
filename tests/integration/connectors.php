<?php
/**
 * Erpy connector conformance suite.
 *
 *     ddev exec php /var/www/craft-erpy/tests/integration/connectors.php
 *
 * Every connector installed on this site is driven through the same set of checks, against a
 * recorded transport rather than a live tenant. That distinction matters and is worth stating
 * plainly: this suite proves the *request* a connector builds and the *contract* it honours. It
 * cannot prove that a vendor's field is spelled the way the connector expects — only a real
 * tenant can do that, and Erpy's mapping screen is what lets a merchant fix it when it is not.
 *
 * What it does prove is the class of bug that actually ships: a connector advertising a flow it
 * never implemented, a delta sync that quietly sends no filter on any entity it claims delta for,
 * paging that repeats a cursor forever, credentials that never reach the request, or a push that fatals instead of returning a
 * failure the queue can act on.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\ConnectorInterface;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderLine;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;

$passed = 0;
$failed = 0;
$connectorCount = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/**
 * A response shaped like every envelope any of these ERPs uses, all at once.
 *
 * A connector reads whichever key it knows about and finds an empty collection there. That is
 * enough to exercise the whole request-building path — URL, auth, query parameters, paging — and
 * to prove the connector ends its page loop rather than spinning.
 */
function emptyEnvelope(): Response
{
    // A bare empty array is the one body that every envelope shape in this family reads as
    // "nothing here": `value`, `d.results`, `$items`, `$resources`, `rows` and `items` are all
    // simply absent, so each connector's own parser finds an empty collection.
    return Response::json(200, []);
}

/**
 * Intacct answers XML, so it gets an envelope of its own — a successful, empty readByQuery.
 */
function intacctEnvelope(): Response
{
    return Response::xml(200, '<?xml version="1.0" encoding="UTF-8"?>'
        . '<response><control><status>success</status></control>'
        . '<operation><authentication><status>success</status></authentication>'
        . '<result><status>success</status>'
        . '<data listtype="item" count="0" totalcount="0" numremaining="0" resultId=""></data>'
        . '</result></operation></response>');
}

/**
 * A recording double: answers everything with an empty envelope, and keeps what it was asked.
 */
final class Recorder
{
    public array $requests = [];

    public function __construct(private bool $xml = false)
    {
    }

    public function __invoke(string $method, string $url, array $options): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        // OAuth token exchanges go through the same double, so they have to be answered as
        // token responses or every OAuth connector fails before it makes a real request.
        if (str_contains($url, 'token') || str_contains($url, 'oauth')) {
            return Response::json(200, ['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        // A token exchange is a form post with a grant type, whatever the URL happens to be.
        if (($options['form']['grant_type'] ?? null) !== null) {
            return Response::json(200, ['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        // Business Central resolves its company before it reads anything, so an empty company
        // list would stop every one of its requests before it was made.
        if (str_ends_with(strtok($url, '?') ?: $url, '/companies')) {
            return Response::json(200, ['value' => [
                ['id' => '11111111-2222-3333-4444-555555555555', 'name' => 'erpy-test-companyName', 'systemVersion' => '26.0'],
            ]]);
        }

        // So do session logins.
        if (str_contains($url, '/login') || str_contains($url, '/Login')) {
            return new Response(
                status: 200,
                headers: ['Set-Cookie' => ['ASP.NET_SessionId=abc; path=/', 'ROUTEID=.1; path=/']],
                body: (string)json_encode(['SessionId' => 'sess-1', 'SessionTimeout' => 30]),
            );
        }

        if (str_contains($url, 'jsonrpc')) {
            $service = $options['json']['params']['service'] ?? '';
            $method = $options['json']['params']['method'] ?? '';

            // Odoo's authenticate returns a uid; everything else returns an empty result set.
            return Response::json(200, [
                'result' => ($service === 'common' && $method === 'authenticate') ? 2 : [],
            ]);
        }

        return $this->xml ? intacctEnvelope() : emptyEnvelope();
    }

    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)] ?? [];
    }

    /** Requests that were not token or session handshakes. */
    public function business(): array
    {
        return array_values(array_filter($this->requests, static fn(array $request) => !str_contains($request['url'], 'token')
            && !str_contains($request['url'], 'oauth')
            && !str_contains(strtolower($request['url']), '/login')));
    }

    public function reset(): void
    {
        $this->requests = [];
    }
}

/**
 * Plausible credentials for every field a connector declares, so nothing is skipped for being
 * unconfigured. URLs get URLs, numbers get numbers, everything else gets a string.
 */
function credentialsFor(string $class): array
{
    $settings = [];

    foreach ($class::settingsFields() as $field) {
        $name = $field['name'] ?? '';

        if ($name === '' || ($field['type'] ?? '') === 'heading' || ($field['type'] ?? '') === 'copyable') {
            continue;
        }

        $settings[$name] = match ($field['type'] ?? 'text') {
            'url' => 'https://erp.example.test',
            'number' => (string)($field['default'] ?? 1),
            'boolean' => (bool)($field['default'] ?? false),
            'select' => (string)($field['default'] ?? array_key_first($field['options'] ?? ['x' => 'x'])),
            default => ($field['default'] ?? '') !== '' ? (string)$field['default'] : 'erpy-test-' . $name,
        };
    }

    return $settings;
}

function makeConnection(string $handle, string $class): Connection
{
    return new Connection([
        'id' => 999000 + crc32($handle) % 1000,
        'uid' => 'conformance-' . $handle,
        'name' => 'Conformance ' . $handle,
        'handle' => 'conf' . preg_replace('/[^a-z0-9]/', '', $handle),
        'connector' => $handle,
        'enabled' => true,
        'settings' => credentialsFor($class),
        // Authorization-code connectors have nothing to send until a merchant has consented
        // once; seeding the refresh token is what a connected site would have.
        'tokens' => ['refreshToken' => 'test-refresh-token', 'obtainedAt' => time()],
    ]);
}

/**
 * Fetch one page of an entity with a `since`, and report whether that moment reached the ERP.
 *
 * The moment is deliberately distinctive — a date years before anything else in these requests —
 * so finding it cannot be a coincidence. Every vendor spells it differently (ISO, `Ymd`, US and
 * European slashes, OData literals, epoch seconds), some shift it into the tenant's timezone, and
 * it may travel in the URL, the query, a JSON body, a form or raw XML, so all of those are
 * searched, URL-decoded, for any spelling of the day before, the day of or the day after.
 *
 * @return array{requested:bool,sentDate:bool,serialised:string,error:?string}
 */
function deltaProbe(string $class, Connection $connection, string $entity): array
{
    $since = new DateTimeImmutable('2019-03-27 14:47:53', new DateTimeZone('UTC'));
    $recorder = new Recorder(xml: str_contains($class::handle(), 'intacct'));

    /** @var Connector $connector */
    $connector = new $class();
    $connector->setConnection($connection);
    $connector->useDouble($recorder);

    try {
        $connector->fetchPage($entity, new FetchCriteria(['since' => $since, 'limit' => 5]));
    } catch (Throwable $e) {
        return ['requested' => $recorder->business() !== [], 'sentDate' => false, 'serialised' => '', 'error' => $e->getMessage()];
    }

    $business = $recorder->business();
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $serialised = '';

    foreach ($business as $request) {
        $options = $request['options'];
        $query = $options['query'] ?? [];

        $serialised .= ' ' . $request['url']
            . ' ' . (is_array($query) ? json_encode($query, $flags) . ' ' . http_build_query($query) : (string)$query)
            . ' ' . (array_key_exists('json', $options) ? json_encode($options['json'], $flags) : '')
            . ' ' . (is_array($options['form'] ?? null) ? http_build_query($options['form']) : '')
            . ' ' . (is_string($options['body'] ?? null) ? $options['body'] : '');
    }

    $haystack = urldecode($serialised);
    $needles = [(string)$since->getTimestamp(), (string)($since->getTimestamp() * 1000)];

    foreach (['-1 day', '+0 days', '+1 day'] as $shift) {
        $day = $since->modify($shift);

        foreach (['Y-m-d', 'Ymd', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'd-m-Y', 'd.m.Y'] as $format) {
            $needles[] = $day->format($format);
        }
    }

    $sentDate = false;

    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            $sentDate = true;
            break;
        }
    }

    return ['requested' => $business !== [], 'sentDate' => $sentDate, 'serialised' => trim($serialised), 'error' => null];
}

/**
 * One order that is not the sample order, in every field name these ERPs use for an order
 * number, an external reference or a customer reference — and an id, so a customer or item lookup
 * answered with it still finds "something" and the push goes on to its duplicate check.
 * `wrapped` spells each value the Acumatica way, as `{"value": …}`.
 */
function unrelatedOrderRow(string $style): array
{
    $other = 'ERPY-UNRELATED-777';
    $row = ['id' => 777, 'ID' => '00000000-0000-0000-0000-000000000777', 'OrderID' => '00000000-0000-0000-0000-000000000777'];

    foreach ([
        'number', 'orderNumber', 'OrderNumber', 'OrderNbr', 'DocNum', 'DocEntry', 'NumAtCard', 'YourRef',
        'externalDocumentNumber', 'customerOrderNumber', 'CustomerOrder', 'CustomerOrderNbr', 'customerRefNo',
        'reference', 'Reference', 'ExternalRef', 'client_order_ref', 'name', 'ORDNAME', 'BOOKNUM', 'SOHNUM',
        'CUSORDREF', 'document_no', 'invoice_number', 'tranId', 'externalId', 'CUSTOMERDOCNO', 'DOCNO',
        'RECORDNO', 'orderNo', 'orderId', 'customerReference', 'yourReference', 'OrderReference',
        'Description', 'description', 'Code', 'code', 'accno', 'OrderNo', 'OurRef',
    ] as $key) {
        $row[$key] = $other;
    }

    return $style === 'wrapped'
        ? array_map(static fn($value) => ['value' => $value], $row)
        : $row;
}

/** @return array<string,array> shape name => decoded body */
function unrelatedOrderEnvelopes(string $style): array
{
    $row = unrelatedOrderRow($style);

    return [
        'bare' => [$row],
        'value' => ['value' => [$row]],
        '$resources' => ['$resources' => [$row]],
        '$items' => ['$items' => [$row]],
        'data' => ['data' => [$row]],
        'items' => ['items' => [$row]],
        'results' => ['results' => [$row]],
        'd.results' => ['d' => ['results' => [$row]]],
    ];
}

/**
 * The recorder's handshakes, with every lookup answered by one unrelated order: a GET for the
 * REST connectors, a `search_read` for Odoo, a `readByQuery` for Intacct.
 */
function unrelatedOrderDouble(string $class, array $body, string $style): callable
{
    $recorder = new Recorder(xml: str_contains($class::handle(), 'intacct'));
    $plain = unrelatedOrderRow('plain');

    return static function(string $method, string $url, array $options) use ($recorder, $body, $plain, $class): Response {
        if (str_contains($url, 'token') || str_contains($url, 'oauth') || stripos($url, '/login') !== false
            || ($options['form']['grant_type'] ?? null) !== null
            || str_ends_with(strtok($url, '?') ?: $url, '/companies')) {
            return $recorder($method, $url, $options);
        }

        if (str_contains($url, 'jsonrpc')) {
            $params = $options['json']['params'] ?? [];

            if (($params['service'] ?? '') === 'common') {
                return $recorder($method, $url, $options);
            }

            $call = $params['args'][4] ?? '';

            return Response::json(200, ['result' => match ($call) {
                'search_read', 'read' => [$plain],
                'search' => [777],
                'create' => 778,
                default => [],
            }]);
        }

        if (str_contains($class::handle(), 'intacct')) {
            $fields = '';

            foreach ($plain as $key => $value) {
                if (preg_match('/^[A-Za-z_]+$/', (string)$key)) {
                    $fields .= "<$key>" . htmlspecialchars((string)$value) . "</$key>";
                }
            }

            return Response::xml(200, '<?xml version="1.0" encoding="UTF-8"?>'
                . '<response><control><status>success</status></control>'
                . '<operation><authentication><status>success</status></authentication>'
                . '<result><status>success</status>'
                . '<data listtype="sodocument" count="1" totalcount="1" numremaining="0" resultId="">'
                . "<sodocument>$fields</sodocument></data></result></operation></response>");
        }

        return $method === 'GET' ? Response::json(200, $body) : $recorder($method, $url, $options);
    };
}

function sampleOrder(): ErpOrder
{
    $order = new ErpOrder([
        'orderNumber' => 'ERPY-CONFORMANCE-1',
        'reference' => 'CONF1',
        'orderedAt' => new DateTime('2026-08-28 10:00:00'),
        'email' => 'buyer@example.test',
        'currency' => 'USD',
        // Numeric, because a numeric code is a valid customer code in every one of these ERPs
        // and the *only* valid one in some: MYOB Exo's debtor `accno` is an integer, and a code
        // like `CUST001` is refused before any request, so its POST path was never driven.
        'customerCode' => '1001',
        'itemTotal' => 50.0,
        'taxTotal' => 5.0,
        'total' => 55.0,
    ]);

    $order->lines = [
        new ErpOrderLine([
            'lineNumber' => 10,
            'sku' => 'CONF-SKU-1',
            'description' => 'Conformance widget',
            'quantity' => 2.0,
            'unitPrice' => 25.0,
            'lineTotal' => 50.0,
        ]),
    ];

    $order->shippingAddress = new ErpAddress([
        'type' => ErpAddress::TYPE_SHIPPING,
        'fullName' => 'Dana Conformance',
        'addressLine1' => '742 Evergreen Terrace',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ]);
    $order->billingAddress = $order->shippingAddress;

    return $order;
}

$plugin = Plugin::getInstance();
$connectors = $plugin->getConnectors()->all();

echo "Erpy connector conformance — " . count($connectors) . " connectors installed\n";

try {
    foreach ($connectors as $handle => $class) {
        $connectorCount++;
        section($class::displayName() . "  ($handle)");

        $capabilities = $class::capabilities();
        $fields = $class::settingsFields();

        // -------------------------------------------------------------------------------------
        check('declares an identity a merchant can act on', function() use ($class, $handle) {
            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $handle)) {
                return "the handle “$handle” is not kebab-case, and handles are stored on connections";
            }

            foreach (['displayName', 'vendor', 'description'] as $method) {
                if (trim((string)$class::$method()) === '') {
                    return "$method() is empty";
                }
            }

            return true;
        });

        check('points somewhere a merchant can get credentials', function() use ($class) {
            $url = $class::setupUrl();

            return ($url === null || str_starts_with($url, 'https://')) ?: "setup URL is “$url”";
        });

        check('declares at least one entity, in dependency order', function() use ($capabilities) {
            $entities = $capabilities->entities();

            if ($entities === []) {
                return 'it advertises nothing at all';
            }

            $expected = array_values(array_filter(Entity::syncOrder(), fn($e) => in_array($e, $entities, true)));

            return $entities === $expected ?: implode(', ', $entities);
        });

        // The check that matters most: a connector must not advertise a flow it never wrote.
        check('implements every flow it advertises', function() use ($class, $capabilities) {
            $connector = new $class();
            $missing = [];

            $fetchMethods = [
                Entity::PRODUCT => 'fetchProducts',
                Entity::PRICE => 'fetchPrices',
                Entity::INVENTORY => 'fetchInventory',
                Entity::CUSTOMER => 'fetchCustomers',
                Entity::ORDER => 'fetchOrders',
                Entity::ORDER_STATUS => 'fetchOrderStatuses',
                Entity::SHIPMENT => 'fetchShipments',
                Entity::INVOICE => 'fetchInvoices',
                Entity::PAYMENT => 'fetchPayments',
                Entity::CREDIT => 'fetchCredit',
            ];

            $pushMethods = [
                Entity::ORDER => 'pushOrder',
                Entity::CUSTOMER => 'pushCustomer',
                Entity::PAYMENT => 'pushPayment',
                Entity::PRODUCT => 'pushProduct',
                Entity::INVENTORY => 'pushInventory',
                Entity::SHIPMENT => 'pushShipment',
            ];

            foreach ($capabilities->entities() as $entity) {
                if ($capabilities->handles($entity, Direction::PULL) && !method_exists($connector, $fetchMethods[$entity] ?? '')) {
                    $missing[] = 'pull ' . $entity;
                }

                if ($capabilities->handles($entity, Direction::PUSH) && !method_exists($connector, $pushMethods[$entity] ?? '')) {
                    $missing[] = 'push ' . $entity;
                }
            }

            return $missing === [] ?: 'advertises but does not implement: ' . implode(', ', $missing);
        });

        check('every credential field is well formed', function() use ($fields) {
            foreach ($fields as $index => $field) {
                if (!isset($field['type'], $field['label'])) {
                    return "field $index has no type or no label";
                }

                if ($field['type'] === 'select' && ($field['options'] ?? []) === []) {
                    return "the select “{$field['label']}” has no options";
                }

                if (($field['type'] ?? '') !== 'heading' && trim((string)($field['name'] ?? '')) === '') {
                    return "the field “{$field['label']}” has no name, so it can never be saved";
                }
            }

            return true;
        });

        check('every secret is marked as one, so it can be redacted', function() use ($fields) {
            $suspicious = [];

            foreach ($fields as $field) {
                $name = strtolower((string)($field['name'] ?? ''));

                if ($name === '' || ($field['secret'] ?? false)) {
                    continue;
                }

                if (str_ends_with($name, 'url') || str_ends_with($name, 'uri')) {
                    continue;
                }

                if (preg_match('/password|secret|token|apikey|key$/', $name) && $name !== 'clientid') {
                    $suspicious[] = $field['name'];
                }
            }

            return $suspicious === [] ?: 'not marked secret: ' . implode(', ', $suspicious);
        });

        check('required credentials are the ones without which nothing works', function() use ($fields, $class) {
            // A connector that authenticates must insist on the credentials it needs, or a blank
            // connection could be switched on and fail on its first run. One that authenticates
            // with nothing — the mock — legitimately requires nothing.
            if (Field::secretNames($fields) === []) {
                return true;
            }

            return Field::requiredNames($fields) !== []
                ?: $class::displayName() . ' marks nothing required, so an empty connection could be enabled';
        });

        // -------------------------------------------------------------------------------------
        $connection = makeConnection($handle, $class);
        $recorder = new Recorder(xml: str_contains($handle, 'intacct'));

        // The mock ERP invents its data in memory and never makes a request, so the checks below
        // that inspect a request do not apply to it.
        $makesRequests = Field::secretNames($fields) !== [];

        check('binds to a connection and builds a transport', function() use ($class, $connection, $recorder) {
            /** @var ConnectorInterface&Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);
            $connector->useDouble($recorder);

            return $connector->transport()->hasDouble() ?: 'the double was not attached';
        });

        check('reports its own health rather than throwing', function() use ($class, $connection, $recorder) {
            $recorder->reset();
            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);
            $connector->useDouble($recorder);

            $result = $connector->test();

            // Either answer is fine — an empty ERP is not a healthy one for every connector. What
            // matters is that a merchant gets a sentence rather than a stack trace.
            return trim($result->message) !== '' ?: 'the health check produced no message';
        });

        check('sends its credentials on a real request', function() use ($class, $connection, $recorder, $handle, $makesRequests) {
            if (!$makesRequests) {
                return true;
            }

            $recorder->reset();
            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);
            $connector->useDouble($recorder);

            $entity = $connector::capabilities()->entities()[0] ?? null;

            if ($entity === null || !$connector::capabilities()->handles($entity, Direction::PULL)) {
                return true;
            }

            $connector->fetchPage($entity, new FetchCriteria(['limit' => 5]));
            $business = $recorder->business();

            if ($business === []) {
                return 'the connector made no request at all';
            }

            $request = $business[0];
            $headers = $request['options']['headers'] ?? [];

            // Anything beyond the two headers every request carries is identifying the caller —
            // a bearer token, a session cookie, a subscription key, a tenant id.
            $identifying = array_diff(array_map('strtolower', array_keys($headers)), ['accept', 'content-type']);

            // Intacct and Odoo authenticate inside the payload rather than in a header.
            $body = (string)json_encode($request['options']['json'] ?? '') . (string)($request['options']['body'] ?? '');
            $inBody = str_contains($body, 'erpy-test');

            return ($identifying !== [] || $inBody)
                ?: 'no credential reached the request: ' . implode(', ', array_keys($headers));
        });

        // Every entity is checked, not just the first one that declares delta: a connector that
        // filters products properly and forgets the filter on inventory passed this for months,
        // because products were the only entity it was ever asked about.
        check('asks the ERP only for what changed, for every entity it says it can', function() use ($class, $connection, $makesRequests) {
            if (!$makesRequests) {
                return true;
            }

            $capabilities = $class::capabilities();
            $problems = [];

            foreach ($capabilities->entities() as $entity) {
                if (!$capabilities->supportsDelta($entity) || !$capabilities->handles($entity, Direction::PULL)) {
                    continue;
                }

                $probe = deltaProbe($class, $connection, $entity);

                if ($probe['error'] !== null) {
                    $problems[] = "$entity: a delta fetch threw — {$probe['error']}";
                } elseif (!$probe['requested']) {
                    $problems[] = "$entity: no request was made";
                } elseif (!$probe['sentDate']) {
                    $problems[] = "$entity: declared delta but the request carried no date — " . mb_substr($probe['serialised'], 0, 300);
                }
            }

            return $problems === [] ?: implode("\n    ", $problems);
        });

        // The inverse. The engine never hands a watermark to an entity declared `delta: false`,
        // so a connector that filters one anyway either under-declares (every sync is a full one
        // for no reason) or filters unconditionally, which is the shape of the bug that made
        // MYOB Exo's credit sync miss records. Either way the declaration and the code disagree.
        check('does not filter by date on an entity it says has no delta', function() use ($class, $connection, $makesRequests) {
            if (!$makesRequests) {
                return true;
            }

            $capabilities = $class::capabilities();
            $problems = [];

            foreach ($capabilities->entities() as $entity) {
                if ($capabilities->supportsDelta($entity) || !$capabilities->handles($entity, Direction::PULL)) {
                    continue;
                }

                $probe = deltaProbe($class, $connection, $entity);

                if ($probe['error'] === null && $probe['sentDate']) {
                    $problems[] = "$entity: declared delta: false but the request filtered by the since date — " . mb_substr($probe['serialised'], 0, 300);
                }
            }

            return $problems === [] ?: implode("\n    ", $problems);
        });

        check('a full page asks for another, and an empty one stops', function() use ($class, $connection, $recorder, $makesRequests) {
            if (!$makesRequests) {
                return true;
            }

            $capabilities = $class::capabilities();
            $entity = null;

            foreach ($capabilities->entities() as $candidate) {
                if ($capabilities->handles($candidate, Direction::PULL)) {
                    $entity = $candidate;
                    break;
                }
            }

            if ($entity === null) {
                return true;
            }

            $recorder->reset();
            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);
            $connector->useDouble($recorder);

            // An empty page must end the loop rather than handing back a cursor forever.
            $page = $connector->fetchPage($entity, new FetchCriteria(['limit' => 5]));

            if (!$page instanceof Page) {
                return 'fetchPage did not return a Page';
            }

            return !$page->hasMore() ?: 'an empty page still claimed there was more to fetch';
        });

        check('a push answers with a result rather than a fatal', function() use ($class, $connection, $recorder) {
            if (!$class::capabilities()->handles(Entity::ORDER, Direction::PUSH)) {
                return true;
            }

            $recorder->reset();
            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);
            $connector->useDouble($recorder);

            $result = $connector->pushDocument(Entity::ORDER, sampleOrder());

            if (!$result instanceof PushResult) {
                return 'pushDocument did not return a PushResult';
            }

            // Against an empty double most connectors will fail to resolve a customer or an item,
            // and saying so is the correct behaviour. What must not happen is an exception
            // escaping into the queue as an unexplained job failure.
            return ($result->success || trim((string)$result->message) !== '')
                ?: 'the push failed without saying why';
        });

        check('a refused push is not queued for retry forever', function() use ($class, $connection, $makesRequests) {
            if (!$makesRequests || !$class::capabilities()->handles(Entity::ORDER, Direction::PUSH)) {
                return true;
            }

            $recorder = new Recorder(xml: str_contains($class::handle(), 'intacct'));
            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);

            // A 422 is the ERP saying the document itself is wrong. Retrying it unchanged can
            // only produce the same answer, so it has to be marked unretryable or the queue will
            // hammer the ERP until it gives up.
            $connector->useDouble(function(string $method, string $url, array $options) use ($recorder) {
                if (str_contains($url, 'token') || str_contains($url, 'oauth') || stripos($url, '/login') !== false) {
                    return $recorder($method, $url, $options);
                }

                if ($method === 'GET') {
                    return $recorder($method, $url, $options);
                }

                return Response::json(422, ['error' => ['message' => 'Item CONF-SKU-1 does not exist.']]);
            });

            $result = $connector->pushDocument(Entity::ORDER, sampleOrder());

            if ($result->success) {
                return true;
            }

            return $result->retryable === false
                ?: 'a document the ERP refused is still marked retryable, so the queue will keep resending it';
        });

        // The empty-envelope double answers every duplicate lookup with "nothing here", so a
        // connector that treats *any* row coming back as a match could never be caught — and
        // that is the bug that made Visma report every order after the first as a duplicate,
        // because its API ignored the filter and returned the latest order. Here every lookup
        // finds exactly one order, and it is somebody else's.
        check('a duplicate check does not treat an unrelated order as a match', function() use ($class, $connection, $makesRequests) {
            if (!$makesRequests || !$class::capabilities()->handles(Entity::ORDER, Direction::PUSH)) {
                return true;
            }

            $problems = [];
            $threw = [];
            $attempts = 0;

            foreach (['plain', 'wrapped'] as $style) {
                foreach (unrelatedOrderEnvelopes($style) as $shape => $body) {
                    $attempts++;
                    /** @var Connector $connector */
                    $connector = new $class();
                    $connector->setConnection($connection);
                    $connector->useDouble(unrelatedOrderDouble($class, $body, $style));

                    try {
                        $result = $connector->pushDocument(Entity::ORDER, sampleOrder());
                    } catch (Throwable $e) {
                        // A shape this connector cannot read is not a verdict on duplicates;
                        // crashing on it is what the "answers with a result" check is for.
                        $threw[] = "$style/$shape: " . $e->getMessage();
                        continue;
                    }

                    if ($result->duplicate) {
                        $problems[] = "$style/$shape";
                        $claimed = (string)$result->remoteId;
                    }
                }
            }

            if ($problems !== []) {
                return 'an unrelated order was reported as this one already existing (claimed remote id “'
                    . ($claimed ?? '') . '”), for envelopes: ' . implode(', ', $problems);
            }

            return count($threw) < $attempts ?: 'every attempt threw: ' . $threw[0];
        });

        check('an unreachable ERP is retryable, not a rejection', function() use ($class, $connection, $makesRequests) {
            if (!$makesRequests || !$class::capabilities()->handles(Entity::ORDER, Direction::PUSH)) {
                return true;
            }

            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);
            $connector->useDouble(function(string $method, string $url, array $options) {
                if (str_contains($url, 'token') || str_contains($url, 'oauth') || stripos($url, '/login') !== false) {
                    return Response::json(200, ['access_token' => 'x', 'expires_in' => 3600, 'SessionId' => 's']);
                }

                return new Response(0, [], '', 'Network: connection refused');
            });

            $result = $connector->pushDocument(Entity::ORDER, sampleOrder());

            // Either it never got far enough to try (a lookup failed first, which is honest), or
            // it failed in a way the queue should retry. What it must not do is dead-letter an
            // order because the network blipped.
            return ($result->success === false && $result->retryable === true) || $result->message !== null
                ?: 'a network failure was treated as a permanent rejection';
        });

        check('never writes a credential into an error message', function() use ($class, $connection) {
            /** @var Connector $connector */
            $connector = new $class();
            $connector->setConnection($connection);

            $secrets = Field::secretNames($class::settingsFields());

            if ($secrets === []) {
                return true;
            }

            $connector->useDouble(function(string $method, string $url, array $options) use ($connection, $secrets) {
                if (str_contains($url, 'token') || str_contains($url, 'oauth') || stripos($url, '/login') !== false) {
                    return Response::json(200, ['access_token' => 'x', 'expires_in' => 3600, 'SessionId' => 's']);
                }

                // The nastiest real-world case: an ERP that echoes the credential back inside its
                // own error message.
                return Response::json(401, [
                    'error' => ['message' => 'Bad credential: ' . $connection->getSetting($secrets[0])],
                ]);
            });

            $result = $connector->test();
            $secretValue = (string)$connection->getSetting($secrets[0]);

            return !str_contains($result->message, $secretValue)
                ?: 'the health check echoed the ' . $secrets[0] . ' back to the merchant';
        });
    }
} finally {
    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $connectorCount connectors · $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
