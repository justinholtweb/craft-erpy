<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\elements\User;
use DateTime;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\events\BuildDocumentEvent;
use justinholtweb\erpy\jobs\PushOrderJob;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpDocument;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\DeadLetter;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\models\RunItem;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * The push engine.
 *
 * Erpy's second invariant lives here: **every outbound document is delivered by `deliver()`**, and
 * every one is built by a `build*()` method that the control panel's preview uses too. A merchant
 * who clicks "preview payload" sees the bytes that will be sent, not a rendering of them.
 *
 * The rule that matters most: a duplicated sales order gets picked, packed and shipped twice, so
 * delivery is guarded three ways — a mutex against concurrent workers, the identity map against
 * repeat attempts, and the connector's own duplicate detection against everything else.
 */
class Push extends Component
{
    /**
     * @event BuildDocumentEvent fired after an outbound document is built, before it is sent
     */
    public const EVENT_BEFORE_PUSH = 'beforePush';

    // ---------------------------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------------------------

    /**
     * Send one order to one ERP.
     *
     * @param array{force?:bool, trigger?:string, dryRun?:bool} $options
     */
    public function order(Connection $connection, Order $order, array $options = []): PushResult
    {
        $document = $this->buildOrder($connection, $order);

        if ($document === null) {
            return PushResult::rejected(Craft::t('erpy', 'An event handler held this order back.'));
        }

        return $this->deliver($connection, Entity::ORDER, $document, $order->id, $options);
    }

    /**
     * Build the canonical order and let handlers adjust it. Public because the preview screen and
     * the console command both need exactly this and nothing more.
     */
    public function buildOrder(Connection $connection, Order $order): ?ErpOrder
    {
        $document = Plugin::getInstance()->getOrders()->build($connection, $order);

        $event = new BuildDocumentEvent([
            'connection' => $connection,
            'entity' => Entity::ORDER,
            'document' => $document,
            'source' => $order,
        ]);
        $this->trigger(self::EVENT_BEFORE_PUSH, $event);

        return $event->isValid && $event->document instanceof ErpOrder ? $event->document : null;
    }

    /**
     * Send an order to every connection configured to receive one.
     *
     * @return array<string,PushResult> keyed by connection handle
     */
    public function orderToAll(Order $order, array $options = []): array
    {
        $results = [];

        foreach (Plugin::getInstance()->getConnections()->syncing(Entity::ORDER, Direction::PUSH) as $connection) {
            if ($connection->getStoreId() !== $order->storeId) {
                continue;
            }

            $results[$connection->handle] = $this->order($connection, $order, $options);
        }

        return $results;
    }

    /**
     * Hand an order to the queue.
     *
     * Order push is *always* queued from checkout, never inline. An ERP having a slow afternoon
     * must not be able to hold up a customer's payment confirmation, and an ERP being down must
     * not be able to stop the sale.
     */
    public function queueOrder(Order $order, int $delaySeconds = 0): void
    {
        if (!$order->id) {
            return;
        }

        $connections = Plugin::getInstance()->getConnections()->syncing(Entity::ORDER, Direction::PUSH);

        foreach ($connections as $connection) {
            if ($connection->getStoreId() !== $order->storeId) {
                continue;
            }

            Craft::$app->getQueue()->delay($delaySeconds)->push(new PushOrderJob([
                'connectionId' => $connection->id,
                'orderId' => $order->id,
                'orderNumber' => (string)$order->number,
            ]));
        }
    }

    // ---------------------------------------------------------------------------------------
    // Customers
    // ---------------------------------------------------------------------------------------

    public function customer(Connection $connection, User $user, array $options = []): PushResult
    {
        $account = Plugin::getInstance()->getAccounts()->forUser($user, $connection);

        $document = new ErpCustomer([
            'code' => $account->customerCode ?? '',
            'name' => $user->fullName ?: $user->username,
            'email' => $user->email,
            'currency' => $account?->currency,
            'priceListCode' => $account?->priceListCode,
            'customerGroupCode' => $account?->customerGroupCode,
            'paymentTermsCode' => $account?->paymentTermsCode,
            'taxId' => $account?->taxId,
        ]);

        $addresses = [];

        foreach ($user->getAddresses() as $address) {
            $addresses[] = new \justinholtweb\erpy\models\canonical\ErpAddress([
                'type' => \justinholtweb\erpy\models\canonical\ErpAddress::TYPE_SHIPPING,
                'fullName' => $address->fullName,
                'organization' => $address->organization,
                'addressLine1' => $address->addressLine1,
                'addressLine2' => $address->addressLine2,
                'locality' => $address->locality,
                'administrativeArea' => $address->administrativeArea,
                'postalCode' => $address->postalCode,
                'countryCode' => $address->countryCode,
            ]);
        }

        $document->addresses = $addresses;

        // A customer with no ERP code yet is a create; the natural key falls back to the email
        // address so the identity map still has something unique to hold on to.
        if ($document->code === '') {
            $document->code = (string)$user->email;
        }

        $event = new BuildDocumentEvent([
            'connection' => $connection,
            'entity' => Entity::CUSTOMER,
            'document' => $document,
            'source' => $user,
        ]);
        $this->trigger(self::EVENT_BEFORE_PUSH, $event);

        if (!$event->isValid) {
            return PushResult::rejected(Craft::t('erpy', 'An event handler held this customer back.'));
        }

        return $this->deliver($connection, Entity::CUSTOMER, $event->document, $user->id, $options);
    }

    // ---------------------------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------------------------

    /**
     * The one place an outbound document reaches an ERP.
     *
     * @param array{force?:bool, trigger?:string, dryRun?:bool} $options
     */
    public function deliver(Connection $connection, string $entity, ErpDocument $document, ?int $localId, array $options = []): PushResult
    {
        $force = (bool)($options['force'] ?? false);
        $dryRun = (bool)($options['dryRun'] ?? false);
        $trigger = $options['trigger'] ?? Run::TRIGGER_QUEUE;

        $connector = $connection->getConnector();

        if (!$connector) {
            return PushResult::rejected(Craft::t('erpy', 'The add-on for “{handle}” is not installed.', ['handle' => $connection->connector]));
        }

        if (!$connector::capabilities()->handles($entity, Direction::PUSH)) {
            return PushResult::rejected(Craft::t('erpy', '{connector} cannot write {entity}.', [
                'connector' => $connector::displayName(),
                'entity' => Entity::displayName($entity),
            ]));
        }

        $links = Plugin::getInstance()->getLinks();
        $key = $document->naturalKey();
        $link = $links->find($connection, $entity, $key);

        if ($link?->isDelivered() && !$force) {
            return PushResult::alreadyExists((string)$link->remoteId, $link->remoteKey);
        }

        if ($dryRun) {
            return PushResult::ok('(dry run)', $key);
        }

        // Two queue workers picking up the same order is not hypothetical — it is what happens
        // when a job is retried while the first attempt is still in flight.
        $mutex = Craft::$app->getMutex();
        $lockName = 'erpy:push:' . $connection->id . ':' . $entity . ':' . md5($key);

        if (!$mutex->acquire($lockName, 0)) {
            return PushResult::failed(Craft::t('erpy', 'Another worker is already sending this document.'));
        }

        $runs = Plugin::getInstance()->getRuns();
        $run = $runs->start($connection, $entity, Direction::PUSH, $trigger);
        $startedAt = microtime(true);

        try {
            // Re-read under the lock: the other worker may have finished between our check and
            // our acquiring it.
            $link = $links->find($connection, $entity, $key);

            if ($link?->isDelivered() && !$force) {
                $result = PushResult::alreadyExists((string)$link->remoteId, $link->remoteKey);
            } else {
                $result = $connector->pushDocument($entity, $document, $force ? $link?->remoteId : null);
            }
        } catch (Throwable $e) {
            $result = PushResult::failed($e->getMessage());
        } finally {
            $mutex->release($lockName);
        }

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $this->recordOutcome($connection, $entity, $document, $localId, $result, $run, $durationMs);

        return $result;
    }

    private function recordOutcome(Connection $connection, string $entity, ErpDocument $document, ?int $localId, PushResult $result, Run $run, int $durationMs): void
    {
        $plugin = Plugin::getInstance();
        $key = $document->naturalKey();

        if ($result->success) {
            $plugin->getLinks()->record(
                connection: $connection,
                entity: $entity,
                naturalKey: $key,
                localId: $localId,
                remoteId: $result->remoteId,
                remoteKey: $result->remoteKey,
                contentHash: $document->contentHash(),
                pushedAt: new DateTime(),
            );

            $plugin->getDeadLetters()->resolve($connection, $entity, $key);

            $plugin->getRuns()->item(
                run: $run,
                action: $result->duplicate ? RunItem::ACTION_SKIPPED : RunItem::ACTION_CREATED,
                naturalKey: $key,
                remoteId: $result->remoteId,
                localId: $localId,
                message: $result->duplicate ? Craft::t('erpy', 'The ERP already had it.') : null,
                durationMs: $durationMs,
            );
        } else {
            $plugin->getLinks()->record(
                connection: $connection,
                entity: $entity,
                naturalKey: $key,
                localId: $localId,
                error: $result->message,
            );

            $plugin->getDeadLetters()->record(
                connection: $connection,
                entity: $entity,
                direction: Direction::PUSH,
                naturalKey: $key,
                localId: $localId,
                document: $document->toArray(),
                error: (string)$result->message,
                retryable: $result->retryable,
            );

            $plugin->getRuns()->item(
                run: $run,
                action: RunItem::ACTION_FAILED,
                naturalKey: $key,
                localId: $localId,
                message: $result->message,
                durationMs: $durationMs,
            );
        }

        $plugin->getRuns()->finish($run);
    }

    /**
     * Re-send a dead-lettered document.
     *
     * Rebuilt from Commerce when the source record is still there, because a merchant who fixed
     * the order expects the fix to be what goes out. Only when it has been deleted does the
     * stored copy get used. A payment, customer or any other dead letter is sent as itself, never
     * by re-exporting the order it belongs to.
     *
     * Never forced. A retry is a second attempt at the send that failed, not a resend: if the
     * identity map says the ERP has the document by now — a queue retry got it there, or another
     * tab's "Retry" did — it is reported as already there. Forcing it handed the connector the
     * remote id, which every add-on reads as "skip the duplicate check and post another", so a
     * stale Retry button booked a second sales order or invoice. A deliberate resend is still
     * available as `erpy/orders/push --force`.
     */
    public function replay(Connection $connection, DeadLetter $letter): bool
    {
        if ($letter->entity === Entity::ORDER) {
            $order = $letter->localId
                ? Order::find()->id($letter->localId)->status(null)->one()
                : Plugin::getInstance()->getOrders()->findOrder($letter->naturalKey);

            if ($order) {
                return $this->order($connection, $order, ['trigger' => Run::TRIGGER_MANUAL])->success;
            }
        }

        $class = Entity::documentClass($letter->entity);
        /** @var ErpDocument $document */
        $document = new $class($letter->documentArray());

        return $this->deliver($connection, $letter->entity, $document, $letter->localId, [
            'trigger' => Run::TRIGGER_MANUAL,
        ])->success;
    }

    /**
     * Orders that should have reached the ERP and have not.
     *
     * This is the query a merchant actually wants at the end of the day, and it is deliberately
     * derived from the identity map rather than from the queue: a job that vanished leaves no
     * trace in the queue, but its order still has no remote id.
     *
     * @return Order[]
     */
    public function undelivered(Connection $connection, int $limit = 100): array
    {
        $orders = Order::find()
            ->isCompleted(true)
            ->storeId($connection->getStoreId())
            ->orderBy(['dateOrdered' => SORT_DESC])
            ->limit(max($limit, 200))
            ->all();

        $links = Plugin::getInstance()->getLinks();
        $keys = array_map(static fn(Order $order) => (string)$order->number, $orders);
        $known = $links->findMany($connection, Entity::ORDER, $keys);

        $missing = [];

        foreach ($orders as $order) {
            $link = $known[(string)$order->number] ?? null;

            if (!$link?->isDelivered()) {
                $missing[] = $order;
            }

            if (count($missing) >= $limit) {
                break;
            }
        }

        return $missing;
    }
}
