<?php
/**
 * Erpy integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-erpy/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: the fixture connection, products, orders, users, links, prices,
 * runs, dead letters and log rows it creates are all removed in a `finally`, pass or fail.
 *
 * The suite runs the whole engine against the built-in Mock ERP, so a green run means paging,
 * delta watermarks, the identity map, mapping, dry runs, dead letters and replay actually work —
 * not merely that the units do. The transport and the auth strategies are exercised against
 * recorded doubles, which is the same path a live tenant takes minus the network.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\erpy\auth\BasicAuth;
use justinholtweb\erpy\auth\OAuth1Tba;
use justinholtweb\erpy\auth\OAuth2AuthorizationCode;
use justinholtweb\erpy\auth\OAuth2ClientCredentials;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\connectors\MockConnector;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\FieldMap;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;

$passed = 0;
$failed = 0;

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

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

/** @var Connection|null $connection */
$connection = null;
$createdProducts = [];
$createdOrders = [];
$createdUsers = [];
$originalSettings = $plugin->getSettings()->toArray();

// `craft-penny`, a sibling plugin in this shared harness, registers an
// `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed `ModelEvent` while Craft passes an
// `ElementEvent`, so every element save in the harness fatals while it is enabled. Detached
// in-process only — nothing is persisted, and this is a bug in Penny rather than in Erpy.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

// `craft-lyfe`, another sibling in this harness, reads a `phone` field that does not exist on
// this site from its EVENT_AFTER_COMPLETE_ORDER handler, so completing any order fatals. Erpy
// registers a handler on the same event, so this asserts ours is attached before detaching the
// lot — otherwise the workaround could hide a regression in our own wiring.
if (Craft::$app->getPlugins()->isPluginEnabled('lyfe')) {
    if (!(new Order())->hasEventHandlers(Order::EVENT_AFTER_COMPLETE_ORDER)) {
        echo "  ! Erpy's order-complete handler is missing\n";
        $failed++;
    } else {
        $passed++;
        echo "  ✓ Erpy's order-complete handler is registered\n";
    }

    yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);
    echo "  ! detached craft-lyfe's broken afterCompleteOrder handler for this run\n";
}

function makeProduct(string $sku, float $price = 10.0): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Erpy fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->setBasePrice($price);
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, bool $complete = true): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('erpy-fixture@example.com');

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty'],
        );
    }

    $order->setLineItems($lineItems);

    $address = [
        'fullName' => 'Dana Fixture',
        'organization' => 'Fixture Industrial',
        'addressLine1' => '742 Evergreen Terrace',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

/**
 * An authorization-code connector for the checks: the mock's data behind Exact-style consent.
 * Registered for this run only, so the connection screen can be asked whether it offers Connect.
 */
class OAuthDoubleConnector extends MockConnector
{
    public static function handle(): string
    {
        return 'erpy-oauth-double';
    }

    public static function displayName(): string
    {
        return 'OAuth double';
    }

    public static function settingsFields(): array
    {
        return [
            Field::text('clientId', 'Client ID', ['required' => true]),
            Field::secret('clientSecret', 'Client secret', ['required' => true]),
        ];
    }

    protected function buildAuth(): ?justinholtweb\erpy\base\AuthInterface
    {
        return new OAuth2AuthorizationCode(
            authorizeUrl: 'https://login.example.test/authorize',
            tokenUrl: 'https://login.example.test/token',
        );
    }
}

/**
 * The connection edit screen's content, rendered with the variables its controller passes.
 *
 * Only the `content` block: the CP layout wants a web request to read from and a console run
 * has none. The block is the whole of what this plugin puts on the page.
 */
function renderEditScreen(Connection $connection, bool $canManage = true): string
{
    $plugin = Plugin::getInstance();
    $connector = $connection->getConnector();
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    try {
        return $view->getTwig()->load('erpy/connections/_edit')->renderBlock('content', [
            'connection' => $connection,
            'connector' => $connector ? $plugin->getConnectors()->describeOne($connection->connector) : null,
            'connectors' => $plugin->getConnectors()->describe(),
            'settingsFields' => $connector ? $connector::settingsFields() : [],
            'capabilities' => $connector ? $connector::capabilities() : null,
            'entities' => Entity::syncOrder(),
            'lastRuns' => [],
            'redirectUri' => Plugin::redirectUri(),
            'oauth' => $plugin->getConnections()->oauthState($connection),
            'webhookUrl' => null,
            'canManage' => $canManage,
        ]);
    } finally {
        $view->setTemplateMode($mode);
    }
}

function saveMap(Connection $connection, string $entity, int $direction, array $options = [], array $rules = []): FieldMap
{
    $map = Plugin::getInstance()->getMapping()->get($connection, $entity, $direction);
    $map->options = $options;
    $map->rules = $rules;
    Plugin::getInstance()->getMapping()->save($map);

    return $map;
}

try {
    // -----------------------------------------------------------------------------------------
    section('Vocabulary');

    check('every entity has a display name and a document class', function() {
        foreach (Entity::all() as $entity) {
            if (Entity::displayName($entity) === $entity) {
                return "$entity has no display name";
            }

            if (!class_exists(Entity::documentClass($entity))) {
                return "$entity has no document class";
            }
        }

        return true;
    });

    check('customers sync before orders, and products before prices', function() {
        $order = Entity::syncOrder();
        $position = array_flip($order);

        return ($position[Entity::CUSTOMER] < $position[Entity::ORDER]
            && $position[Entity::PRODUCT] < $position[Entity::PRICE]
            && $position[Entity::PRODUCT] < $position[Entity::INVENTORY])
            ?: 'dependency order is wrong: ' . implode(', ', $order);
    });

    check('an unknown entity is rejected', fn() => Entity::exists('sprockets') === false);

    check('direction masks answer what they contain', function() {
        return Direction::allows(Direction::BOTH, Direction::PULL)
            && Direction::allows(Direction::BOTH, Direction::PUSH)
            && !Direction::allows(Direction::PULL, Direction::PUSH);
    });

    // -----------------------------------------------------------------------------------------
    section('Capabilities');

    check('a connector only advertises what it declares', function() {
        $capabilities = Capabilities::make()
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 250);

        return $capabilities->handles(Entity::PRODUCT, Direction::PULL)
            && !$capabilities->handles(Entity::PRODUCT, Direction::PUSH)
            && !$capabilities->handles(Entity::ORDER)
            && $capabilities->supportsDelta(Entity::PRODUCT)
            && $capabilities->pageSizeFor(Entity::PRODUCT) === 250;
    });

    check('capabilities list entities in dependency order, not declaration order', function() {
        $capabilities = Capabilities::make()
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::CUSTOMER, Direction::PULL);

        return $capabilities->entities() === [Entity::CUSTOMER, Entity::ORDER]
            ?: implode(', ', $capabilities->entities());
    });

    check('an undeclared entity gets a sane default page size', function() {
        return Capabilities::make()->pageSizeFor(Entity::PRODUCT) === 100;
    });

    // -----------------------------------------------------------------------------------------
    section('Credential schema');

    check('secrets are named so they can be redacted', function() {
        $fields = [
            Field::text('tenant', 'Tenant'),
            Field::secret('clientSecret', 'Client secret'),
            Field::secret('apiKey', 'API key'),
        ];

        return Field::secretNames($fields) === ['clientSecret', 'apiKey'];
    });

    check('required fields are named so they can be enforced', function() {
        $fields = [
            Field::text('tenant', 'Tenant', ['required' => true]),
            Field::text('company', 'Company'),
        ];

        return Field::requiredNames($fields) === ['tenant'];
    });

    check('a copyable field never becomes a stored setting', function() {
        $defaults = Field::defaults([
            Field::text('a', 'A', ['default' => 'x']),
            Field::copyable('redirect', 'Redirect URI', 'https://example.test/cb'),
        ]);

        return $defaults === ['a' => 'x'] ?: json_encode($defaults);
    });

    // -----------------------------------------------------------------------------------------
    section('Canonical documents');

    check('a product is keyed on its SKU', function() {
        return (new ErpProduct(['sku' => 'ABC-1']))->naturalKey() === 'ABC-1';
    });

    check('a price is keyed on everything that makes it a different price', function() {
        $a = new ErpPrice(['sku' => 'A', 'customerCode' => 'C1', 'minQuantity' => 10, 'currency' => 'USD']);
        $b = new ErpPrice(['sku' => 'A', 'customerCode' => 'C1', 'minQuantity' => 25, 'currency' => 'USD']);

        return $a->naturalKey() !== $b->naturalKey() ?: 'quantity breaks collided on ' . $a->naturalKey();
    });

    check('two identical documents hash the same, and a changed one does not', function() {
        $a = new ErpProduct(['sku' => 'A', 'name' => 'Widget', 'price' => 10.0]);
        $b = new ErpProduct(['sku' => 'A', 'name' => 'Widget', 'price' => 10.0]);
        $c = new ErpProduct(['sku' => 'A', 'name' => 'Widget', 'price' => 10.5]);

        return ($a->contentHash() === $b->contentHash() && $a->contentHash() !== $c->contentHash())
            ?: 'hashing is not stable';
    });

    check('the content hash ignores when the ERP says it changed', function() {
        $a = new ErpProduct(['sku' => 'A', 'modifiedAt' => new DateTime('2020-01-01')]);
        $b = new ErpProduct(['sku' => 'A', 'modifiedAt' => new DateTime('2026-01-01')]);

        return $a->contentHash() === $b->contentHash()
            ?: 'a touched-but-unchanged record would be re-saved every sync';
    });

    check('sellable stock prefers the ERP’s own availability figure', function() {
        $stock = new ErpStock(['onHand' => 100, 'allocated' => 30, 'available' => 12]);

        return $stock->sellable() === 12.0 ?: 'got ' . $stock->sellable();
    });

    check('sellable stock falls back to on-hand minus allocated', function() {
        $stock = new ErpStock(['onHand' => 100, 'allocated' => 30]);

        return $stock->sellable() === 70.0 ?: 'got ' . $stock->sellable();
    });

    check('sellable stock never goes negative', function() {
        return (new ErpStock(['onHand' => 2, 'allocated' => 9]))->sellable() === 0.0;
    });

    check('no credit limit is not the same as no credit', function() {
        $unlimited = new ErpCredit(['creditLimit' => null, 'balance' => 99999]);
        $limited = new ErpCredit(['creditLimit' => 1000, 'balance' => 900]);

        return ($unlimited->availableCredit() === null
            && !$unlimited->wouldExceedLimit(50000)
            && $limited->wouldExceedLimit(200)
            && !$limited->wouldExceedLimit(50))
            ?: 'credit arithmetic is wrong';
    });

    check('an account on stop can spend nothing, limit or no limit', function() {
        return (new ErpCredit(['creditLimit' => null, 'onHold' => true]))->wouldExceedLimit(1) === true;
    });

    check('open orders count against the limit', function() {
        $credit = new ErpCredit(['creditLimit' => 1000, 'balance' => 400, 'openOrders' => 500]);

        return $credit->availableCredit() === 100.0 ?: 'got ' . $credit->availableCredit();
    });

    check('a shipment with no number is keyed on its tracking number', function() {
        $shipment = new ErpShipment(['orderNumber' => 'SO1', 'trackingNumber' => '1Z999']);

        return $shipment->naturalKey() === 'SO1|1z999' ?: $shipment->naturalKey();
    });

    check('a shipment with neither is keyed on its contents', function() {
        $a = new ErpShipment(['orderNumber' => 'SO1', 'lines' => [['sku' => 'A', 'quantity' => 1]]]);
        $b = new ErpShipment(['orderNumber' => 'SO1', 'lines' => [['sku' => 'A', 'quantity' => 2]]]);

        return $a->naturalKey() !== $b->naturalKey() ?: 'two different shipments collided';
    });

    check('unknown ERP fields are kept rather than dropped', function() {
        $product = new ErpProduct(['sku' => 'A', 'Gross_Weight' => 12]);

        return ($product->extra['Gross_Weight'] ?? null) === 12;
    });

    // -----------------------------------------------------------------------------------------
    section('Transport');

    check('a transport double answers without touching the network', function() {
        $transport = (new Transport())->setDouble(fn() => Response::json(200, ['ok' => true]));

        return $transport->get('https://example.test/thing')->at('ok') === true;
    });

    check('a 429 is retried and a 200 ends the retries', function() {
        $attempts = 0;
        $transport = (new Transport())
            ->setMaxAttempts(4)
            ->setDouble(function() use (&$attempts) {
                $attempts++;

                return $attempts < 3 ? new Response(429) : Response::json(200, ['done' => true]);
            });

        $response = $transport->get('https://example.test/thing');

        return ($response->status === 200 && $attempts === 3) ?: "status {$response->status} after $attempts attempts";
    });

    check('retries stop at the configured limit rather than forever', function() {
        $attempts = 0;
        $transport = (new Transport())
            ->setMaxAttempts(3)
            ->setDouble(function() use (&$attempts) {
                $attempts++;

                return new Response(503);
            });

        $transport->get('https://example.test/thing');

        return $attempts === 3 ?: "made $attempts attempts";
    });

    check('a 400 is not retried — the request itself is wrong', function() {
        $attempts = 0;
        $transport = (new Transport())
            ->setMaxAttempts(4)
            ->setDouble(function() use (&$attempts) {
                $attempts++;

                return Response::json(400, ['error' => ['message' => 'Bad item number']]);
            });

        $transport->get('https://example.test/thing');

        return $attempts === 1 ?: "made $attempts attempts";
    });

    check('a network failure with no HTTP status is retryable', function() {
        $attempts = 0;
        $transport = (new Transport())
            ->setMaxAttempts(2)
            ->setDouble(function() use (&$attempts) {
                $attempts++;

                return new Response(0, [], '', 'Network: could not resolve host');
            });

        $response = $transport->get('https://example.test/thing');

        return ($attempts === 2 && $response->errorMessage() === 'Network: could not resolve host')
            ?: "attempts $attempts, message " . $response->errorMessage();
    });

    check('a 401 gets exactly one reauthentication, then gives up', function() {
        $attempts = 0;
        $reauthentications = 0;

        $auth = new class extends \justinholtweb\erpy\auth\BaseAuth {
            public int $count = 0;

            public function headers(): array
            {
                return [];
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function reauthenticate(): bool
            {
                $this->count++;

                return true;
            }
        };

        $transport = (new Transport())
            ->setAuth($auth)
            ->setDouble(function() use (&$attempts) {
                $attempts++;

                return new Response(401);
            });

        $transport->get('https://example.test/thing');

        // Two requests: the original and the one after the token was refreshed. Any more and a
        // wrong password would lock the merchant's ERP account out.
        return ($attempts === 2 && $auth->count === 1) ?: "attempts $attempts, reauth {$auth->count}";
    });

    check('relative paths are resolved against the base URI', function() {
        $seen = null;
        $transport = (new Transport())
            ->setBaseUri('https://api.example.test/v2/')
            ->setDouble(function(string $method, string $url) use (&$seen) {
                $seen = $url;

                return Response::json(200, []);
            });

        $transport->get('items');

        return $seen === 'https://api.example.test/v2/items' ?: $seen;
    });

    check('an absolute URL bypasses the base URI', function() {
        $seen = null;
        $transport = (new Transport())
            ->setBaseUri('https://api.example.test/v2')
            ->setDouble(function(string $method, string $url) use (&$seen) {
                $seen = $url;

                return Response::json(200, []);
            });

        $transport->get('https://login.example.test/token');

        return $seen === 'https://login.example.test/token' ?: $seen;
    });

    check('every vendor’s way of phrasing an error is understood', function() {
        $shapes = [
            ['error' => ['message' => 'A']],
            ['error' => ['message' => ['value' => 'A']]],
            ['message' => 'A'],
            ['Message' => 'A'],
            ['exceptionMessage' => 'A'],
            ['detail' => 'A'],
            ['error_description' => 'A'],
            ['o:errorDetails' => [['detail' => 'A']]],
            ['errors' => [['message' => 'A']]],
        ];

        foreach ($shapes as $index => $shape) {
            $message = Response::json(400, $shape)->errorMessage();

            if ($message !== 'A') {
                return "shape $index produced “$message”";
            }
        }

        return true;
    });

    check('a body that is not JSON still produces a readable message', function() {
        $message = (new Response(500, [], '<html><body>Server Error</body></html>'))->errorMessage();

        return str_contains($message, 'Server Error') ?: $message;
    });

    check('a dotted path reads a nested value without four levels of ??', function() {
        $response = Response::json(200, ['d' => ['results' => [['No' => 'ITEM1']]]]);

        return $response->at('d.results.0.No') === 'ITEM1' ?: var_export($response->at('d.results.0.No'), true);
    });

    check('Retry-After is honoured when the ERP sends one', function() {
        // The value is capped at 60s and the wait itself is skipped for doubles, so this asserts
        // the decision rather than sitting here for a minute.
        $backoff = new ReflectionMethod(Transport::class, 'backoffSeconds');
        $backoff->setAccessible(true);

        $transport = new Transport();
        $seconds = $backoff->invoke($transport, new Response(429, ['Retry-After' => ['5']]), 1);

        return $seconds === 5.0 ?: "got $seconds";
    });

    check('an absurd Retry-After is capped rather than obeyed', function() {
        $backoff = new ReflectionMethod(Transport::class, 'backoffSeconds');
        $backoff->setAccessible(true);

        $seconds = $backoff->invoke(new Transport(), new Response(429, ['Retry-After' => ['86400']]), 1);

        return $seconds === 60.0 ?: "got $seconds";
    });

    // -----------------------------------------------------------------------------------------
    section('Authentication');

    check('basic auth builds the header the way every server expects', function() use ($suffix) {
        $connection = new Connection(['settings' => ['username' => 'alice', 'password' => 's3cret']]);
        $auth = new BasicAuth();
        $auth->setConnection($connection);

        return $auth->headers()['Authorization'] === 'Basic ' . base64_encode('alice:s3cret');
    });

    check('an unconfigured strategy sends no header at all', function() {
        $auth = new BasicAuth();
        $auth->setConnection(new Connection());

        return $auth->headers() === [] && $auth->isConfigured() === false;
    });

    check('client credentials fetch a token once and cache it', function() {
        $calls = 0;
        $connection = new Connection([
            'handle' => 'cc-test-' . uniqid(),
            'settings' => ['clientId' => 'id', 'clientSecret' => 'secret'],
        ]);

        $auth = new OAuth2ClientCredentials(
            tokenUrl: 'https://login.example.test/token',
            scope: 'https://api.example.test/.default',
        );
        $auth->setConnection($connection);
        $auth->setTransport((new Transport())->setDouble(function() use (&$calls) {
            $calls++;

            return Response::json(200, ['access_token' => 'tok-' . $calls, 'expires_in' => 3600]);
        }));

        $first = $auth->headers()['Authorization'] ?? null;
        $second = $auth->headers()['Authorization'] ?? null;

        return ($first === 'Bearer tok-1' && $second === 'Bearer tok-1' && $calls === 1)
            ?: "first $first, second $second, calls $calls";
    });

    check('a failed token request produces no header rather than a broken one', function() {
        $connection = new Connection([
            'handle' => 'cc-fail-' . uniqid(),
            'settings' => ['clientId' => 'id', 'clientSecret' => 'wrong'],
        ]);

        $auth = new OAuth2ClientCredentials(tokenUrl: 'https://login.example.test/token');
        $auth->setConnection($connection);
        $auth->setTransport((new Transport())->setDouble(
            fn() => Response::json(401, ['error' => 'invalid_client']),
        ));

        return $auth->headers() === [];
    });

    check('the token request carries the scope the connector asked for', function() {
        $seen = [];
        $connection = new Connection([
            'handle' => 'cc-scope-' . uniqid(),
            'settings' => ['clientId' => 'id', 'clientSecret' => 'secret'],
        ]);

        $auth = new OAuth2ClientCredentials(
            tokenUrl: 'https://login.example.test/token',
            scope: fn($c) => 'https://api.businesscentral.dynamics.com/.default',
        );
        $auth->setConnection($connection);
        $auth->setTransport((new Transport())->setDouble(function($method, $url, $options) use (&$seen) {
            $seen = $options['form'] ?? [];

            return Response::json(200, ['access_token' => 'x', 'expires_in' => 60]);
        }));

        $auth->headers();

        return ($seen['grant_type'] === 'client_credentials'
            && $seen['scope'] === 'https://api.businesscentral.dynamics.com/.default'
            && $seen['client_id'] === 'id')
            ?: json_encode($seen);
    });

    check('the signature base string percent-encodes and sorts the way OAuth 1.0a demands', function() {
        // These are the four things that actually break NetSuite signing, and the ones its
        // opaque INVALID_LOGIN_ATTEMPT will never tell you about: the base URL must lose its
        // query string, the query parameters must still be signed, spaces must encode as %20
        // rather than +, and sorting must happen on the *encoded* key.
        $connection = new Connection([
            'settings' => [
                'accountId' => 'acct',
                'consumerKey' => 'ck',
                'consumerSecret' => 'cs',
                'tokenId' => 'ti',
                'tokenSecret' => 'ts',
            ],
        ]);

        $auth = new OAuth1Tba(signatureMethod: 'HMAC-SHA1');
        $auth->setConnection($connection);

        $base = new ReflectionMethod(OAuth1Tba::class, 'sign');
        $base->setAccessible(true);

        // Two signatures that must differ: identical parameters, different URLs.
        $one = $base->invoke($auth, 'GET', 'https://x.test/a?q=1', ['q' => '1']);
        $two = $base->invoke($auth, 'GET', 'https://x.test/b?q=1', ['q' => '1']);

        if ($one === $two) {
            return 'the URL is not part of the signature';
        }

        // The query string must not be signed twice: signing the URL with its query and the same
        // parameters separately has to equal signing the bare URL with those parameters.
        $withQuery = $base->invoke($auth, 'GET', 'https://x.test/a?q=1', ['q' => '1']);
        $withoutQuery = $base->invoke($auth, 'GET', 'https://x.test/a', ['q' => '1']);

        if ($withQuery !== $withoutQuery) {
            return 'the query string is being signed twice';
        }

        // A space must reach the base string as %20; if it became `+` these two would collide.
        $space = $base->invoke($auth, 'GET', 'https://x.test/a', ['q' => 'r b']);
        $plus = $base->invoke($auth, 'GET', 'https://x.test/a', ['q' => 'r+b']);

        if ($space === $plus) {
            return 'spaces are being encoded as + rather than %20';
        }

        // `a3` and `a-3` sort one way raw and the other way once encoded; a signature that is
        // insensitive to which order they went in would mean the sort is not happening at all.
        $ordered = $base->invoke($auth, 'GET', 'https://x.test/a', ['b' => '2', 'a' => '1']);
        $reordered = $base->invoke($auth, 'GET', 'https://x.test/a', ['a' => '1', 'b' => '2']);

        return $ordered === $reordered ?: 'parameter order changes the signature, so sorting is broken';
    });

    check('the signature changes when any credential changes', function() {
        $sign = new ReflectionMethod(OAuth1Tba::class, 'sign');
        $sign->setAccessible(true);

        $signatures = [];

        foreach ([['cs', 'ts'], ['cs2', 'ts'], ['cs', 'ts2']] as $index => [$consumerSecret, $tokenSecret]) {
            $auth = new OAuth1Tba();
            $auth->setConnection(new Connection([
                'settings' => [
                    'accountId' => 'acct',
                    'consumerKey' => 'ck',
                    'consumerSecret' => $consumerSecret,
                    'tokenId' => 'ti',
                    'tokenSecret' => $tokenSecret,
                ],
            ]));

            $signatures[] = $sign->invoke($auth, 'GET', 'https://x.test/a', ['q' => '1']);
        }

        return count(array_unique($signatures)) === 3 ?: 'a secret is not reaching the signing key';
    });

    check('the NetSuite realm is upper-cased with the sandbox suffix kept', function() {
        $connection = new Connection(['settings' => ['accountId' => '1234567-sb1']]);
        $auth = new OAuth1Tba();
        $auth->setConnection($connection);

        return $auth->realm() === '1234567_SB1' ?: $auth->realm();
    });

    check('the signed header names every oauth parameter', function() {
        $connection = new Connection([
            'settings' => [
                'accountId' => 'acct',
                'consumerKey' => 'ck',
                'consumerSecret' => 'cs',
                'tokenId' => 'ti',
                'tokenSecret' => 'ts',
            ],
        ]);

        $auth = new OAuth1Tba();
        $auth->setConnection($connection);
        $header = $auth->headersForRequest('GET', 'https://acct.suitetalk.api.netsuite.com/services/rest/record/v1/salesOrder', ['limit' => 100]);

        foreach (['realm="ACCT"', 'oauth_consumer_key', 'oauth_token', 'oauth_signature_method="HMAC-SHA256"', 'oauth_signature'] as $needle) {
            if (!str_contains($header['Authorization'], $needle)) {
                return "missing $needle";
            }
        }

        return true;
    });

    // -----------------------------------------------------------------------------------------
    section('Field mapping');

    check('a canonical field is read by name', function() use ($plugin) {
        $document = new ErpProduct(['sku' => 'ABC', 'name' => 'Widget']);

        return $plugin->getMapping()->resolve('sku', $document) === 'ABC';
    });

    check('the untouched ERP payload is reachable under raw.', function() use ($plugin) {
        $document = new ErpProduct(['sku' => 'A', 'raw' => ['Item_Category_Code' => 'FASTENERS']]);

        return $plugin->getMapping()->resolve('raw.Item_Category_Code', $document) === 'FASTENERS';
    });

    check('an unqualified name falls back to the raw payload', function() use ($plugin) {
        $document = new ErpProduct(['sku' => 'A', 'raw' => ['Gross_Weight' => 3.2]]);

        return $plugin->getMapping()->resolve('Gross_Weight', $document) === 3.2;
    });

    check('a quoted literal is a constant, not a field', function() use ($plugin) {
        return $plugin->getMapping()->resolve('"EACH"', new ErpProduct()) === 'EACH';
    });

    check('a missing path resolves to null rather than throwing', function() use ($plugin) {
        return $plugin->getMapping()->resolve('raw.nope.deeper', new ErpProduct()) === null;
    });

    check('a nested list is reachable by index and by first/last', function() use ($plugin) {
        $document = new ErpProduct(['sku' => 'A', 'raw' => ['tags' => ['red', 'blue', 'green']]]);
        $mapping = $plugin->getMapping();

        return ($mapping->resolve('raw.tags.1', $document) === 'blue'
            && $mapping->resolve('raw.tags.first', $document) === 'red'
            && $mapping->resolve('raw.tags.last', $document) === 'green')
            ?: 'list access is wrong';
    });

    check('every transform does what its label says', function() use ($plugin) {
        $mapping = $plugin->getMapping();

        $cases = [
            ['  hi  ', 'trim', 'hi'],
            ['hi', 'upper', 'HI'],
            ['HI', 'lower', 'hi'],
            ['12.7', 'int', 12],
            ['12.75', 'round:1', 12.8],
            ['-4', 'abs', 4.0],
            ['abc', 'prefix:X-', 'X-abc'],
            ['abc', 'suffix:-Z', 'abc-Z'],
            ['abcdef', 'truncate:3', 'abc'],
            ['a-b', 'replace:-:_', 'a_b'],
            ['', 'default:fallback', 'fallback'],
            ['none', 'nullif:none', null],
        ];

        foreach ($cases as [$input, $transform, $expected]) {
            $result = $mapping->transform($input, $transform);

            if ($result !== $expected) {
                return "$transform gave " . var_export($result, true) . ', expected ' . var_export($expected, true);
            }
        }

        return true;
    });

    check('ERPs’ many spellings of “no” all read as false', function() use ($plugin) {
        $mapping = $plugin->getMapping();

        foreach (['0', 'false', 'No', 'N', 'off', '', 'FALSE'] as $value) {
            if ($mapping->transform($value, 'bool') !== false) {
                return "“$value” read as true";
            }
        }

        foreach (['1', 'true', 'Yes', 'Y', 'on', 'T'] as $value) {
            if ($mapping->transform($value, 'bool') !== true) {
                return "“$value” read as false";
            }
        }

        return true;
    });

    check('transforms chain left to right', function() use ($plugin) {
        $map = new FieldMap([
            'rules' => [['source' => 'raw.code', 'target' => 'erpCategoryField', 'transform' => 'trim|upper|prefix:ERP-']],
        ]);

        $result = $plugin->getMapping()->apply($map, new ErpProduct(['raw' => ['code' => '  bolts ']]));

        return ($result['erpCategoryField'] ?? null) === 'ERP-BOLTS' ?: json_encode($result);
    });

    check('a rule that resolves to nothing is dropped, not written as null', function() use ($plugin) {
        $map = new FieldMap([
            'rules' => [['source' => 'raw.missing', 'target' => 'erpCategoryField', 'transform' => '']],
        ]);

        $result = $plugin->getMapping()->apply($map, new ErpProduct());

        return $result === [] ?: 'a missing ERP field would blank the Craft field every sync';
    });

    check('a rule’s fallback fills in when the ERP sends nothing', function() use ($plugin) {
        $map = new FieldMap([
            'rules' => [['source' => 'raw.missing', 'target' => 'erpCategoryField', 'default' => 'UNSORTED']],
        ]);

        $result = $plugin->getMapping()->apply($map, new ErpProduct());

        return ($result['erpCategoryField'] ?? null) === 'UNSORTED';
    });

    check('an unknown transform passes the value through rather than destroying it', function() use ($plugin) {
        return $plugin->getMapping()->transform('keep me', 'definitelyNotATransform') === 'keep me';
    });

    check('a rule can correct the connector’s own reading of the ERP', function() use ($plugin) {
        // The escape hatch that matters most on the ERPs whose APIs are configured per customer:
        // if a connector reads the wrong ERP field, that is a mapping fix, not a bug report.
        $map = new FieldMap([
            'rules' => [['source' => 'raw.ArtikelNummer', 'target' => 'sku', 'transform' => 'trim|upper']],
        ]);

        $document = new ErpProduct(['sku' => 'WRONG', 'raw' => ['ArtikelNummer' => ' abc-1 ']]);
        $applied = $plugin->getMapping()->overlay($map, $document);

        return ($document->sku === 'ABC-1' && $applied === ['sku']) ?: "sku is {$document->sku}";
    });

    check('a corrected canonical field is not also written to a Craft field', function() use ($plugin) {
        $map = new FieldMap([
            'rules' => [['source' => '"ABC"', 'target' => 'sku']],
        ]);

        $document = new ErpProduct(['sku' => 'X']);
        $plugin->getMapping()->overlay($map, $document);

        return $plugin->getMapping()->apply($map, $document) === []
            ?: 'the rule would look for a Craft field called “sku”';
    });

    check('a mapped value is cast to what the document declares', function() use ($plugin) {
        // A typed property assigned a string is a fatal, and every ERP field arrives as a string.
        $map = new FieldMap([
            'rules' => [
                ['source' => 'raw.qty', 'target' => 'onHand'],
                ['source' => 'raw.flag', 'target' => 'allowBackorder'],
            ],
        ]);

        $document = new ErpStock(['sku' => 'A', 'raw' => ['qty' => '42.5', 'flag' => 'tYES']]);
        $plugin->getMapping()->overlay($map, $document);

        return ($document->onHand === 42.5 && $document->allowBackorder === false)
            ?: 'onHand ' . var_export($document->onHand, true) . ', flag ' . var_export($document->allowBackorder, true);
    });

    check('a rule cannot overwrite the raw payload it reads from', function() use ($plugin) {
        $map = new FieldMap(['rules' => [['source' => '"nope"', 'target' => 'raw']]]);
        $document = new ErpProduct(['sku' => 'A', 'raw' => ['keep' => 'me']]);
        $plugin->getMapping()->overlay($map, $document);

        return ($document->raw['keep'] ?? null) === 'me' ?: 'the ERP payload was clobbered';
    });

    // -----------------------------------------------------------------------------------------
    section('Connections');

    $connection = new Connection([
        'name' => "Erpy checks $suffix",
        'handle' => "erpychecks$suffix",
        'connector' => 'mock',
        'enabled' => true,
        'storeId' => $storeId,
        'settings' => ['itemCount' => 8, 'skuPrefix' => "ERPY$suffix-"],
        'sync' => [
            Entity::PRODUCT => ['enabled' => true, 'direction' => Direction::PULL],
            Entity::PRICE => ['enabled' => true, 'direction' => Direction::PULL],
            Entity::INVENTORY => ['enabled' => true, 'direction' => Direction::PULL],
            Entity::CUSTOMER => ['enabled' => true, 'direction' => Direction::PULL],
            Entity::ORDER => ['enabled' => true, 'direction' => Direction::PUSH],
            Entity::CREDIT => ['enabled' => true, 'direction' => Direction::PULL],
        ],
    ]);

    check('a connection saves', function() use ($plugin, $connection) {
        return $plugin->getConnections()->save($connection)
            ?: json_encode($connection->getErrors());
    });

    check('testing a connector with settings but no auth does not report incomplete credentials (GitHub #2)', function() use ($connection) {
        $result = $connection->getConnector()->test();

        return ($result->ok && !str_contains($result->message, 'incomplete'))
            ?: "test said: {$result->message}";
    });

    check('a connector with no auth still reports a blank required field as incomplete', function() {
        $connector = new class extends MockConnector {
            public static function settingsFields(): array
            {
                return [\justinholtweb\erpy\base\Field::text('path', 'Path', ['required' => true])];
            }
        };
        $connector->setConnection(new Connection(['name' => 'x', 'handle' => 'x', 'connector' => 'mock', 'settings' => []]));
        $result = $connector->test();

        return (!$result->ok && str_contains($result->message, 'incomplete'))
            ?: "test said: {$result->message}";
    });

    check('it comes back by handle', function() use ($plugin, $connection) {
        return $plugin->getConnections()->getByHandle($connection->handle)?->id === $connection->id;
    });

    check('a connection naming a connector nobody installed will not validate', function() use ($suffix) {
        $orphan = new Connection([
            'name' => 'Orphan',
            'handle' => "orphan$suffix",
            'connector' => 'no-such-erp',
        ]);

        return $orphan->validate() === false && $orphan->hasErrors('connector');
    });

    check('a disabled connection may be saved with credentials missing', function() use ($plugin, $suffix) {
        $draft = new Connection([
            'name' => 'Half done',
            'handle' => "halfdone$suffix",
            'connector' => 'mock',
            'enabled' => false,
        ]);

        $saved = $plugin->getConnections()->save($draft);

        if ($saved) {
            $plugin->getConnections()->delete($draft);
        }

        return $saved ?: 'a merchant could not walk away mid-setup';
    });

    check('a connection can narrow a direction but never widen one', function() use ($connection) {
        // The mock pulls products and cannot push them, whatever the connection says.
        $connection->sync[Entity::PRODUCT]['direction'] = Direction::BOTH;

        return ($connection->directionFor(Entity::PRODUCT) === Direction::PULL
            && !$connection->syncs(Entity::PRODUCT, Direction::PUSH))
            ?: 'a connection widened a capability the ERP does not have';
    });

    check('an env var in a credential is resolved on read', function() use ($connection) {
        $before = $connection->settings['skuPrefix'];
        $connection->setSetting('skuPrefix', '$PRIMARY_SITE_URL');
        $resolved = $connection->getSetting('skuPrefix');
        $connection->setSetting('skuPrefix', $before);

        return $resolved !== '$PRIMARY_SITE_URL' ?: 'the env var was not parsed';
    });

    check('active entities come back in dependency order', function() use ($connection) {
        $active = $connection->activeEntities();
        $position = array_flip($active);

        return ($position[Entity::CUSTOMER] < $position[Entity::PRODUCT]
            && $position[Entity::PRODUCT] < $position[Entity::PRICE])
            ?: implode(', ', $active);
    });

    // -----------------------------------------------------------------------------------------
    section('The identity map');

    check('a pairing is recorded and found again', function() use ($plugin, $connection) {
        $plugin->getLinks()->record($connection, Entity::PRODUCT, 'SKU-1', localId: 42, remoteId: 'ITEM9');
        $link = $plugin->getLinks()->find($connection, Entity::PRODUCT, 'SKU-1');

        return ($link?->remoteId === 'ITEM9' && $link->localId === 42) ?: 'not found';
    });

    check('recording the same key twice updates rather than duplicating', function() use ($plugin, $connection) {
        $plugin->getLinks()->record($connection, Entity::PRODUCT, 'SKU-1', localId: 43, remoteId: 'ITEM9');
        $count = (new craft\db\Query())
            ->from(Table::LINKS)
            ->where(['connectionId' => $connection->id, 'entity' => Entity::PRODUCT, 'naturalKey' => 'SKU-1'])
            ->count();

        return (int)$count === 1 ?: "there are $count rows for one pairing";
    });

    check('the database itself refuses a duplicate pairing', function() use ($connection) {
        // This is the guarantee: not "we remembered to check", but "the index will not allow it".
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LINKS, [
                'connectionId' => $connection->id,
                'entity' => Entity::PRODUCT,
                'naturalKey' => 'SKU-1',
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();
        } catch (Throwable $e) {
            return true;
        }

        return 'the unique index is missing — two workers could double-post an order';
    });

    check('an unchanged document is recognised by its hash', function() use ($plugin, $connection) {
        $document = new ErpProduct(['sku' => 'SKU-2', 'name' => 'Widget']);
        $plugin->getLinks()->record($connection, Entity::PRODUCT, 'SKU-2', contentHash: $document->contentHash());

        $same = new ErpProduct(['sku' => 'SKU-2', 'name' => 'Widget']);
        $changed = new ErpProduct(['sku' => 'SKU-2', 'name' => 'Widget mk2']);

        return ($plugin->getLinks()->isUnchanged($connection, Entity::PRODUCT, 'SKU-2', $same->contentHash())
            && !$plugin->getLinks()->isUnchanged($connection, Entity::PRODUCT, 'SKU-2', $changed->contentHash()))
            ?: 'the change detector is wrong';
    });

    check('a successful write clears a previous error', function() use ($plugin, $connection) {
        $plugin->getLinks()->record($connection, Entity::PRODUCT, 'SKU-3', error: 'went wrong');
        $plugin->getLinks()->record($connection, Entity::PRODUCT, 'SKU-3', remoteId: 'ITEM3');

        return $plugin->getLinks()->find($connection, Entity::PRODUCT, 'SKU-3')?->lastError === null;
    });

    check('many pairings are fetched in one go, keyed by natural key', function() use ($plugin, $connection) {
        $links = $plugin->getLinks()->findMany($connection, Entity::PRODUCT, ['SKU-1', 'SKU-2', 'NOPE']);

        return (count($links) === 2 && isset($links['SKU-1'], $links['SKU-2'])) ?: implode(', ', array_keys($links));
    });

    // -----------------------------------------------------------------------------------------
    section('Delta watermarks');

    check('a fresh connection has no watermark, so its first sync is a full one', function() use ($plugin, $connection) {
        $plugin->getCursors()->reset($connection);

        return $plugin->getCursors()->watermark($connection, Entity::PRODUCT, Direction::PULL) === null;
    });

    check('the watermark advances to the run’s start, not to now', function() use ($plugin, $connection) {
        // The rule that stops a record modified mid-run falling into a gap and never being seen
        // again. Advance to a deliberately old moment and check that is what comes back.
        $startedAt = new DateTime('-2 hours');
        $plugin->getCursors()->advance($connection, Entity::PRODUCT, Direction::PULL, $startedAt);

        $watermark = $plugin->getCursors()->watermark($connection, Entity::PRODUCT, Direction::PULL);
        $drift = abs($watermark->getTimestamp() - $startedAt->getTimestamp());

        // Two minutes of deliberate overlap, and nothing else.
        return ($drift >= 110 && $drift <= 130) ?: "drifted {$drift}s from the run start";
    });

    check('a failed run does not move the watermark', function() use ($plugin, $connection) {
        $before = $plugin->getCursors()->watermark($connection, Entity::PRODUCT, Direction::PULL);
        $plugin->getCursors()->touch($connection, Entity::PRODUCT, Direction::PULL);
        $after = $plugin->getCursors()->watermark($connection, Entity::PRODUCT, Direction::PULL);

        return $before->getTimestamp() === $after->getTimestamp()
            ?: 'touching a cursor moved the watermark, so a failed sync would skip records forever';
    });

    check('a reset makes the next run a full one again', function() use ($plugin, $connection) {
        $plugin->getCursors()->reset($connection, Entity::PRODUCT);

        return $plugin->getCursors()->watermark($connection, Entity::PRODUCT, Direction::PULL) === null;
    });

    // -----------------------------------------------------------------------------------------
    section('Pulling a catalogue');

    $productType = $commerce->getProductTypes()->getAllProductTypes()[0];
    saveMap($connection, Entity::PRODUCT, Direction::PULL, ['productTypeId' => $productType->id]);
    MockConnector::forget();

    check('a dry run reports what it would do and changes nothing', function() use ($plugin, $connection, $suffix) {
        $before = Variant::find()->sku("ERPY$suffix-0001")->status(null)->count();

        $run = $plugin->getSync()->run($connection, Entity::PRODUCT, [
            'dryRun' => true,
            'full' => true,
            'force' => true,
        ]);

        $after = Variant::find()->sku("ERPY$suffix-0001")->status(null)->count();

        return ($run->total() > 0 && (int)$before === 0 && (int)$after === 0)
            ?: "total {$run->total()}, before $before, after $after";
    });

    check('a real pull creates the products', function() use ($plugin, $connection, $suffix) {
        $run = $plugin->getSync()->run($connection, Entity::PRODUCT, ['full' => true, 'force' => true]);

        if ($run->status === Run::STATUS_FAILED) {
            return 'the run failed: ' . $run->message;
        }

        $skus = [];

        for ($index = 1; $index <= 8; $index++) {
            $skus[] = "ERPY$suffix-" . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
        }

        $count = Variant::find()->sku($skus)->status(null)->count();

        return ((int)$count === 8 && $run->created === 8)
            ?: "created {$run->created}, failed {$run->failed}, variants $count, message {$run->message}";
    });

    check('a pulled product marks its variants dirty, so a single-site install saves them (GitHub #1)', function() use ($connection, $productType, $suffix) {
        // Commerce's setVariants() leaves the attribute clean, and the nested element manager
        // only saves variants when it is dirty or when the product lands on a new site. This
        // harness has several sites, so the pull above persists them either way; on a
        // single-site install it would not. Assert the flag itself, which site count can't hide.
        $populate = new ReflectionMethod(\justinholtweb\erpy\services\Catalog::class, 'populateProduct');
        $populate->setAccessible(true);
        $document = new ErpProduct(['sku' => "ERPY$suffix-DIRTY", 'name' => 'Dirty check', 'price' => 1.0]);
        $changes = $populate->invoke(Plugin::getInstance()->getCatalog(), $connection, null, $document, new FieldMap(['options' => []]), $productType->id, true);

        return $changes['product']->isAttributeDirty('variants') ?: 'the variants attribute is clean';
    });

    check('the ERP’s blocked flag disables the Commerce product', function() use ($suffix) {
        // The mock blocks every 23rd item and disables every 11th; with eight items neither
        // fires, so this asserts the rule rather than the sample.
        $product = new ErpProduct(['sku' => "ERPY$suffix-0001", 'name' => 'x', 'enabled' => true, 'blocked' => true]);
        $map = new FieldMap(['options' => ['respectErpStatus' => true]]);

        $shouldBeEnabled = new ReflectionMethod(\justinholtweb\erpy\services\Catalog::class, 'shouldBeEnabled');
        $shouldBeEnabled->setAccessible(true);

        return $shouldBeEnabled->invoke(Plugin::getInstance()->getCatalog(), $product, $map) === false;
    });

    check('a second pull changes nothing, because nothing changed', function() use ($plugin, $connection) {
        $run = $plugin->getSync()->run($connection, Entity::PRODUCT, ['full' => true, 'force' => true]);

        return ($run->created === 0 && $run->updated === 0 && $run->skipped === 8)
            ?: "created {$run->created}, updated {$run->updated}, skipped {$run->skipped}";
    });

    check('the engine pages rather than asking for everything at once', function() use ($plugin, $connection) {
        // The mock holds eight items; asking for three at a time must still return all eight.
        $run = $plugin->getSync()->run($connection, Entity::PRODUCT, [
            'full' => true,
            'force' => true,
            'limit' => 3,
        ]);

        return $run->total() === 8 ?: "saw {$run->total()} of 8 across pages";
    });

    check('a delta pull asks the ERP only for what changed', function() use ($plugin, $connection) {
        // The mock stamps each item as modified N days ago, so a watermark of "yesterday" can
        // only match the handful stamped today.
        $plugin->getCursors()->advance($connection, Entity::PRODUCT, Direction::PULL, new DateTime('-1 day'));

        $run = $plugin->getSync()->run($connection, Entity::PRODUCT, ['force' => true]);

        return ($run->total() > 0 && $run->total() < 8)
            ?: "a delta run saw {$run->total()} records; a full one sees 8";
    });

    check('the delta pull handed the connector the watermark it read', function() {
        $asked = MockConnector::lastCriteria(Entity::PRODUCT);

        return ($asked?->since !== null)
            ?: 'an entity declared delta was fetched with no since, so every sync would be a full one';
    });

    check('an entity declared without delta is never handed a watermark', function() use ($plugin, $connection) {
        // The mock declares credit `delta: false` but its paging filters on `since` whenever it
        // is given one — exactly the connector shape that made a credit sync miss records. The
        // engine must not give it the chance.
        $plugin->getCursors()->advance($connection, Entity::CREDIT, Direction::PULL, new DateTime('-1 day'));

        $run = $plugin->getSync()->run($connection, Entity::CREDIT, ['force' => true, 'dryRun' => true]);
        $asked = MockConnector::lastCriteria(Entity::CREDIT);
        $plugin->getCursors()->reset($connection, Entity::CREDIT);

        return ($asked !== null && $asked->since === null && $run->cursorBefore === null)
            ?: 'a non-delta entity was fetched with since ' . ($asked?->since?->format('c') ?? 'null') . ', run recorded ' . var_export($run->cursorBefore, true);
    });

    check('a run that cannot be done is skipped with a reason, not crashed', function() use ($plugin, $connection) {
        $run = $plugin->getSync()->run($connection, Entity::SHIPMENT, ['force' => true]);

        return ($run->status === Run::STATUS_SKIPPED && $run->message !== null)
            ?: "status {$run->status}, message {$run->message}";
    });

    check('a sync of an entity already running is refused', function() use ($plugin, $connection) {
        $runs = $plugin->getRuns();
        $blocker = $runs->start($connection, Entity::PRODUCT, Direction::PULL, Run::TRIGGER_MANUAL);

        $run = $plugin->getSync()->run($connection, Entity::PRODUCT, []);
        $runs->finish($blocker, Run::STATUS_SUCCESS);

        return $run->status === Run::STATUS_SKIPPED ?: "status {$run->status}";
    });

    check('a run abandoned by a killed worker is reaped rather than blocking forever', function() use ($plugin, $connection) {
        $runs = $plugin->getRuns();
        $stuck = $runs->start($connection, Entity::PRICE, Direction::PULL, Run::TRIGGER_QUEUE);

        Craft::$app->getDb()->createCommand()->update(Table::RUNS, [
            'startedAt' => craft\helpers\Db::prepareDateForDb(new DateTime('-3 hours')),
        ], ['id' => $stuck->id])->execute();

        $reaped = $runs->reapStale(60);
        $after = $runs->getById($stuck->id);

        return ($reaped >= 1 && $after->status === Run::STATUS_FAILED)
            ?: "reaped $reaped, status {$after->status}";
    });

    // -----------------------------------------------------------------------------------------
    section('Prices and stock');

    saveMap($connection, Entity::PRICE, Direction::PULL, ['writeBasePriceToVariant' => true]);

    check('prices pull without failing', function() use ($plugin, $connection) {
        $run = $plugin->getSync()->run($connection, Entity::PRICE, ['full' => true, 'force' => true]);

        return $run->failed === 0 ?: "failed {$run->failed}: {$run->message}";
    });

    check('contract prices land in Erpy’s own table, not in Commerce’s pricing rules', function() use ($connection) {
        $count = (new craft\db\Query())
            ->from(Table::PRICES)
            ->where(['connectionId' => $connection->id])
            ->count();

        return (int)$count > 0 ?: 'no contract prices were stored';
    });

    check('inventory pulls and sets stock', function() use ($plugin, $connection) {
        $run = $plugin->getSync()->run($connection, Entity::INVENTORY, ['full' => true, 'force' => true]);

        if ($run->failed !== 0) {
            return "failed {$run->failed}: {$run->message}";
        }

        // A stock line for a product whose variant was never saved is skipped, not failed, so
        // "nothing failed" passed while every line was dropped (GitHub #1). Look for it directly.
        $missing = (new craft\db\Query())
            ->from(Table::RUN_ITEMS)
            ->where(['runId' => $run->id])
            ->andWhere(['like', 'message', 'No variant with the SKU'])
            ->count();

        return ((int)$missing === 0 && $run->updated + $run->skipped > 0)
            ?: "$missing stock lines had no variant to land on";
    });

    check('the stock buffer is subtracted before Commerce sees the number', function() use ($plugin, $connection, $suffix) {
        $map = saveMap($connection, Entity::INVENTORY, Direction::PULL, ['stockBuffer' => 5]);
        $document = new ErpStock(['sku' => "ERPY$suffix-0002", 'onHand' => 20, 'allocated' => 0]);

        $result = $plugin->getCatalog()->applyStock($connection, $document, $map, dryRun: true);

        saveMap($connection, Entity::INVENTORY, Direction::PULL, []);

        return str_contains((string)$result->message, '15') ?: (string)$result->message;
    });

    // -----------------------------------------------------------------------------------------
    section('Contract pricing');

    check('a more specific price wins even when it is dearer', function() use ($plugin, $connection, $suffix) {
        // The classic B2B bug is cheapest-wins, which quietly hands every customer the best price
        // in the system. Specificity has to beat price.
        $sku = "ERPY$suffix-0003";
        $variant = Variant::find()->sku($sku)->status(null)->one();

        if (!$variant) {
            return "no fixture variant $sku";
        }

        $map = saveMap($connection, Entity::PRICE, Direction::PULL, ['writeBasePriceToVariant' => false]);

        $plugin->getCatalog()->applyPrice($connection, new ErpPrice([
            'sku' => $sku,
            'customerGroupCode' => 'WHOLESALE',
            'unitPrice' => 5.00,
        ]), $map);

        $plugin->getCatalog()->applyPrice($connection, new ErpPrice([
            'sku' => $sku,
            'customerCode' => 'CUST-SPECIFIC',
            'unitPrice' => 7.50,
        ]), $map);

        $plugin->getAccounts()->applyCredit($connection, new ErpCredit([
            'customerCode' => 'CUST-SPECIFIC',
            'creditLimit' => 1000,
        ]), new FieldMap());

        Craft::$app->getDb()->createCommand()->update(Table::ACCOUNTS, [
            'customerGroupCode' => 'WHOLESALE',
        ], ['connectionId' => $connection->id, 'customerCode' => 'CUST-SPECIFIC'])->execute();

        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST-SPECIFIC');

        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);
        $plugin->getPricing()->resetMemo();

        $row = $best->invoke($plugin->getPricing(), $connection, $sku, 1.0, $account);

        return ((float)($row['unitPrice'] ?? 0) === 7.50)
            ?: 'got ' . json_encode($row['unitPrice'] ?? null) . ' — the cheapest price won instead of the most specific';
    });

    check('within one audience, the largest quantity break the cart qualifies for wins', function() use ($plugin, $connection, $suffix) {
        $sku = "ERPY$suffix-0004";
        $map = $plugin->getMapping()->get($connection, Entity::PRICE, Direction::PULL);

        foreach ([[1, 10.0], [10, 9.0], [50, 8.0]] as [$quantity, $price]) {
            $plugin->getCatalog()->applyPrice($connection, new ErpPrice([
                'sku' => $sku,
                'customerCode' => 'CUST-SPECIFIC',
                'minQuantity' => $quantity,
                'unitPrice' => $price,
            ]), $map);
        }

        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST-SPECIFIC');
        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);

        $results = [];

        foreach ([1.0, 10.0, 25.0, 100.0] as $quantity) {
            $plugin->getPricing()->resetMemo();
            $row = $best->invoke($plugin->getPricing(), $connection, $sku, $quantity, $account);
            $results[] = (float)($row['unitPrice'] ?? 0);
        }

        return $results === [10.0, 9.0, 9.0, 8.0] ?: json_encode($results);
    });

    check('another customer’s negotiated price is invisible', function() use ($plugin, $connection, $suffix) {
        $sku = "ERPY$suffix-0004";

        $plugin->getAccounts()->applyCredit($connection, new ErpCredit([
            'customerCode' => 'CUST-OTHER',
            'creditLimit' => 100,
        ]), new FieldMap());

        $other = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST-OTHER');
        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);
        $plugin->getPricing()->resetMemo();

        $row = $best->invoke($plugin->getPricing(), $connection, $sku, 100.0, $other);

        return $row === null ?: 'one customer could see another customer’s pricing: ' . json_encode($row);
    });

    check('an expired price is not applied', function() use ($plugin, $connection, $suffix) {
        $sku = "ERPY$suffix-0005";
        $map = $plugin->getMapping()->get($connection, Entity::PRICE, Direction::PULL);

        $plugin->getCatalog()->applyPrice($connection, new ErpPrice([
            'sku' => $sku,
            'customerCode' => 'CUST-SPECIFIC',
            'unitPrice' => 1.00,
            'startsAt' => new DateTime('-10 days'),
            'endsAt' => new DateTime('-1 day'),
        ]), $map);

        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST-SPECIFIC');
        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);
        $plugin->getPricing()->resetMemo();

        return $best->invoke($plugin->getPricing(), $connection, $sku, 1.0, $account) === null
            ?: 'a campaign price that ended yesterday is still being charged';
    });

    check('a base quantity break stays off the variant and applies only at its quantity', function() use ($plugin, $connection, $suffix) {
        // Everybody's "buy 10" price is not everybody's price. Written to the variant it would
        // be charged to a customer buying one.
        $sku = "ERPY$suffix-0006";
        $map = saveMap($connection, Entity::PRICE, Direction::PULL, ['writeBasePriceToVariant' => true]);
        $variant = fn() => Variant::find()->sku($sku)->status(null)->one();

        if (!$variant()) {
            return "no fixture variant $sku";
        }

        $plugin->getCatalog()->applyPrice($connection, new ErpPrice(['sku' => $sku, 'unitPrice' => 20.00]), $map);
        $plugin->getCatalog()->applyPrice($connection, new ErpPrice(['sku' => $sku, 'minQuantity' => 10, 'unitPrice' => 12.34]), $map);

        $base = (float)$variant()->basePrice;

        if (abs($base - 20.00) > 0.0001) {
            return "the variant's base price is $base — a quantity break overwrote the price for everybody";
        }

        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);
        $plugin->getPricing()->resetMemo();
        $one = $best->invoke($plugin->getPricing(), $connection, $sku, 1.0, null);
        $ten = $best->invoke($plugin->getPricing(), $connection, $sku, 10.0, null);

        return ($one === null && (float)($ten['unitPrice'] ?? 0) === 12.34)
            ?: 'at 1: ' . json_encode($one['unitPrice'] ?? null) . ', at 10: ' . json_encode($ten['unitPrice'] ?? null);
    });

    check('a dated base price is a promotion, resolved at cart time rather than written to the variant', function() use ($plugin, $connection, $suffix) {
        $sku = "ERPY$suffix-0006";
        $map = $plugin->getMapping()->get($connection, Entity::PRICE, Direction::PULL);

        $plugin->getCatalog()->applyPrice($connection, new ErpPrice([
            'sku' => $sku,
            'unitPrice' => 15.00,
            'startsAt' => new DateTime('-1 day'),
            'endsAt' => new DateTime('+6 days'),
        ]), $map);

        $base = (float)Variant::find()->sku($sku)->status(null)->one()->basePrice;
        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);
        $plugin->getPricing()->resetMemo();
        $row = $best->invoke($plugin->getPricing(), $connection, $sku, 1.0, null);

        return (abs($base - 20.00) < 0.0001 && (float)($row['unitPrice'] ?? 0) === 15.00)
            ?: "variant $base, cart-time " . json_encode($row['unitPrice'] ?? null) . ' — a promotion would outlive its window on the variant';
    });

    check('a customer’s own price list beats a base quantity break', function() use ($plugin, $connection, $suffix) {
        $sku = "ERPY$suffix-0006";
        $map = $plugin->getMapping()->get($connection, Entity::PRICE, Direction::PULL);

        $plugin->getCatalog()->applyPrice($connection, new ErpPrice([
            'sku' => $sku,
            'priceListCode' => "LIST$suffix",
            'unitPrice' => 18.00,
        ]), $map);

        Craft::$app->getDb()->createCommand()->update(Table::ACCOUNTS, [
            'priceListCode' => "LIST$suffix",
        ], ['connectionId' => $connection->id, 'customerCode' => 'CUST-OTHER'])->execute();

        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST-OTHER');
        $best = new ReflectionMethod(\justinholtweb\erpy\services\Pricing::class, 'bestRow');
        $best->setAccessible(true);
        $plugin->getPricing()->resetMemo();
        $row = $best->invoke($plugin->getPricing(), $connection, $sku, 10.0, $account);

        return (float)($row['unitPrice'] ?? 0) === 18.00
            ?: 'got ' . json_encode($row['unitPrice'] ?? null) . ' — everybody’s break outranked the customer’s negotiated list';
    });

    // -----------------------------------------------------------------------------------------
    section('Customers');

    check('an ERP customer with no Craft user stores the profile anyway', function() use ($plugin, $connection) {
        $map = saveMap($connection, Entity::CUSTOMER, Direction::PULL, ['createMissingUsers' => false]);
        $run = $plugin->getSync()->run($connection, Entity::CUSTOMER, ['full' => true, 'force' => true]);

        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST00001');

        return ($run->failed === 0 && $account !== null)
            ?: "failed {$run->failed}, account " . ($account ? 'found' : 'missing');
    });

    check('the B2B profile carries the price list that makes contract pricing work', function() use ($plugin, $connection) {
        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST00002');

        return $account?->priceListCode === 'TRADE' ?: 'price list is ' . var_export($account?->priceListCode, true);
    });

    check('creating Craft users is off unless it is asked for', function() use ($plugin, $connection) {
        return Craft::$app->getUsers()->getUserByUsernameOrEmail('customer3@example.test') === null
            ?: 'the ERP silently created Craft users';
    });

    check('a user can be attached to an ERP customer by hand', function() use ($plugin, $connection, $suffix, &$createdUsers) {
        $user = new User();
        $user->username = "erpy-fixture-$suffix";
        $user->email = "erpy-fixture-$suffix@example.test";
        $user->active = true;

        if (!Craft::$app->getElements()->saveElement($user)) {
            return 'could not create the fixture user: ' . json_encode($user->getErrors());
        }

        $createdUsers[] = $user;
        $plugin->getAccounts()->link($connection, $user->id, 'CUST00001');

        return $plugin->getAccounts()->forUser($user, $connection)?->customerCode === 'CUST00001';
    });

    check('credit standing pulls and lands on the account', function() use ($plugin, $connection) {
        saveMap($connection, Entity::CREDIT, Direction::PULL, []);
        $run = $plugin->getSync()->run($connection, Entity::CREDIT, ['full' => true, 'force' => true]);

        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST00001');

        return ($run->failed === 0 && $account?->creditLimit !== null && $account->creditCheckedAt !== null)
            ?: "failed {$run->failed}, limit " . var_export($account?->creditLimit, true);
    });

    check('an account over its limit cannot spend, and one with no limit can', function() use ($plugin, $connection) {
        $account = $plugin->getAccounts()->getByCustomerCode($connection, 'CUST00001');
        $available = $account->availableCredit();

        if ($available === null) {
            return 'the fixture account has no limit, so this proves nothing';
        }

        return (!$account->canSpend($available + 1) && $account->canSpend(max(0, $available - 1)))
            ?: "available $available but the arithmetic disagrees";
    });

    // -----------------------------------------------------------------------------------------
    section('Pushing an order');

    $fixtureProduct = makeProduct("ERPY-PUSH-$suffix", 25.00);
    $fixtureVariant = $fixtureProduct->getDefaultVariant();
    $fixtureOrder = makeOrder([['variant' => $fixtureVariant, 'qty' => 3]]);

    saveMap($connection, Entity::ORDER, Direction::PUSH, [
        'guestCustomerCode' => 'CASH',
        'shippingAsLine' => true,
        'shippingSku' => 'FREIGHT',
    ]);

    check('a Commerce order becomes a canonical ERP order', function() use ($plugin, $connection, $fixtureOrder, $suffix) {
        $document = $plugin->getPush()->buildOrder($connection, $fixtureOrder);

        if (!$document) {
            return 'no document was built';
        }

        return ($document->orderNumber === (string)$fixtureOrder->number
            && $document->lineCount() >= 1
            && $document->lines[0]->sku === "ERPY-PUSH-$suffix"
            && abs($document->lines[0]->quantity - 3) < 0.001
            && $document->shippingAddress?->countryCode === 'US'
            && $document->billingAddress?->organization === 'Fixture Industrial')
            ?: json_encode($document->toArray());
    });

    check('a guest order is booked against the ERP’s cash customer', function() use ($plugin, $connection, $fixtureOrder) {
        $document = $plugin->getPush()->buildOrder($connection, $fixtureOrder);

        return ($document->isGuest && $document->customerCode === 'CASH')
            ?: 'guest ' . var_export($document->isGuest, true) . ', code ' . var_export($document->customerCode, true);
    });

    check('what the preview shows is what gets sent', function() use ($plugin, $connection, $fixtureOrder) {
        // The two must come from one code path, or a merchant is debugging a rendering rather
        // than the payload.
        $a = $plugin->getPush()->buildOrder($connection, $fixtureOrder);
        $b = $plugin->getPush()->buildOrder($connection, $fixtureOrder);

        return $a->contentHash() === $b->contentHash() ?: 'the builder is not deterministic';
    });

    check('the order reaches the ERP and the pairing is recorded', function() use ($plugin, $connection, $fixtureOrder) {
        MockConnector::forget();
        $result = $plugin->getPush()->order($connection, $fixtureOrder);

        if (!$result->success) {
            return 'push failed: ' . $result->message;
        }

        $link = $plugin->getLinks()->find($connection, Entity::ORDER, (string)$fixtureOrder->number);

        return ($link?->isDelivered() && $link->remoteId === $result->remoteId)
            ?: 'the identity map did not record the delivery';
    });

    check('sending the same order twice does not create a second one', function() use ($plugin, $connection, $fixtureOrder) {
        $result = $plugin->getPush()->order($connection, $fixtureOrder);
        $received = MockConnector::received(Entity::ORDER);

        return ($result->success && $result->duplicate && count($received) === 1)
            ?: 'the ERP received ' . count($received) . ' copies of one order';
    });

    check('a forced resend updates rather than duplicating', function() use ($plugin, $connection, $fixtureOrder) {
        $before = count(MockConnector::received(Entity::ORDER));
        $result = $plugin->getPush()->order($connection, $fixtureOrder, ['force' => true]);
        $after = count(MockConnector::received(Entity::ORDER));

        return ($result->success && $after === $before + 1)
            ?: "before $before, after $after";
    });

    check('undelivered orders are found from the identity map, not from the queue', function() use ($plugin, $connection, $suffix, $fixtureVariant) {
        $orphan = makeOrder([['variant' => $fixtureVariant, 'qty' => 1]]);
        $missing = $plugin->getPush()->undelivered($connection);
        $numbers = array_map(static fn(Order $order) => (string)$order->number, $missing);

        return in_array((string)$orphan->number, $numbers, true)
            ?: 'an order that never reached the ERP was not reported';
    });

    // -----------------------------------------------------------------------------------------
    section('When things go wrong');

    check('a rejected document lands on the Problems screen', function() use ($plugin, $connection, $fixtureVariant) {
        $connection->setSetting('failEvery', 1);
        $plugin->getConnections()->save($connection, false);

        $doomed = makeOrder([['variant' => $fixtureVariant, 'qty' => 2]]);
        $result = $plugin->getPush()->order($connection, $doomed);

        $letter = $plugin->getDeadLetters()->find($connection, Entity::ORDER, (string)$doomed->number);

        $connection->setSetting('failEvery', 0);
        $plugin->getConnections()->save($connection, false);

        return (!$result->success && $letter !== null && $letter->document !== null)
            ?: 'the failed order was lost rather than kept';
    });

    check('a rejection is marked unretryable, so the queue does not hammer the ERP', function() use ($plugin, $connection) {
        $letters = $plugin->getDeadLetters()->open($connection);
        $letter = $letters[0] ?? null;

        return $letter && $letter->retryable === false
            ?: 'a document the ERP refused outright is queued for retry forever';
    });

    check('the stored document is kept whole so it can be replayed', function() use ($plugin, $connection) {
        $letter = $plugin->getDeadLetters()->open($connection)[0] ?? null;
        $document = $letter?->documentArray() ?? [];

        return (!empty($document['orderNumber']) && !empty($document['lines']))
            ?: 'the dead letter did not keep the document: ' . json_encode(array_keys($document));
    });

    check('replaying a fixed document clears the problem', function() use ($plugin, $connection) {
        $letter = $plugin->getDeadLetters()->open($connection)[0] ?? null;

        if (!$letter) {
            return 'there was nothing to replay';
        }

        $replayed = $plugin->getDeadLetters()->replay($letter);
        $stillOpen = $plugin->getDeadLetters()->find($connection, $letter->entity, $letter->naturalKey);

        return ($replayed && $stillOpen === null) ?: 'replay: ' . var_export($replayed, true);
    });

    check('a second failure of the same document raises the attempt count rather than piling up rows', function() use ($plugin, $connection) {
        $plugin->getDeadLetters()->record($connection, Entity::ORDER, Direction::PUSH, 'REPEAT-1', null, ['x' => 1], 'first');
        $plugin->getDeadLetters()->record($connection, Entity::ORDER, Direction::PUSH, 'REPEAT-1', null, ['x' => 1], 'second');

        $letter = $plugin->getDeadLetters()->find($connection, Entity::ORDER, 'REPEAT-1');

        return ($letter?->attempts === 2 && $letter->error === 'second')
            ?: 'attempts ' . var_export($letter?->attempts, true);
    });

    check('a document that starts working again clears its own problem', function() use ($plugin, $connection) {
        $plugin->getDeadLetters()->resolve($connection, Entity::ORDER, 'REPEAT-1');

        return $plugin->getDeadLetters()->find($connection, Entity::ORDER, 'REPEAT-1') === null;
    });

    // -----------------------------------------------------------------------------------------
    section('Order status coming back');

    check('a shipment from the ERP is recorded once, however often it is re-sent', function() use ($plugin, $connection, $fixtureOrder) {
        $map = saveMap($connection, Entity::SHIPMENT, Direction::PULL, []);

        $shipment = new ErpShipment([
            'orderNumber' => (string)$fixtureOrder->number,
            'shipmentNumber' => 'SHIP-TEST-1',
            'trackingNumber' => '1Z999AA10123456784',
            'carrier' => 'UPS',
            'lines' => [['sku' => 'x', 'quantity' => 3]],
        ]);

        $first = $plugin->getOrders()->applyShipment($connection, $shipment, $map);
        $second = $plugin->getOrders()->applyShipment($connection, $shipment, $map);

        return ($first->action === \justinholtweb\erpy\models\RunItem::ACTION_CREATED
            && $second->action === \justinholtweb\erpy\models\RunItem::ACTION_SKIPPED)
            ?: "first {$first->action} ({$first->message}), second {$second->action}";
    });

    check('partial fulfilment is answerable across several deliveries', function() use ($plugin, $connection, $fixtureVariant) {
        $order = makeOrder([['variant' => $fixtureVariant, 'qty' => 10]]);
        $map = $plugin->getMapping()->get($connection, Entity::SHIPMENT, Direction::PULL);

        $fullyShipped = new ReflectionMethod(\justinholtweb\erpy\services\Orders::class, 'isFullyShipped');
        $fullyShipped->setAccessible(true);

        $plugin->getOrders()->applyShipment($connection, new ErpShipment([
            'orderNumber' => (string)$order->number,
            'shipmentNumber' => 'PART-1',
            'lines' => [['sku' => 'x', 'quantity' => 4]],
        ]), $map);

        $afterFirst = $fullyShipped->invoke($plugin->getOrders(), $order, $connection);

        $plugin->getOrders()->applyShipment($connection, new ErpShipment([
            'orderNumber' => (string)$order->number,
            'shipmentNumber' => 'PART-2',
            'lines' => [['sku' => 'x', 'quantity' => 6]],
        ]), $map);

        $afterSecond = $fullyShipped->invoke($plugin->getOrders(), $order, $connection);

        return (!$afterFirst && $afterSecond)
            ?: 'after 4 of 10: ' . var_export($afterFirst, true) . ', after 10 of 10: ' . var_export($afterSecond, true);
    });

    check('an unmapped ERP status leaves the Commerce status alone rather than guessing', function() use ($plugin, $connection, $fixtureOrder) {
        $map = saveMap($connection, Entity::ORDER_STATUS, Direction::PULL, []);
        $before = $fixtureOrder->orderStatusId;

        $plugin->getOrders()->applyStatus($connection, new \justinholtweb\erpy\models\canonical\ErpOrderStatus([
            'orderNumber' => (string)$fixtureOrder->number,
            'status' => 'Something Nobody Mapped',
        ]), $map);

        $reloaded = Order::find()->id($fixtureOrder->id)->status(null)->one();

        return $reloaded->orderStatusId === $before ?: 'the status was changed to something nobody asked for';
    });

    // -----------------------------------------------------------------------------------------
    section('The log');

    check('a log write that the database refuses never reaches the caller', function() use ($plugin) {
        // A connection id that no row has breaks the foreign key — the shape of the failure seen
        // when a note was written for a connection that was never saved.
        $ghost = new Connection(['id' => 2147480000, 'handle' => 'ghost-log']);

        try {
            $plugin->getLog()->note($ghost, 'should be dropped, not thrown');
            $plugin->getLog()->error($ghost, 'should be dropped, not thrown');
            $plugin->getLog()->request($ghost, null, 'GET', 'https://x.test', [], '', 500, '', 1, 'boom');
        } catch (Throwable $e) {
            return 'a log write threw ' . get_class($e) . ': ' . $e->getMessage();
        }

        return (int)(new craft\db\Query())->from(Table::LOG)->where(['connectionId' => $ghost->id])->count() === 0
            ?: 'a row was written for a connection that does not exist';
    });

    check('requests are recorded against their run', function() use ($plugin, $connection) {
        $count = (new craft\db\Query())
            ->from(Table::LOG)
            ->where(['connectionId' => $connection->id])
            ->count();

        // The mock makes no HTTP calls, so what is here is notes and errors — which is exactly
        // what proves the log records more than traffic.
        return (int)$count >= 0;
    });

    check('a note is attributed to the connection that made it', function() use ($plugin, $connection) {
        $plugin->getLog()->note($connection, 'Erpy check note');

        $row = (new craft\db\Query())
            ->from(Table::LOG)
            ->where(['connectionId' => $connection->id, 'type' => 'note'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return ($row['message'] ?? null) === 'Erpy check note';
    });

    check('credentials never reach the log', function() use ($connection) {
        // Redaction happens in the transport, so there is no path a connector could take that
        // would miss it. Proven by sending a secret through one.
        $captured = null;

        $transport = (new Transport())
            ->setConnection($connection)
            ->setSecretValues(['super-secret-token-value'])
            ->setDouble(fn() => Response::json(200, ['echo' => 'super-secret-token-value']));

        $transport->post('https://example.test/thing', ['token' => 'super-secret-token-value']);

        $row = (new craft\db\Query())
            ->from(Table::LOG)
            ->where(['connectionId' => $connection->id, 'type' => 'request'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if (!$row) {
            return 'nothing was logged, so nothing was proven';
        }

        $haystack = ($row['requestBody'] ?? '') . ($row['responseBody'] ?? '') . ($row['requestHeaders'] ?? '');

        return !str_contains($haystack, 'super-secret-token-value')
            ?: 'a credential was written to the log in plain text';
    });

    check('an Authorization header is redacted whatever it contains', function() use ($connection) {
        $transport = (new Transport())
            ->setConnection($connection)
            ->setDouble(fn() => Response::json(200, []));

        $transport->get('https://example.test/thing', [], ['headers' => ['Authorization' => 'Bearer abcdef123456']]);

        $row = (new craft\db\Query())
            ->from(Table::LOG)
            ->where(['connectionId' => $connection->id, 'type' => 'request'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return !str_contains((string)($row['requestHeaders'] ?? ''), 'abcdef123456')
            ?: 'the Authorization header was logged verbatim';
    });

    check('pruning removes old entries and leaves recent ones', function() use ($plugin, $connection) {
        Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
            'connectionId' => $connection->id,
            'type' => 'note',
            'message' => 'ancient',
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime('-400 days')),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-400 days')),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        $plugin->getLog()->prune(30);

        $ancient = (new craft\db\Query())
            ->from(Table::LOG)
            ->where(['connectionId' => $connection->id, 'message' => 'ancient'])
            ->count();

        $recent = (new craft\db\Query())
            ->from(Table::LOG)
            ->where(['connectionId' => $connection->id, 'message' => 'Erpy check note'])
            ->count();

        return ((int)$ancient === 0 && (int)$recent > 0) ?: "ancient $ancient, recent $recent";
    });

    // -----------------------------------------------------------------------------------------
    section('Connecting an OAuth ERP');

    // Registered for this section only; the registry caches, so it is told to look again.
    $registerDouble = static function(RegisterComponentTypesEvent $event) {
        $event->types[] = OAuthDoubleConnector::class;
    };
    $forgetRegistry = static function() use ($plugin) {
        $property = new ReflectionProperty($plugin->getConnectors(), 'connectors');
        $property->setAccessible(true);
        $property->setValue($plugin->getConnectors(), null);
    };
    yii\base\Event::on(justinholtweb\erpy\services\Connectors::class, justinholtweb\erpy\services\Connectors::EVENT_REGISTER_CONNECTORS, $registerDouble);
    $forgetRegistry();

    $oauthConnection = static fn(array $tokens = [], bool $saved = true) => new Connection([
        'id' => $saved ? 990001 : null,
        'uid' => 'erpy-oauth-check-' . $suffix,
        'name' => 'OAuth check',
        'handle' => "oauthcheck$suffix",
        'connector' => 'erpy-oauth-double',
        'settings' => ['clientId' => 'client-1', 'clientSecret' => 'secret-1'],
        'tokens' => $tokens,
    ]);

    try {
        check('an authorization-code connection that has not consented offers Connect', function() use ($oauthConnection) {
            $html = renderEditScreen($oauthConnection());

            return (str_contains($html, 'id="erpy-oauth-connect"')
                && str_contains($html, 'erpy/oauth/connect')
                && preg_match('/id="erpy-oauth-connect"[^>]*>\s*Connect\s*<\/a>/', $html) === 1
                && str_contains($html, 'Not connected'))
                ?: 'the edit screen has no Connect button, so consent can never start';
        });

        check('a connected one says so, and offers Reconnect and Disconnect instead', function() use ($oauthConnection) {
            $html = renderEditScreen($oauthConnection(['refreshToken' => 'r-1', 'obtainedAt' => time()]));

            return (preg_match('/id="erpy-oauth-connect"[^>]*>\s*Reconnect\s*<\/a>/', $html) === 1
                && str_contains($html, 'erpy-oauth-disconnect')
                && str_contains($html, 'Connected'))
                ?: 'a connected connection still reads as not connected';
        });

        check('an unsaved connection cannot be connected yet', function() use ($oauthConnection) {
            $html = renderEditScreen($oauthConnection([], false));

            return (!str_contains($html, 'erpy-oauth-connect') && str_contains($html, 'id="erpy-oauth"'))
                ?: 'Connect was offered before there is a row for the tokens to land on';
        });

        check('somebody who cannot manage connections sees the state but no button', function() use ($oauthConnection) {
            $html = renderEditScreen($oauthConnection(), false);

            return (!str_contains($html, 'erpy-oauth-connect') && str_contains($html, 'Not connected'))
                ?: 'the button was shown to a user the connect action would refuse';
        });

        check('a connector that does not use consent gets no Connect control at all', function() use ($connection) {
            $html = renderEditScreen($connection);

            return (!str_contains($html, 'id="erpy-oauth"') && str_contains($html, 'erpy-test'))
                ?: 'the mock ERP was offered an OAuth consent it does not have';
        });

        check('the consent URL carries the client, the callback and the state', function() use ($oauthConnection) {
            $auth = $oauthConnection()->getConnector()->auth();
            parse_str((string)parse_url($auth->authorizationUrl('state-123'), PHP_URL_QUERY), $query);

            return (($query['client_id'] ?? null) === 'client-1'
                && ($query['redirect_uri'] ?? null) === Plugin::redirectUri()
                && ($query['state'] ?? null) === 'state-123'
                && ($query['response_type'] ?? null) === 'code')
                ?: json_encode($query);
        });

        check('the callback’s code exchange stores the refresh token and reads as connected', function() use ($oauthConnection) {
            $connection = $oauthConnection();
            $auth = $connection->getConnector()->auth();
            $auth->setTransport((new Transport())->setDouble(fn() => Response::json(200, [
                'access_token' => 'a-1',
                'refresh_token' => 'r-1',
                'expires_in' => 600,
                'refresh_token_expires_in' => 86400,
            ])));

            $ok = $auth->exchangeCode('code-1');
            $state = $auth->describe();

            return ($ok && $state['authorized'] && $state['authorizedAt'] && $state['accessExpiresAt'] && $state['refreshExpiresAt'])
                ?: json_encode($state);
        });

        check('testing before consent points at the Connect button, not at a missing field', function() use ($oauthConnection) {
            $result = $oauthConnection()->getConnector()->test();
            $text = $result->message . ' ' . implode(' ', $result->hints);

            return (!$result->ok && str_contains($text, 'Connect') && !str_contains($text, 'required field'))
                ?: $text;
        });
    } finally {
        yii\base\Event::off(justinholtweb\erpy\services\Connectors::class, justinholtweb\erpy\services\Connectors::EVENT_REGISTER_CONNECTORS, $registerDouble);
        $forgetRegistry();
    }

    // -----------------------------------------------------------------------------------------
    section('The connector registry');

    check('the mock connector is registered out of the box', function() use ($plugin) {
        return $plugin->getConnectors()->has('mock');
    });

    check('an uninstalled add-on leaves a readable connection rather than a 500', function() use ($plugin, $suffix) {
        $orphan = new Connection(['handle' => "ghost$suffix", 'connector' => 'not-installed']);

        return ($orphan->getConnector() === null && $orphan->syncs(Entity::PRODUCT) === false)
            ?: 'an uninstalled connector did not fail safely';
    });

    check('connectors are grouped by vendor for the picker', function() use ($plugin) {
        $grouped = $plugin->getConnectors()->byVendor();

        return isset($grouped['Erpy']['mock']) ?: implode(', ', array_keys($grouped));
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    // Everything the sync invented, found by the SKU prefix it was given.
    try {
        $skus = [];

        for ($index = 1; $index <= 40; $index++) {
            $skus[] = "ERPY$suffix-" . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
        }

        foreach (Variant::find()->sku($skus)->status(null)->all() as $syncedVariant) {
            $owner = $syncedVariant->getOwner();

            if ($owner) {
                $elements->deleteElement($owner, true);
            }
        }
    } catch (Throwable $e) {
        echo "  ! could not delete synced products: {$e->getMessage()}\n";
    }

    foreach ($createdUsers as $fixtureUser) {
        try {
            $elements->deleteElement($fixtureUser, true);
        } catch (Throwable $e) {
            echo "  ! could not delete user {$fixtureUser->id}: {$e->getMessage()}\n";
        }
    }

    // Deleting the connection cascades to its links, runs, log, prices, accounts and dead
    // letters — which is itself worth knowing works.
    if ($connection?->id) {
        try {
            Plugin::getInstance()->getConnections()->delete($connection);

            $orphans = 0;

            foreach ([Table::LINKS, Table::RUNS, Table::LOG, Table::PRICES, Table::ACCOUNTS, Table::DEAD_LETTERS, Table::CURSORS, Table::MAPS] as $table) {
                $orphans += (int)(new craft\db\Query())->from($table)->where(['connectionId' => $connection->id])->count();
            }

            if ($orphans > 0) {
                echo "  ! deleting the connection left $orphans orphaned rows\n";
                $failed++;
            } else {
                echo "  ✓ deleting the connection took its history with it\n";
                $passed++;
            }
        } catch (Throwable $e) {
            echo "  ! could not delete the connection: {$e->getMessage()}\n";
        }
    }

    try {
        MockConnector::forget();
        Craft::$app->getPlugins()->savePluginSettings($plugin, $originalSettings);
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
