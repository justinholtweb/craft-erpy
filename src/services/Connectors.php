<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\erpy\base\ConnectorInterface;
use justinholtweb\erpy\connectors\MockConnector;
use justinholtweb\erpy\models\Connection;
use Throwable;

/**
 * The registry every add-on plugs into.
 *
 * This is the whole extension model, and it is deliberately the same shape as every other
 * "register your types" event in Craft, so an add-on author already knows how to write it:
 *
 *     Event::on(
 *         Connectors::class,
 *         Connectors::EVENT_REGISTER_CONNECTORS,
 *         static function(RegisterComponentTypesEvent $event) {
 *             $event->types[] = BusinessCentralConnector::class;
 *         }
 *     );
 *
 * One add-on may register several connectors — the Sage add-on registers four, because Sage sells
 * four unrelated products under one name and a merchant should only have to install one thing.
 */
class Connectors extends Component
{
    /**
     * @event RegisterComponentTypesEvent fired when connector classes are being collected
     */
    public const EVENT_REGISTER_CONNECTORS = 'registerConnectors';

    /** @var array<string,class-string<ConnectorInterface>>|null */
    private ?array $connectors = null;

    /**
     * @return array<string,class-string<ConnectorInterface>> handle => class
     */
    public function all(): array
    {
        if ($this->connectors !== null) {
            return $this->connectors;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => [
                MockConnector::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_CONNECTORS, $event);

        $connectors = [];

        foreach ($event->types as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, ConnectorInterface::class)) {
                Craft::warning("Erpy ignored a registered connector that is not a " . ConnectorInterface::class . ': ' . (is_string($class) ? $class : gettype($class)), 'erpy');
                continue;
            }

            $handle = $class::handle();

            // Two add-ons claiming one handle would silently swap which ERP a live connection
            // talks to, so the first registration wins and the second is reported loudly.
            if (isset($connectors[$handle])) {
                Craft::warning("Erpy has two connectors claiming the handle “$handle”: {$connectors[$handle]} and $class. The second was ignored.", 'erpy');
                continue;
            }

            $connectors[$handle] = $class;
        }

        return $this->connectors = $connectors;
    }

    public function has(string $handle): bool
    {
        return isset($this->all()[$handle]);
    }

    /**
     * @return class-string<ConnectorInterface>|null
     */
    public function classFor(string $handle): ?string
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * A connector bound to a connection, or null if its add-on is not installed.
     *
     * Returning null rather than throwing is deliberate: a merchant who uninstalls an add-on
     * should find a connection that says "install the Business Central add-on to use this", not a
     * control panel that 500s.
     */
    public function create(Connection $connection): ?ConnectorInterface
    {
        $class = $this->classFor($connection->connector);

        if ($class === null) {
            return null;
        }

        try {
            /** @var ConnectorInterface $connector */
            $connector = new $class();
            $connector->setConnection($connection);

            return $connector;
        } catch (Throwable $e) {
            Craft::error("Erpy could not instantiate $class: " . $e->getMessage(), 'erpy');

            return null;
        }
    }

    /**
     * Connectors grouped by vendor and sorted for the picker, so a merchant scanning for "Sage"
     * finds all four in one place.
     *
     * @return array<string,array<string,class-string<ConnectorInterface>>>
     */
    public function byVendor(): array
    {
        $grouped = [];

        foreach ($this->all() as $handle => $class) {
            $grouped[$class::vendor() ?: Craft::t('erpy', 'Other')][$handle] = $class;
        }

        uksort($grouped, static fn(string $a, string $b) => strcasecmp($a, $b));

        foreach ($grouped as &$connectors) {
            uasort($connectors, static fn(string $a, string $b) => strcasecmp($a::displayName(), $b::displayName()));
        }

        return $grouped;
    }

    /**
     * A flat list for a select field.
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->byVendor() as $vendor => $connectors) {
            foreach ($connectors as $handle => $class) {
                $options[] = [
                    'value' => $handle,
                    'label' => $class::displayName(),
                    'vendor' => $vendor,
                ];
            }
        }

        return $options;
    }

    /**
     * Everything the control panel needs to describe a connector, already resolved.
     *
     * Twig cannot call a static method on a class name — it sees a string and refuses — so
     * templates are handed plain arrays rather than class strings. Passing a class name to a
     * template at all was the bug this exists to prevent.
     *
     * @return array<string,array<int,array<string,mixed>>> vendor => descriptors
     */
    public function describe(): array
    {
        $grouped = [];

        foreach ($this->byVendor() as $vendor => $connectors) {
            foreach ($connectors as $handle => $class) {
                $capabilities = $class::capabilities();

                $grouped[$vendor][] = [
                    'handle' => $handle,
                    'name' => $class::displayName(),
                    'vendor' => $vendor,
                    'description' => $class::description(),
                    'setupUrl' => $class::setupUrl(),
                    'entities' => $capabilities->entities(),
                    'webhooks' => $capabilities->supportsWebhooks(),
                    'sandbox' => $capabilities->hasSandbox(),
                ];
            }
        }

        return $grouped;
    }

    /**
     * One connector's descriptor, or null when its add-on is not installed.
     */
    public function describeOne(string $handle): ?array
    {
        foreach ($this->describe() as $connectors) {
            foreach ($connectors as $descriptor) {
                if ($descriptor['handle'] === $handle) {
                    return $descriptor;
                }
            }
        }

        return null;
    }

    /**
     * Forget what is registered. Only the test suite needs this — installing an add-on restarts
     * PHP anyway.
     */
    public function reset(): void
    {
        $this->connectors = null;
    }
}
