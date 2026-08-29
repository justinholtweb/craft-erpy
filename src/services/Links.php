<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Link;
use yii\db\IntegrityException;

/**
 * The identity map.
 *
 * Every duplicate-order horror story in ERP integration comes down to somebody deciding, at write
 * time, whether a document is new. Erpy does not decide: it asks this table, and the table has a
 * unique index that will refuse the second answer even if two queue workers ask at once.
 */
class Links extends Component
{
    public function find(Connection $connection, string $entity, string $naturalKey): ?Link
    {
        $row = $this->query($connection, $entity)
            ->andWhere(['naturalKey' => $naturalKey])
            ->one();

        return $row ? new Link($row) : null;
    }

    public function findByRemoteId(Connection $connection, string $entity, string $remoteId): ?Link
    {
        $row = $this->query($connection, $entity)
            ->andWhere(['remoteId' => $remoteId])
            ->one();

        return $row ? new Link($row) : null;
    }

    public function findByLocalId(Connection $connection, string $entity, int $localId): ?Link
    {
        $row = $this->query($connection, $entity)
            ->andWhere(['localId' => $localId])
            ->one();

        return $row ? new Link($row) : null;
    }

    /**
     * @return Link[] keyed by natural key
     */
    public function findMany(Connection $connection, string $entity, array $naturalKeys): array
    {
        if ($naturalKeys === []) {
            return [];
        }

        $links = [];

        // Chunked because a full catalogue sync asks about several thousand SKUs at once and
        // MySQL's placeholder limit is not a theoretical concern at that size.
        foreach (array_chunk(array_values(array_unique($naturalKeys)), 500) as $chunk) {
            foreach ($this->query($connection, $entity)->andWhere(['naturalKey' => $chunk])->all() as $row) {
                $links[$row['naturalKey']] = new Link($row);
            }
        }

        return $links;
    }

    /**
     * Record — or update — the pairing.
     *
     * Written as an upsert on the unique key rather than a read-then-write, because the read and
     * the write are not one operation and two queue workers on the same order will interleave
     * them given the chance.
     */
    public function record(
        Connection $connection,
        string $entity,
        string $naturalKey,
        ?int $localId = null,
        ?string $localUid = null,
        ?string $remoteId = null,
        ?string $remoteKey = null,
        ?string $contentHash = null,
        ?float $quantity = null,
        ?DateTime $pulledAt = null,
        ?DateTime $pushedAt = null,
        ?string $error = null,
    ): Link {
        $now = Db::prepareDateForDb(new DateTime());

        $values = array_filter([
            'localId' => $localId,
            'localUid' => $localUid,
            'remoteId' => $remoteId,
            'remoteKey' => $remoteKey,
            'contentHash' => $contentHash,
            'quantity' => $quantity,
            'lastPulledAt' => $pulledAt ? Db::prepareDateForDb($pulledAt) : null,
            'lastPushedAt' => $pushedAt ? Db::prepareDateForDb($pushedAt) : null,
        ], static fn($value) => $value !== null);

        // An error is cleared by a successful write, so it is set unconditionally rather than
        // being filtered out when null.
        $values['lastError'] = $error;
        $values['dateUpdated'] = $now;

        $db = Craft::$app->getDb();

        try {
            $db->createCommand()->upsert(Table::LINKS, array_merge([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'naturalKey' => $naturalKey,
                'dateCreated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ], $values), $values)->execute();
        } catch (IntegrityException $e) {
            // Two workers raced and both lost the insert. The row exists; take it as written.
            Craft::info('Erpy link upsert raced and resolved: ' . $e->getMessage(), 'erpy');
        }

        return $this->find($connection, $entity, $naturalKey)
            ?? new Link(['connectionId' => $connection->id, 'entity' => $entity, 'naturalKey' => $naturalKey]);
    }

    /**
     * Whether this document has changed since the last time it was synced.
     *
     * The reason a nightly 40,000-SKU pull finishes in minutes: an unchanged record costs one
     * hash comparison instead of an element save, and most records are unchanged.
     */
    public function isUnchanged(Connection $connection, string $entity, string $naturalKey, string $contentHash): bool
    {
        $existing = (new Query())
            ->select(['contentHash'])
            ->from(Table::LINKS)
            ->where([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'naturalKey' => $naturalKey,
            ])
            ->scalar();

        return is_string($existing) && $existing !== '' && $existing === $contentHash;
    }

    public function forget(Connection $connection, string $entity, string $naturalKey): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::LINKS, [
                'connectionId' => $connection->id,
                'entity' => $entity,
                'naturalKey' => $naturalKey,
            ])
            ->execute();
    }

    /**
     * Drop every pairing for an entity, so the next sync treats the ERP's records as new.
     *
     * Destructive in a specific way merchants need to understand: it does not delete anything in
     * Commerce, but the next pull will re-match by natural key and anything that no longer
     * matches will be created again.
     */
    public function forgetAll(Connection $connection, ?string $entity = null): int
    {
        $condition = ['connectionId' => $connection->id];

        if ($entity !== null) {
            $condition['entity'] = $entity;
        }

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LINKS, $condition)->execute();
    }

    /**
     * Everything this connection has recorded against one local record — every shipment posted
     * for an order, say.
     *
     * @return Link[]
     */
    public function forLocalId(Connection $connection, string $entity, int $localId): array
    {
        return array_map(
            static fn(array $row) => new Link($row),
            $this->query($connection, $entity)->andWhere(['localId' => $localId])->all(),
        );
    }

    public function countFor(Connection $connection, string $entity): int
    {
        return (int)$this->query($connection, $entity)->count();
    }

    /**
     * Links whose local record has been deleted out from under them — a product removed in
     * Commerce that the ERP still sends. Shown on the connection screen because it is usually the
     * explanation for "why does this keep re-creating things".
     */
    public function orphanCount(Connection $connection, string $entity): int
    {
        return (int)(new Query())
            ->from(['l' => Table::LINKS])
            ->leftJoin(
                ['e' => \craft\db\Table::ELEMENTS],
                '[[e.id]] = [[l.localId]] AND [[e.dateDeleted]] IS NULL',
            )
            ->where([
                'l.connectionId' => $connection->id,
                'l.entity' => $entity,
                'e.id' => null,
            ])
            ->andWhere(['not', ['l.localId' => null]])
            ->count();
    }

    private function query(Connection $connection, string $entity): Query
    {
        return (new Query())
            ->from(Table::LINKS)
            ->where([
                'connectionId' => $connection->id,
                'entity' => $entity,
            ]);
    }
}
