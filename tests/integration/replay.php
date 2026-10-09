<?php
/**
 * Retrying a dead letter sends that document once, as itself, and never books it twice.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-erpy/tests/integration/replay.php
 *
 * Until this was fixed every replay was forced, and a forced push hands the connector the remote id
 * from the identity map — which every add-on reads as "skip the duplicate check and post another".
 * A Problems page left open while a queue retry delivered the order, a "retry everything" pass that
 * listed a letter just before, or a retry of a failed forced resend each booked a second sales
 * order or invoice in the ERP.
 *
 * The ERP here is a Guzzle MockHandler behind a test connector that behaves exactly like the
 * add-ons: with no remote id it looks the document up before posting; with one it posts. Every
 * request is kept with `Middleware::history`, so each check counts POSTs per endpoint.
 *
 * Self-cleaning: the fixture connection — and by cascade its links, runs and dead letters — is
 * removed at the end. Commerce orders are only read.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\events\RegisterComponentTypesEvent;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpPayment;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;
use justinholtweb\erpy\services\Connectors;
use Psr\Http\Message\RequestInterface;
use yii\base\Event;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

/**
 * The pretend ERP's books, answered through a Guzzle MockHandler.
 */
final class ReplayErp
{
    /** @var array<string,array<string,string>> endpoint => reference => id */
    public static array $books = ['orders' => [], 'payments' => []];

    /** @var array<string,string> endpoint => "reject" (nothing booked) or "lose" (booked, answer lost) */
    public static array $fail = [];

    public static array $history = [];

    public static ?MockHandler $mock = null;

    public static ?Client $client = null;

    public static function reset(): void
    {
        self::$books = ['orders' => [], 'payments' => []];
        self::$fail = [];
        self::$history = [];
        self::$mock = new MockHandler();
        $stack = HandlerStack::create(self::$mock);
        $stack->push(Middleware::history(self::$history));
        self::$client = new Client(['handler' => $stack, 'http_errors' => false]);
    }

    public static function forgetHistory(): void
    {
        self::$history = [];
    }

    /** POSTs to one endpoint since the history was last cleared. */
    public static function posts(string $endpoint): int
    {
        $count = 0;

        foreach (self::$history as $entry) {
            $request = $entry['request'];

            if ($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/' . $endpoint)) {
                $count++;
            }
        }

        return $count;
    }

    /** The transport double: every request goes through the MockHandler-backed Guzzle client. */
    public static function double(string $method, string $url, array $options): Response
    {
        self::$mock->append(static fn(RequestInterface $request) => self::answer($request));

        $guzzle = [];

        if (isset($options['query']) && $options['query'] !== []) {
            $guzzle['query'] = $options['query'];
        }

        if (array_key_exists('json', $options)) {
            $guzzle['json'] = $options['json'];
        }

        $response = self::$client->request($method, $url, $guzzle);

        return Response::json($response->getStatusCode(), json_decode((string)$response->getBody(), true));
    }

    private static function answer(RequestInterface $request): Psr7Response
    {
        $endpoint = basename($request->getUri()->getPath());

        if ($request->getMethod() === 'GET') {
            parse_str($request->getUri()->getQuery(), $query);
            $ref = (string)($query['ref'] ?? '');
            $rows = isset(self::$books[$endpoint][$ref]) ? [['id' => self::$books[$endpoint][$ref], 'ref' => $ref]] : [];

            return new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode(['value' => $rows]));
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $ref = (string)($body['ref'] ?? '');
        $mode = self::$fail[$endpoint] ?? null;
        unset(self::$fail[$endpoint]);

        if ($mode === 'reject') {
            return new Psr7Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Item not on file']));
        }

        $id = strtoupper(substr($endpoint, 0, 2)) . str_pad((string)(count(self::$books[$endpoint]) + 1), 5, '0', STR_PAD_LEFT) . '-' . substr(md5($ref . microtime()), 0, 4);
        self::$books[$endpoint][$ref] = $id;

        if ($mode === 'lose') {
            // Booked, but the answer never made it back — the timeout every ERP integration meets.
            return new Psr7Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Gateway hiccup']));
        }

        return new Psr7Response(201, ['Content-Type' => 'application/json'], json_encode(['id' => $id]));
    }
}

/**
 * Behaves like every craft-erpy-* add-on's push: no remote id → look it up first; a remote id →
 * the engine is forcing it, so post.
 */
final class ReplayTestConnector extends Connector
{
    public static function handle(): string
    {
        return 'erpyreplaytest';
    }

    public static function displayName(): string
    {
        return 'Replay test ERP';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::PAYMENT, Direction::PUSH);
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://erp.example.test/api/')
            ->setMaxAttempts(1)
            ->setDouble([ReplayErp::class, 'double']);
    }

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        return $this->book('orders', $document->orderNumber, ['ref' => $document->orderNumber, 'total' => $document->total], $remoteId);
    }

    protected function pushPayment(ErpPayment $document, ?string $remoteId = null): PushResult
    {
        return $this->book('payments', $document->naturalKey(), [
            'ref' => $document->naturalKey(),
            'order' => $document->orderNumber,
            'amount' => $document->isRefund ? -$document->amount : $document->amount,
        ], $remoteId);
    }

    private function book(string $endpoint, string $ref, array $body, ?string $remoteId): PushResult
    {
        if ($remoteId === null) {
            $existing = $this->transport()->get($endpoint, ['ref' => $ref]);

            foreach ((array)$existing->at('value', []) as $row) {
                if (($row['ref'] ?? null) === $ref) {
                    return PushResult::alreadyExists((string)$row['id'], $ref);
                }
            }
        }

        $response = $this->transport()->post($endpoint, $body);

        return $response->ok()
            ? PushResult::ok((string)$response->at('id'), $ref)
            : PushResult::failed((string)$response->at('error', 'HTTP ' . $response->status));
    }
}

Craft::$app->getPlugins()->loadPlugins();

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

Event::on(Connectors::class, Connectors::EVENT_REGISTER_CONNECTORS, static function(RegisterComponentTypesEvent $event) {
    $event->types[] = ReplayTestConnector::class;
});

$plugin = Plugin::getInstance();

// The registry is memoised; drop it so the test connector is collected.
(new ReflectionProperty(Connectors::class, 'connectors'))->setValue($plugin->getConnectors(), null);

$suffix = bin2hex(random_bytes(3));
$cleanup = [];

register_shutdown_function(function() use (&$cleanup, $plugin) {
    foreach ($cleanup as $id) {
        if ($c = $plugin->getConnections()->getById($id)) {
            $plugin->getConnections()->delete($c);
        }
    }
});

ReplayErp::reset();

$connection = new Connection([
    'name' => "Replay $suffix",
    'handle' => "replay$suffix",
    'connector' => ReplayTestConnector::handle(),
    'enabled' => true,
    'settings' => [],
    'sync' => [],
]);
$plugin->getConnections()->save($connection, false) or throw new RuntimeException(json_encode($connection->getErrors()));
$cleanup[] = $connection->id;
$connection = $plugin->getConnections()->getById($connection->id);

$push = $plugin->getPush();
$letters = $plugin->getDeadLetters();
$links = $plugin->getLinks();

$order = fn(string $number) => new ErpOrder(['orderNumber' => $number, 'currency' => 'USD', 'total' => 120.0]);
$refund = fn(string $number) => new ErpPayment(['reference' => "RF-$number", 'orderNumber' => $number, 'amount' => 20.0, 'currency' => 'USD', 'isRefund' => true]);

// ---------------------------------------------------------------------------------------------
section('A failed refund is retried as itself');

$saleNo = "ERPY-RP-$suffix-1";
$stale = null;

check('the sale books once and the refund fails onto the Problems screen', function() use ($push, $letters, $connection, $order, $refund, $saleNo, &$stale) {
    $sale = $push->deliver($connection, Entity::ORDER, $order($saleNo), null);
    ReplayErp::$fail['payments'] = 'reject';
    $result = $push->deliver($connection, Entity::PAYMENT, $refund($saleNo), null);
    $stale = $letters->find($connection, Entity::PAYMENT, "RF-$saleNo");

    return ($sale->success && !$result->success && $stale !== null && ReplayErp::posts('orders') === 1 && ReplayErp::posts('payments') === 1)
        ?: 'sale ' . var_export($sale->success, true) . ', refund ' . var_export($result->success, true) . ', letter ' . var_export($stale !== null, true);
});

check('retrying the refund POSTs once to payments and never to orders', function() use ($letters, $links, $connection, $saleNo, &$stale) {
    ReplayErp::forgetHistory();
    $ok = $letters->replay($stale);
    $link = $links->find($connection, Entity::PAYMENT, "RF-$saleNo");

    return ($ok && ReplayErp::posts('payments') === 1 && ReplayErp::posts('orders') === 0 && $link?->isDelivered()
        && $letters->find($connection, Entity::PAYMENT, "RF-$saleNo") === null)
        ?: sprintf('replay %s, payments %d, orders %d', var_export($ok, true), ReplayErp::posts('payments'), ReplayErp::posts('orders'));
});

check('a stale "Retry" on the now-resolved refund sends nothing', function() use ($letters, &$stale) {
    ReplayErp::forgetHistory();
    $ok = $letters->replay($stale);

    return ($ok && ReplayErp::posts('payments') === 0 && ReplayErp::posts('orders') === 0 && count(ReplayErp::$history) === 0)
        ?: sprintf('payments %d, orders %d — the refund was booked twice', ReplayErp::posts('payments'), ReplayErp::posts('orders'));
});

// ---------------------------------------------------------------------------------------------
section('An order delivered behind the retry\'s back');

$raceNo = "ERPY-RP-$suffix-2";

check('"retry everything" listed it, a queue retry delivered it, the retry posts nothing', function() use ($push, $letters, $connection, $order, $raceNo) {
    ReplayErp::$fail['orders'] = 'reject';
    $push->deliver($connection, Entity::ORDER, $order($raceNo), null);
    $listed = array_values(array_filter($letters->open($connection), static fn($l) => $l->naturalKey === $raceNo))[0] ?? null;

    if (!$listed) {
        return 'the failed order was not dead-lettered';
    }

    // The queue's own retry gets there first.
    $push->deliver($connection, Entity::ORDER, $order($raceNo), null);
    ReplayErp::forgetHistory();
    $ok = $letters->replay($listed);

    return ($ok && ReplayErp::posts('orders') === 0)
        ?: sprintf('orders POSTed %d more time(s) — a second sales order', ReplayErp::posts('orders'));
});

check('a Commerce order whose forced resend failed is not booked a second time by Retry', function() use ($push, $letters, $links, $connection) {
    $commerceOrder = Order::find()->isCompleted(true)->status(null)->one();

    if (!$commerceOrder) {
        return 'the harness has no completed order to read';
    }

    $number = (string)$commerceOrder->number;
    $first = $push->order($connection, $commerceOrder);

    if (!$first->success || !$links->find($connection, Entity::ORDER, $number)?->isDelivered()) {
        return 'the first push failed: ' . $first->message;
    }

    // `erpy/orders/push --force` that fell over leaves an open letter on a delivered order.
    ReplayErp::$fail['orders'] = 'reject';
    $forced = $push->order($connection, $commerceOrder, ['force' => true]);
    $letter = $letters->find($connection, Entity::ORDER, $number);

    if ($forced->success || !$letter) {
        return 'the forced resend did not fail onto the Problems screen';
    }

    ReplayErp::forgetHistory();
    $ok = $letters->replay($letter);

    return ($ok && ReplayErp::posts('orders') === 0 && $letters->find($connection, Entity::ORDER, $number) === null)
        ?: sprintf('retry %s, orders POSTed %d', var_export($ok, true), ReplayErp::posts('orders'));
});

// ---------------------------------------------------------------------------------------------
section('Retries that should still send');

check('an order the ERP never booked is sent exactly once on retry', function() use ($push, $letters, $connection, $order, $suffix) {
    $number = "ERPY-RP-$suffix-3";
    ReplayErp::$fail['orders'] = 'reject';
    $push->deliver($connection, Entity::ORDER, $order($number), null);
    $letter = $letters->find($connection, Entity::ORDER, $number);
    ReplayErp::forgetHistory();
    $ok = $letter && $letters->replay($letter);

    return ($ok && ReplayErp::posts('orders') === 1 && isset(ReplayErp::$books['orders'][$number]))
        ?: sprintf('retry %s, orders POSTed %d', var_export($ok, true), ReplayErp::posts('orders'));
});

check('an order the ERP booked but whose answer was lost is found, not re-posted', function() use ($push, $letters, $connection, $order, $suffix) {
    $number = "ERPY-RP-$suffix-4";
    ReplayErp::$fail['orders'] = 'lose';
    $push->deliver($connection, Entity::ORDER, $order($number), null);
    $letter = $letters->find($connection, Entity::ORDER, $number);
    ReplayErp::forgetHistory();
    $ok = $letter && $letters->replay($letter);

    return ($ok && ReplayErp::posts('orders') === 0)
        ?: sprintf('retry %s, orders POSTed %d', var_export($ok, true), ReplayErp::posts('orders'));
});

check('`erpy/orders/retry` retries a failed refund as a refund', function() use ($push, $connection, $refund, $suffix) {
    $number = "ERPY-RP-$suffix-1";
    $other = new ErpPayment(['reference' => "RF2-$number", 'orderNumber' => $number, 'amount' => 5.0, 'currency' => 'USD', 'isRefund' => true]);
    ReplayErp::$fail['payments'] = 'reject';
    $push->deliver($connection, Entity::PAYMENT, $other, null);
    ReplayErp::forgetHistory();

    ob_start();
    $code = Craft::$app->runAction('erpy/orders/retry', [$connection->handle]);
    ob_end_clean();

    return ($code === 0 && ReplayErp::posts('payments') === 1 && ReplayErp::posts('orders') === 0)
        ?: sprintf('exit %s, payments %d, orders %d', var_export($code, true), ReplayErp::posts('payments'), ReplayErp::posts('orders'));
});

check('a deliberate forced push still resends', function() use ($push, $connection, $order, $suffix) {
    ReplayErp::forgetHistory();
    $result = $push->deliver($connection, Entity::ORDER, $order("ERPY-RP-$suffix-3"), null, ['force' => true]);

    return ($result->success && ReplayErp::posts('orders') === 1) ?: 'orders POSTed ' . ReplayErp::posts('orders');
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $passed passed, $failed failed\n";
echo str_repeat('-', 60) . "\n";

exit($failed > 0 ? 1 : 0);
