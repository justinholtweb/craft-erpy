<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;

/**
 * Delta-sync watermarks.
 *
 * The rule that keeps a delta sync honest: the watermark advances to the *start* of the run, not
 * to the newest record seen and not to the moment the run finished. A record modified while the
 * run was in flight would otherwise fall in the gap and never be seen again. Re-fetching a
 * handful of records costs one content-hash comparison; missing one costs a wrong price.
 */
class Cursors extends Component
{
    /**
     * How far back to overlap, in seconds, on top of the run-start rule. ERP clocks drift and
     * several of these systems stamp `modifiedAt` when a transaction *begins*.
     */
    private const OVERLAP_SECONDS = 120;

    public function watermark(Connection $connection, string $entity, int $direction): ?DateTime
    {
        $row = $this->row($connection, $entity, $direction);

        if (!$row || empty($row['watermark'])) {
            return null;
        }

        // Craft stores datetimes as bare UTC strings; constructing one without naming UTC reads
        // it as site-local and quietly shifts every watermark by the site's offset.
        $watermark = new DateTime($row['watermark'], new \DateTimeZone('UTC'));

        return $watermark->modify('-' . self::OVERLAP_SECONDS . ' seconds');
    }

    public function cursor(Connection $connection, string $entity, int $direction): ?string
    {
        $row = $this->row($connection, $entity, $direction);

        return $row['cursor'] ?? null;
    }

    public function lastRunAt(Connection $connection, string $entity, int $direction): ?DateTime
    {
        $row = $this->row($connection, $entity, $direction);

        return !empty($row['lastRunAt']) ? new DateTime($row['lastRunAt'], new \DateTimeZone('UTC')) : null;
    }

    /**
     * Move the watermark forward. `$runStartedAt` is the moment the run began, and passing
     * anything else here is the bug this method exists to prevent.
     */
    public function advance(Connection $connection, string $entity, int $direction, DateTimeInterface $runStartedAt, ?string $cursor = null): void
    {
        $this->upsert($connection, $entity, $direction, [
            'watermark' => Db::prepareDateForDb($runStartedAt),
            'cursor' => $cursor,
            'lastRunAt' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    /** Record that a run happened without moving the watermark — a failed run must not. */
    public function touch(Connection $connection, string $entity, int $direction): void
    {
        $this->upsert($connection, $entity, $direction, [
            'lastRunAt' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    /**
     * Forget the watermark, so the next run is a full sync.
     */
    public function reset(Connection $connection, ?string $entity = null, ?int $direction = null): int
    {
        $condition = ['connectionId' => $connection->id];

        if ($entity !== null) {
            $condition['entity'] = $entity;
        }

        if ($direction !== null) {
            $condition['direction'] = $direction;
        }

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::CURSORS, $condition)->execute();
    }

    /**
     * Whether enough time has passed for a scheduled sync of this entity to be due.
     */
    public function isDue(Connection $connection, string $entity, int $direction): bool
    {
        $interval = $connection->intervalFor($entity);

        if ($interval <= 0) {
            return false;
        }

        $lastRunAt = $this->lastRunAt($connection, $entity, $direction);

        if ($lastRunAt === null) {
            return true;
        }

        return (time() - $lastRunAt->getTimestamp()) >= $interval;
    }

    private function row(Connection $connection, string $entity, int $direction): ?array
    {
        return (new Query())
            ->from(Table::CURSORS)
            ->where([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'direction' => $direction,
            ])
            ->one() ?: null;
    }

    private function upsert(Connection $connection, string $entity, int $direction, array $values): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $values['dateUpdated'] = $now;

        Craft::$app->getDb()->createCommand()->upsert(Table::CURSORS, array_merge([
            'connectionId' => $connection->id,
            'entity' => $entity,
            'direction' => $direction,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ], $values), $values)->execute();
    }
}
