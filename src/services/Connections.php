<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\records\ConnectionRecord;
use Throwable;

/**
 * Connections live in the database rather than in project config.
 *
 * That is a considered break with the family habit. Project config would push a staging site's
 * ERP credentials into production on the next deploy, and a sandbox connection that quietly
 * becomes a live one is the single worst thing this plugin could do. Connections are environment
 * data; the mapping rules that go with them are exportable on their own.
 */
class Connections extends Component
{
    /** @var array<int,Connection>|null */
    private ?array $connections = null;

    /**
     * @return Connection[]
     */
    public function all(): array
    {
        if ($this->connections !== null) {
            return $this->connections;
        }

        $rows = (new Query())
            ->from(Table::CONNECTIONS)
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        $connections = [];

        foreach ($rows as $row) {
            $connection = new Connection($row);
            $connections[$connection->id] = $connection;
        }

        return $this->connections = $connections;
    }

    /**
     * @return Connection[]
     */
    public function enabled(): array
    {
        return array_filter($this->all(), static fn(Connection $c) => $c->enabled);
    }

    public function getById(int $id): ?Connection
    {
        return $this->all()[$id] ?? null;
    }

    public function getByHandle(string $handle): ?Connection
    {
        foreach ($this->all() as $connection) {
            if ($connection->handle === $handle) {
                return $connection;
            }
        }

        return null;
    }

    public function getByUid(string $uid): ?Connection
    {
        foreach ($this->all() as $connection) {
            if ($connection->uid === $uid) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Every enabled connection that syncs the given entity in the given direction.
     *
     * @return Connection[]
     */
    public function syncing(string $entity, ?int $direction = null): array
    {
        return array_values(array_filter(
            $this->enabled(),
            static fn(Connection $c) => $c->syncs($entity, $direction),
        ));
    }

    public function save(Connection $connection, bool $runValidation = true): bool
    {
        if ($runValidation && !$connection->validate()) {
            return false;
        }

        $isNew = !$connection->id;
        $record = $isNew ? new ConnectionRecord() : ConnectionRecord::findOne($connection->id);

        if (!$record) {
            throw new \InvalidArgumentException("No connection with the id “{$connection->id}”.");
        }

        $record->name = $connection->name;
        $record->handle = $connection->handle;
        $record->connector = $connection->connector;
        $record->enabled = $connection->enabled;
        $record->storeId = $connection->storeId;
        $record->sync = json_encode($connection->sync);

        // A blank secret in a posted form means "leave it alone", not "erase it". The CP never
        // sends a saved credential back to the browser, so it cannot send it back here either.
        $record->settings = json_encode($this->mergeSettings($connection, $isNew));
        $record->tokens = json_encode($connection->tokens);

        if ($isNew) {
            $record->sortOrder = (int)(new Query())->from(Table::CONNECTIONS)->max('[[sortOrder]]') + 1;
        }

        $record->save(false);

        $connection->id = $record->id;
        $connection->uid = $record->uid;
        $connection->settings = json_decode((string)$record->settings, true) ?: [];
        $this->connections = null;

        return true;
    }

    /**
     * Persist just the token bag, without touching anything else or running validation.
     *
     * OAuth refreshes happen mid-request, often mid-sync, sometimes concurrently. Saving the
     * whole model there would write back whatever else happened to be in memory.
     */
    public function saveTokens(Connection $connection): void
    {
        if (!$connection->id) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::CONNECTIONS, [
                'tokens' => json_encode($connection->tokens),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['id' => $connection->id])
            ->execute();

        $this->connections = null;
    }

    private function mergeSettings(Connection $connection, bool $isNew): array
    {
        if ($isNew) {
            return $connection->settings;
        }

        $existing = (new Query())
            ->select(['settings'])
            ->from(Table::CONNECTIONS)
            ->where(['id' => $connection->id])
            ->scalar();

        $existing = json_decode((string)$existing, true) ?: [];
        $merged = $connection->settings;

        $connector = $connection->getConnector();

        foreach ($connector ? \justinholtweb\erpy\base\Field::secretNames($connector::settingsFields()) : [] as $name) {
            if (($merged[$name] ?? '') === '' && ($existing[$name] ?? '') !== '') {
                $merged[$name] = $existing[$name];
            }
        }

        return $merged;
    }

    public function delete(Connection $connection): bool
    {
        if (!$connection->id) {
            return false;
        }

        $record = ConnectionRecord::findOne($connection->id);

        if (!$record) {
            return false;
        }

        try {
            $record->delete();
        } catch (Throwable $e) {
            Craft::error('Erpy could not delete a connection: ' . $e->getMessage(), 'erpy');

            return false;
        }

        $this->connections = null;

        return true;
    }

    /**
     * A connection copied for a second company or a sandbox, credentials deliberately left blank.
     */
    public function duplicate(Connection $connection): Connection
    {
        $clone = new Connection([
            'name' => Craft::t('erpy', '{name} (copy)', ['name' => $connection->name]),
            'handle' => $connection->handle . StringHelper::randomString(4),
            'connector' => $connection->connector,
            'enabled' => false,
            'storeId' => $connection->storeId,
            'sync' => $connection->sync,
            'settings' => [],
        ]);

        $connector = $connection->getConnector();
        $secrets = $connector ? \justinholtweb\erpy\base\Field::secretNames($connector::settingsFields()) : [];

        foreach ($connection->settings as $key => $value) {
            if (!in_array($key, $secrets, true)) {
                $clone->settings[$key] = $value;
            }
        }

        return $clone;
    }

    public function clearCache(): void
    {
        $this->connections = null;
    }
}
