<?php

namespace justinholtweb\erpy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\Plugin;
use yii\console\ExitCode;

/**
 * Connections and connectors, from the command line.
 *
 *     php craft erpy/connections/list
 *     php craft erpy/connections/test acme
 *     php craft erpy/connections/connectors
 */
class ConnectionsController extends Controller
{
    public $defaultAction = 'list';

    public function actionList(): int
    {
        $connections = Plugin::getInstance()->getConnections()->all();

        if ($connections === []) {
            $this->stdout("No connections yet. Add one in the control panel under Erpy → Connections.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($connections as $connection) {
            $connector = $connection->getConnector();

            $this->stdout(str_pad($connection->handle, 20), Console::BOLD);
            $this->stdout(str_pad($connector ? $connector::displayName() : $connection->connector, 36));
            $this->stdout($connection->enabled ? 'enabled' : 'disabled', $connection->enabled ? Console::FG_GREEN : Console::FG_GREY);

            if (!$connector) {
                $this->stdout('  (add-on not installed)', Console::FG_RED);
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Every connector this install knows about, including the add-ons.
     */
    public function actionConnectors(): int
    {
        foreach (Plugin::getInstance()->getConnectors()->byVendor() as $vendor => $connectors) {
            $this->stdout("\n$vendor\n", Console::BOLD);

            foreach ($connectors as $handle => $class) {
                $capabilities = $class::capabilities();
                $entities = [];

                foreach ($capabilities->entities() as $entity) {
                    $direction = $capabilities->directionFor($entity);
                    $arrow = match ($direction) {
                        Direction::PULL => '←',
                        Direction::PUSH => '→',
                        default => '↔',
                    };
                    $entities[] = $arrow . ' ' . $entity;
                }

                $this->stdout('  ' . str_pad($handle, 20));
                $this->stdout(str_pad($class::displayName(), 38));
                $this->stdout(implode(', ', $entities) . "\n", Console::FG_GREY);
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Prove a connection's credentials work.
     *
     * @param string $handle the connection handle
     */
    public function actionTest(string $handle): int
    {
        $connection = Plugin::getInstance()->getConnections()->getByHandle($handle);

        if (!$connection) {
            $this->stderr("No connection with the handle “{$handle}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $connector = $connection->getConnector();

        if (!$connector) {
            $this->stderr("The add-on for “{$connection->connector}” is not installed.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $connector->test();

        $this->stdout($result->ok ? "✓ " : "✗ ", $result->ok ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout($result->message . ($result->durationMs !== null ? "  ({$result->durationMs}ms)" : '') . "\n");

        foreach ($result->details as $label => $value) {
            $this->stdout('    ' . str_pad((string)$label, 24) . $value . "\n", Console::FG_GREY);
        }

        foreach ($result->hints as $hint) {
            $this->stdout('    → ' . $hint . "\n", Console::FG_YELLOW);
        }

        return $result->ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Forget every pairing between this connection and Commerce.
     *
     * Nothing in Commerce is deleted, but the next sync re-matches everything by SKU or customer
     * code — so anything that no longer matches will be created again.
     *
     * @param string $handle the connection handle
     * @param string|null $entity leave off to forget every entity
     */
    public function actionForget(string $handle, ?string $entity = null): int
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getByHandle($handle);

        if (!$connection) {
            $this->stderr("No connection with the handle “{$handle}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($entity !== null && !Entity::exists($entity)) {
            $this->stderr("“{$entity}” is not a known entity.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (!$this->confirm('This forgets which Commerce record is which ERP record. Continue?')) {
            return ExitCode::OK;
        }

        $count = $plugin->getLinks()->forgetAll($connection, $entity);
        $plugin->getCursors()->reset($connection, $entity);

        $this->stdout("Forgot $count pairing" . ($count === 1 ? '' : 's') . ".\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
