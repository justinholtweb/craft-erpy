<?php

namespace justinholtweb\erpy\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\erpy\Plugin;

/**
 * Send one order to one ERP, from the queue.
 *
 * Queued rather than inline because this runs off the back of a completed checkout: an ERP having
 * a slow afternoon must never be able to delay a customer's confirmation page, and an ERP being
 * down must never be able to stop a sale.
 */
class PushOrderJob extends BaseJob
{
    public ?int $connectionId = null;

    public ?int $orderId = null;

    public string $orderNumber = '';

    public bool $force = false;

    public function execute($queue): void
    {
        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->connectionId);

        if (!$connection || !$connection->enabled) {
            return;
        }

        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            // The order was deleted between the checkout and the worker picking this up. Nothing
            // to send, and nothing worth failing over.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('erpy', 'Building the document'));

        $result = Plugin::getInstance()->getPush()->order($connection, $order, [
            'force' => $this->force,
            'trigger' => \justinholtweb\erpy\models\Run::TRIGGER_QUEUE,
        ]);

        $this->setProgress($queue, 1);

        // A retryable failure throws so Craft's queue backs off and tries again. A rejection —
        // the ERP saying the document itself is wrong — has already been dead-lettered, and
        // throwing would just make the queue repeat a request that cannot ever succeed.
        if (!$result->success && $result->retryable) {
            throw new \RuntimeException(sprintf(
                'Erpy could not send order %s to %s: %s',
                $this->orderNumber ?: $this->orderId,
                $connection->name,
                $result->message ?? 'no reason given',
            ));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('erpy', 'Sending order {number} to the ERP', [
            'number' => $this->orderNumber ?: $this->orderId,
        ]);
    }

    public function getTtr(): int
    {
        return 300;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < Plugin::getInstance()->getSettings()->pushMaxAttempts;
    }
}
