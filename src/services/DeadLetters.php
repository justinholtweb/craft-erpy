<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\DeadLetter;
use justinholtweb\erpy\Plugin;

/**
 * Documents the ERP would not accept.
 *
 * The alternative — retry forever, or drop it and log a line — is how integrations lose orders.
 * A dead letter keeps the exact document that failed, so once the merchant has fixed the cause
 * (added the missing item, raised the credit limit, corrected a country code) the same document
 * can be replayed rather than reconstructed from a Commerce order that has since changed.
 */
class DeadLetters extends Component
{
    public function record(
        Connection $connection,
        string $entity,
        int $direction,
        string $naturalKey,
        ?int $localId,
        array $document,
        string $error,
        bool $retryable = true,
    ): DeadLetter {
        $existing = $this->find($connection, $entity, $naturalKey);
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

        if ($existing && $existing->resolvedAt === null) {
            $db->createCommand()->update(Table::DEAD_LETTERS, [
                'document' => json_encode($document),
                'error' => $error,
                'attempts' => $existing->attempts + 1,
                'retryable' => $retryable,
                'lastAttemptAt' => $now,
                'dateUpdated' => $now,
            ], ['id' => $existing->id])->execute();

            return $this->getById($existing->id) ?? $existing;
        }

        $db->createCommand()->insert(Table::DEAD_LETTERS, [
            'connectionId' => $connection->id,
            'entity' => $entity,
            'direction' => $direction,
            'naturalKey' => $naturalKey,
            'localId' => $localId,
            'document' => json_encode($document),
            'error' => $error,
            'attempts' => 1,
            'retryable' => $retryable,
            'lastAttemptAt' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return $this->getById((int)$db->getLastInsertID()) ?? new DeadLetter();
    }

    /**
     * Mark it dealt with. Called by a successful push of the same document as well as by the
     * merchant clicking "dismiss", so a dead letter that fixes itself disappears on its own.
     */
    public function resolve(Connection $connection, string $entity, string $naturalKey): void
    {
        Craft::$app->getDb()->createCommand()->update(Table::DEAD_LETTERS, [
            'resolvedAt' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], [
            'connectionId' => $connection->id,
            'entity' => $entity,
            'naturalKey' => $naturalKey,
            'resolvedAt' => null,
        ])->execute();
    }

    public function find(Connection $connection, string $entity, string $naturalKey): ?DeadLetter
    {
        $row = (new Query())
            ->from(Table::DEAD_LETTERS)
            ->where([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'naturalKey' => $naturalKey,
                'resolvedAt' => null,
            ])
            ->one();

        return $row ? new DeadLetter($row) : null;
    }

    public function getById(int $id): ?DeadLetter
    {
        $row = (new Query())->from(Table::DEAD_LETTERS)->where(['id' => $id])->one();

        return $row ? new DeadLetter($row) : null;
    }

    public function query(): Query
    {
        return (new Query())->from(Table::DEAD_LETTERS)->orderBy(['id' => SORT_DESC]);
    }

    /**
     * @return DeadLetter[]
     */
    public function open(?Connection $connection = null, int $limit = 200): array
    {
        $query = $this->query()->where(['resolvedAt' => null])->limit($limit);

        if ($connection) {
            $query->andWhere(['connectionId' => $connection->id]);
        }

        return array_map(static fn(array $row) => new DeadLetter($row), $query->all());
    }

    public function openCount(?Connection $connection = null): int
    {
        $query = $this->query()->where(['resolvedAt' => null]);

        if ($connection) {
            $query->andWhere(['connectionId' => $connection->id]);
        }

        return (int)$query->count();
    }

    /**
     * Send it again.
     *
     * Deliberately rebuilds the document from Commerce when it still can, and falls back to the
     * stored copy when it cannot: a merchant who fixed the order in Commerce expects the fix to
     * be what gets sent, and a merchant whose order has been deleted still wants the ERP to
     * receive what was promised.
     */
    public function replay(DeadLetter $letter): bool
    {
        $connection = Plugin::getInstance()->getConnections()->getById((int)$letter->connectionId);

        if (!$connection) {
            return false;
        }

        if ($letter->direction === Direction::PUSH) {
            $result = Plugin::getInstance()->getPush()->replay($connection, $letter);
        } else {
            $result = Plugin::getInstance()->getSync()->replayInbound($connection, $letter);
        }

        if ($result) {
            $this->resolve($connection, $letter->entity, $letter->naturalKey);
        }

        return $result;
    }

    public function delete(int $id): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()->delete(Table::DEAD_LETTERS, ['id' => $id])->execute();
    }

    public function prune(int $days = 90): int
    {
        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::DEAD_LETTERS, [
            'and',
            ['not', ['resolvedAt' => null]],
            ['<', 'resolvedAt', Db::prepareDateForDb($cutoff)],
        ])->execute();
    }
}
