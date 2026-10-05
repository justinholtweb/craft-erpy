<?php

namespace justinholtweb\erpy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use yii\console\ExitCode;

/**
 * Getting orders into the ERP, and finding the ones that did not make it.
 *
 *     php craft erpy/orders/missing acme
 *     php craft erpy/orders/push acme 1000123
 *     php craft erpy/orders/retry acme
 */
class OrdersController extends Controller
{
    public $defaultAction = 'missing';

    /** Send it again even though the identity map says the ERP already has it. */
    public bool $force = false;

    /** Build the document and show it without sending anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'push' => ['force', 'dryRun'],
            'retry' => ['force'],
            default => [],
        });
    }

    /**
     * Completed orders this connection has never delivered.
     *
     * Derived from the identity map rather than from the queue, because a job that vanished
     * leaves nothing in the queue — but its order still has no remote id.
     *
     * @param string $connection the connection handle
     */
    public function actionMissing(string $connection): int
    {
        $model = Plugin::getInstance()->getConnections()->getByHandle($connection);

        if (!$model) {
            $this->stderr("No connection with the handle “{$connection}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $orders = Plugin::getInstance()->getPush()->undelivered($model);

        if ($orders === []) {
            $this->stdout("Every completed order has reached the ERP.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(count($orders) . " order(s) have not reached the ERP:\n", Console::FG_YELLOW);

        foreach ($orders as $order) {
            $this->stdout('  ' . str_pad((string)$order->number, 34));
            $this->stdout(str_pad((string)$order->reference, 12));
            $this->stdout(($order->dateOrdered?->format('Y-m-d H:i') ?? '') . "\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Send one order.
     *
     * @param string $connection the connection handle
     * @param string $number the Commerce order number or reference
     */
    public function actionPush(string $connection, string $number): int
    {
        $plugin = Plugin::getInstance();
        $model = $plugin->getConnections()->getByHandle($connection);

        if (!$model) {
            $this->stderr("No connection with the handle “{$connection}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $order = $plugin->getOrders()->findOrder($number);

        if (!$order) {
            $this->stderr("No order numbered “{$number}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $document = $plugin->getPush()->buildOrder($model, $order);
            $this->stdout(json_encode($document?->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return ExitCode::OK;
        }

        $result = $plugin->getPush()->order($model, $order, [
            'force' => $this->force,
            'trigger' => Run::TRIGGER_CONSOLE,
        ]);

        if ($result->success) {
            $this->stdout(($result->duplicate ? 'Already there as ' : 'Sent as ') . $result->remoteId . "\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stderr('Failed: ' . $result->message . "\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Re-send everything that failed and could plausibly succeed.
     *
     * @param string $connection the connection handle
     */
    public function actionRetry(string $connection): int
    {
        $plugin = Plugin::getInstance();
        $model = $plugin->getConnections()->getByHandle($connection);

        if (!$model) {
            $this->stderr("No connection with the handle “{$connection}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($plugin->getDeadLetters()->open($model) as $letter) {
            // A document the ERP rejected outright will be rejected again unchanged; replaying it
            // just makes the log longer.
            if (!$letter->retryable) {
                $skipped++;
                continue;
            }

            $this->stdout('  ' . str_pad($letter->label(), 44));

            if ($plugin->getDeadLetters()->replay($letter)) {
                $this->stdout("sent\n", Console::FG_GREEN);
                $succeeded++;
            } else {
                $this->stdout("still failing\n", Console::FG_RED);
                $failed++;
            }
        }

        $this->stdout("\n$succeeded sent, $failed still failing, $skipped rejected outright and skipped.\n");

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
