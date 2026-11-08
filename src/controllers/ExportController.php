<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Controller;
use DateTime;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\OrderExport;
use justinholtweb\erpy\base\ServesOrders;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Orders served to an ERP that fetches them: `GET erpy/export/<handle>/orders`.
 *
 * Serving is delivering. Each order goes through `Push::deliver()` exactly as a pushed one does
 * — the identity map, the mutex, the run log, the dead letters — and the connector, instead of
 * sending it anywhere, hands it back here to be written into the response by its
 * `ServesOrders::renderServed()`. Any connector that implements `ServesOrders` can be fetched
 * from; the built-in file connector is one. So the order is in
 * the identity map as delivered, and the next poll does not serve it again.
 *
 * - No parameters: orders this connection has not delivered yet, oldest first.
 * - `from` / `to` (dates, inclusive): every order in that range, delivered or not — how an ERP
 *   that lost a response asks again.
 * - `all=1`: every order since the connection was created.
 * - `limit`: at most this many (default 200, at most 1000).
 *
 * The token travels as an `X-Erpy-Token` header or a basic-auth password, and only in the URL
 * (`?key=…`) when the merchant has allowed it. A request from an allowed IP address needs none. Only GET: a HEAD or a link-preview bot must not be able to
 * mark orders as delivered.
 */
class ExportController extends Controller
{
    protected array|bool|int $allowAnonymous = ['orders'];

    public $enableCsrfValidation = false;

    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 1000;

    public function actionOrders(string $connectionHandle): Response
    {
        if (!$this->request->getIsGet()) {
            throw new MethodNotAllowedHttpException('Fetch orders with GET.');
        }

        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getByHandle($connectionHandle);
        $connector = $connection?->getConnector();

        // One answer for every way this is not an export, so the endpoint does not tell a
        // stranger which handles exist.
        if (!$connection || !$connection->enabled || !$connector instanceof ServesOrders
            || !$connector instanceof Connector || !$connector->deliversOnRequest(Entity::ORDER) || !$connection->syncs(Entity::ORDER, Direction::PUSH)) {
            throw new NotFoundHttpException('No such export.');
        }

        $this->authorise($connection);

        $dateFormat = (string)$connection->getSetting('exportDateFormat', '');
        $from = $this->date('from', $dateFormat);
        $to = $this->date('to', $dateFormat, endOfDay: true);
        $all = (bool)$this->param('all');
        $explicit = $all || $from !== null || $to !== null;
        $limit = max(1, min(self::MAX_LIMIT, (int)($this->param('limit') ?: self::DEFAULT_LIMIT)));

        $push = $plugin->getPush();
        $db = Craft::$app->getDb();

        // Serving records each order as delivered, so recording and rendering are one unit: a
        // template that throws half way must not leave orders marked delivered that the ERP never
        // received. The mutex in deliver() still stops two polls serving the same order.
        $transaction = $db->beginTransaction();

        try {
            $documents = Connector::collect(function() use ($connection, $explicit, $from, $to, $limit, $push) {
                $this->serve($connection, $explicit, $from, $to, $limit, $push);
            });

            $body = $connector->renderServed($documents);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Erpy could not serve orders for “{$connection->handle}”: {$e->getMessage()}", 'erpy');
            $plugin->getLog()->error($connection, Craft::t('erpy', 'The export URL failed and served nothing: {message}', ['message' => $e->getMessage()]));

            throw new \yii\web\ServerErrorHttpException('The export failed; nothing was marked as delivered.');
        }

        // A poll every few minutes that finds nothing is not worth a log line each time.
        if ($documents !== []) {
            $plugin->getLog()->note($connection, Craft::t('erpy', 'The ERP fetched {n, plural, =1{one order} other{# orders}} from the export URL.', ['n' => count($documents)]));
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->content = $body;
        $response->getHeaders()
            ->set('Content-Type', $connector->servedContentType())
            ->set('Content-Disposition', 'inline; filename="orders.' . preg_replace('/[^A-Za-z0-9]/', '', $connector->servedExtension()) . '"')
            ->set('Cache-Control', 'no-store')
            ->set('X-Erpy-Count', (string)count($documents));

        return $response;
    }

    private function authorise(Connection $connection): void
    {
        $expected = (string)$connection->getSetting('exportToken', '');
        $allowedIps = OrderExport::allowedIps($connection);

        // Every refusal is the same 404 as a handle that does not exist, so the endpoint cannot
        // be used to find out which connections serve orders. The reason goes to the log.
        if ($expected === '' && $allowedIps === []) {
            Craft::warning("Erpy refused an order export for “{$connection->handle}”: no export token or allowed IP address is set.", 'erpy');

            throw new NotFoundHttpException('No such export.');
        }

        // An ERP that polls from one fixed address and cannot be made to send a token. A listed
        // address with a token is let in here too; the token is not then checked.
        if ($allowedIps !== [] && OrderExport::ipAllowed($this->clientIp(), $allowedIps)) {
            return;
        }

        $provided = (string)$this->request->getHeaders()->get('X-Erpy-Token', '');

        if ($provided === '') {
            [, $password] = $this->request->getAuthCredentials();
            $provided = (string)$password;
        }

        if ($provided === '' && filter_var($connection->getSetting('exportTokenInUrl'), FILTER_VALIDATE_BOOL)) {
            // `key`, not `token`: Craft reserves `?token=` for its own tokenised routes and
            // answers 400 before this controller runs.
            $provided = (string)$this->request->getQueryParam('key', '');
        }

        // Hashed first so hash_equals compares equal lengths, and constant-time so the endpoint
        // cannot be used to guess the token a character at a time.
        if ($expected === '' || $provided === '' || !hash_equals(hash('sha256', $expected), hash('sha256', $provided))) {
            Craft::warning("Erpy refused an order export for “{$connection->handle}” from {$this->clientIp()}: bad or missing token, and not an allowed IP address.", 'erpy');

            throw new NotFoundHttpException('No such export.');
        }
    }

    /**
     * The address the allowed-IP list is checked against.
     *
     * Not `Request::getUserIP()`. Craft's override of it returns the left-most valid entry of
     * `X-Forwarded-For`, which is whatever the client wrote. A proxy appends the real address
     * after it, so the client can name any address it likes. The chain is walked here instead.
     */
    private function clientIp(): ?string
    {
        $forwarded = null;

        foreach ($this->request->ipHeaders as $header) {
            if ($this->request->getHeaders()->has($header)) {
                $forwarded = (string)$this->request->getHeaders()->get($header);
                break;
            }
        }

        return self::resolveClientIp($this->request->getRemoteIP(), $this->request->trustedHosts, $forwarded);
    }

    /**
     * The client's address, believing `X-Forwarded-For` only as far as the proxies in
     * `trustedHosts` vouch for it.
     *
     * - The TCP peer, unless it is a trusted proxy. A header from anyone else is ignored.
     * - The TCP peer as well when `trustedHosts` would trust a public address (Craft's default,
     *   `any`, does). Then every client counts as a proxy and could name its own address.
     * - Otherwise the chain is read from the right, skipping trusted proxies. The first address
     *   that is not one is the client. Everything to its left was written by the client.
     */
    public static function resolveClientIp(?string $remote, array $trustedHosts, ?string $forwarded): ?string
    {
        if ($remote === null || $forwarded === null || trim($forwarded) === ''
            || self::trustsPublicAddresses($trustedHosts) || !self::isTrusted($remote, $trustedHosts)) {
            return $remote;
        }

        $client = $remote;

        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }

            $client = $hop;

            if (!self::isTrusted($hop, $trustedHosts)) {
                break;
            }
        }

        return $client;
    }

    private static function isTrusted(string $ip, array $trustedHosts): bool
    {
        $validator = new \yii\validators\IpValidator();

        foreach ($trustedHosts as $key => $value) {
            try {
                $validator->setRanges(is_array($value) ? $key : $value);

                if ($validator->validate($ip)) {
                    return true;
                }
            } catch (\Throwable) {
                // A hostname or malformed entry: never matches an address.
            }
        }

        return false;
    }

    /**
     * Whether `trustedHosts` (in Yii's shape: `'range'` or `'range' => [headers]`) would trust
     * well-known public addresses, the way `Request::getTrustedHeaders()` matches them.
     */
    public static function trustsPublicAddresses(array $trustedHosts): bool
    {
        $validator = new \yii\validators\IpValidator();

        foreach ($trustedHosts as $key => $value) {
            $range = is_array($value) ? $key : $value;

            foreach (['8.8.8.8', '1.1.1.1', '203.0.113.7', '2001:4860:4860::8888'] as $probe) {
                try {
                    $validator->setRanges($range);

                    if ($validator->validate($probe)) {
                        return true;
                    }
                } catch (\Throwable) {
                    // A hostname or malformed entry: Yii would not match an IP against it either.
                }
            }
        }

        return false;
    }

    /**
     * Hand orders to `Push::order()` until `$limit` have been served or there are no more.
     *
     * An order an event handler holds back, or one that fails to build, is skipped rather than
     * ending the request — and the scan moves past it, so a few of those at the front of the
     * queue cannot stop every newer order from ever being served.
     */
    private function serve(Connection $connection, bool $explicit, ?DateTime $from, ?DateTime $to, int $limit, \justinholtweb\erpy\services\Push $push): void
    {
        $served = 0;
        $offset = 0;

        // Bounded, so a store with thousands of unservable orders still answers.
        for ($round = 0; $round < 10 && $served < $limit; $round++) {
            $orders = $this->candidates($connection, $explicit, $from, $to, $limit, $offset);

            if ($orders === []) {
                return;
            }

            $servedNow = 0;

            foreach ($orders as $order) {
                try {
                    $result = $push->order($connection, $order, [
                        'trigger' => Run::TRIGGER_EXPORT,
                        // An explicit range is the ERP asking again for orders it may already have.
                        'force' => $explicit,
                    ]);
                } catch (\Throwable $e) {
                    Plugin::getInstance()->getLog()->error($connection, Craft::t('erpy', 'Order {number} could not be built for the export: {message}', ['number' => $order->number, 'message' => $e->getMessage()]));
                    continue;
                }

                if ($result->success && !$result->duplicate) {
                    $servedNow++;

                    if (++$served >= $limit) {
                        return;
                    }
                }
            }

            // Without a range, a served order is now delivered and drops out of the next query
            // on its own (this transaction sees its own writes), so only the skipped ones are
            // stepped over. A range lists the same orders every time, so step over them all.
            $offset += $explicit ? count($orders) : count($orders) - $servedNow;
        }
    }

    /**
     * The orders to serve, oldest first.
     *
     * Without a range, only what the identity map does not have as delivered — found with one
     * join rather than by loading every order a store has ever taken. Orders placed before the
     * connection existed are left out, as they are for a pushing connection: switching an export
     * on must not hand the ERP five years of history on its first poll.
     *
     * @return Order[]
     */
    private function candidates(Connection $connection, bool $explicit, ?DateTime $from, ?DateTime $to, int $limit, int $offset = 0): array
    {
        $query = (new Query())
            ->select(['o.id'])
            ->from(['o' => CommerceTable::ORDERS])
            ->innerJoin(['e' => \craft\db\Table::ELEMENTS], '[[e.id]] = [[o.id]]')
            ->where(['o.isCompleted' => true, 'o.storeId' => $connection->getStoreId(), 'e.dateDeleted' => null])
            ->orderBy(['o.dateOrdered' => SORT_ASC, 'o.id' => SORT_ASC])
            ->offset($offset)
            ->limit($limit);

        $since = $from ?? $connection->dateCreated;

        if ($since !== null) {
            $query->andWhere(['>=', 'o.dateOrdered', Db::prepareDateForDb($since)]);
        }

        if ($to !== null) {
            $query->andWhere(['<=', 'o.dateOrdered', Db::prepareDateForDb($to)]);
        }

        if (!$explicit) {
            $query
                ->leftJoin(['l' => Table::LINKS], [
                    'and',
                    '[[l.naturalKey]] = [[o.number]]',
                    ['l.connectionId' => $connection->id, 'l.entity' => Entity::ORDER],
                ])
                ->andWhere(['or', ['l.id' => null], ['l.remoteId' => null], ['l.remoteId' => '']]);
        }

        $ids = $query->column();

        if ($ids === []) {
            return [];
        }

        $orders = Order::find()->id($ids)->status(null)->limit(null)->indexBy('id')->all();

        // Back into date order; the element query has its own idea of sorting.
        return array_values(array_filter(array_map(static fn($id) => $orders[$id] ?? null, $ids)));
    }

    /**
     * A date parameter, read in the site's timezone. A bare date as `to` means the end of that day.
     */
    private function date(string $name, string $format, bool $endOfDay = false): ?DateTime
    {
        $value = trim((string)$this->param($name));

        if ($value === '') {
            return null;
        }

        try {
            return OrderExport::parseDate($value, $format, $endOfDay);
        } catch (\InvalidArgumentException) {
            $example = $format !== '' ? (new DateTime())->format($format) : '2026-11-03 or 2026-11-03T14:00:00';

            throw new BadRequestHttpException("“{$name}” is not a date. Use {$example}.");
        }
    }

    /**
     * A query parameter, or the same name captured by a site route — so an ERP that already
     * fetches `/export-orders/orders-time/<from>` can keep its URL. Craft, unlike Yii, does not
     * merge route parameters into the query string, so without this a `<from>` in a route would be
     * silently ignored and the ERP handed only new orders instead of its range.
     */
    private function param(string $name): mixed
    {
        $value = $this->request->getQueryParam($name);

        if ($value !== null && $value !== '') {
            return $value;
        }

        return Craft::$app->getUrlManager()->getRouteParams()[$name] ?? null;
    }
}
