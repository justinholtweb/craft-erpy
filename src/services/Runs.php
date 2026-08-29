<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\models\RunItem;
use justinholtweb\erpy\Plugin;

/**
 * Run bookkeeping.
 *
 * Runs are written as they happen rather than at the end, so a sync that dies halfway leaves
 * evidence instead of nothing. That is also what makes a stuck run visible: a row that says
 * `running` with a start time an hour ago is the symptom of a queue worker that was killed.
 */
class Runs extends Component
{
    /** Per-run item rows are the biggest table Erpy writes, so they are batched. */
    private const ITEM_BUFFER = 100;

    private array $itemBuffer = [];

    public function start(Connection $connection, string $entity, int $direction, string $trigger, bool $dryRun = false, ?string $cursorBefore = null): Run
    {
        $run = new Run([
            'connectionId' => $connection->id,
            'entity' => $entity,
            'direction' => $direction,
            'trigger' => $trigger,
            'status' => Run::STATUS_RUNNING,
            'dryRun' => $dryRun,
            'cursorBefore' => $cursorBefore,
            'startedAt' => new DateTime(),
        ]);

        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->insert(Table::RUNS, [
            'connectionId' => $run->connectionId,
            'entity' => $run->entity,
            'direction' => $run->direction,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'dryRun' => $run->dryRun,
            'cursorBefore' => $run->cursorBefore,
            'startedAt' => Db::prepareDateForDb($run->startedAt),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $run->id = (int)Craft::$app->getDb()->getLastInsertID();
        Plugin::getInstance()->getLog()->setRunId($run->id);

        return $run;
    }

    public function finish(Run $run, ?string $status = null, ?string $message = null, ?string $cursorAfter = null): void
    {
        $this->flushItems();

        $run->finishedAt = new DateTime();
        $run->status = $status ?? $run->resolveStatus();
        $run->message = $message;
        $run->cursorAfter = $cursorAfter;
        $run->requests = Plugin::getInstance()->getLog()->requestCount();

        Craft::$app->getDb()->createCommand()->update(Table::RUNS, [
            'status' => $run->status,
            'created' => $run->created,
            'updated' => $run->updated,
            'skipped' => $run->skipped,
            'failed' => $run->failed,
            'requests' => $run->requests,
            'cursorAfter' => $run->cursorAfter,
            'message' => $message,
            'finishedAt' => Db::prepareDateForDb($run->finishedAt),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $run->id])->execute();

        Plugin::getInstance()->getLog()->setRunId(null);
    }

    /**
     * Record one document's outcome and keep the run's counters in step.
     */
    public function item(Run $run, string $action, ?string $naturalKey = null, ?string $remoteId = null, ?int $localId = null, ?string $message = null, array $changes = [], ?int $durationMs = null): void
    {
        match ($action) {
            RunItem::ACTION_CREATED => $run->created++,
            RunItem::ACTION_UPDATED => $run->updated++,
            RunItem::ACTION_FAILED => $run->failed++,
            default => $run->skipped++,
        };

        // Skipped records are the overwhelming majority of a delta sync and recording every one
        // would cost more storage than the catalogue itself. Failures always land.
        $settings = Plugin::getInstance()->getSettings();

        if ($action === RunItem::ACTION_SKIPPED && !$settings->recordSkippedItems) {
            return;
        }

        $now = Db::prepareDateForDb(new DateTime());

        $this->itemBuffer[] = [
            $run->id,
            $naturalKey,
            $remoteId,
            $localId,
            $action,
            $message,
            $changes ? json_encode($changes) : null,
            $durationMs,
            $now,
            $now,
            StringHelper::UUID(),
        ];

        if (count($this->itemBuffer) >= self::ITEM_BUFFER) {
            $this->flushItems();
        }
    }

    public function flushItems(): void
    {
        if ($this->itemBuffer === []) {
            return;
        }

        $rows = $this->itemBuffer;
        $this->itemBuffer = [];

        Craft::$app->getDb()->createCommand()->batchInsert(Table::RUN_ITEMS, [
            'runId', 'naturalKey', 'remoteId', 'localId', 'action',
            'message', 'changes', 'durationMs', 'dateCreated', 'dateUpdated', 'uid',
        ], $rows)->execute();
    }

    public function query(): Query
    {
        return (new Query())->from(Table::RUNS)->orderBy(['id' => SORT_DESC]);
    }

    public function getById(int $id): ?Run
    {
        $row = (new Query())->from(Table::RUNS)->where(['id' => $id])->one();

        return $row ? new Run($row) : null;
    }

    /**
     * @return Run[]
     */
    public function recent(?Connection $connection = null, int $limit = 50): array
    {
        $query = $this->query()->limit($limit);

        if ($connection) {
            $query->where(['connectionId' => $connection->id]);
        }

        return array_map(static fn(array $row) => new Run($row), $query->all());
    }

    public function lastFor(Connection $connection, string $entity, int $direction): ?Run
    {
        $row = $this->query()
            ->where([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'direction' => $direction,
            ])
            ->andWhere(['not', ['status' => Run::STATUS_RUNNING]])
            ->one();

        return $row ? new Run($row) : null;
    }

    /**
     * @return RunItem[]
     */
    public function items(int $runId, ?string $action = null, int $limit = 500): array
    {
        $query = (new Query())
            ->from(Table::RUN_ITEMS)
            ->where(['runId' => $runId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit);

        if ($action !== null) {
            $query->andWhere(['action' => $action]);
        }

        return array_map(static fn(array $row) => new RunItem($row), $query->all());
    }

    /**
     * Runs left marked `running` by a worker that died. Closed out so the connection screen does
     * not claim a sync has been going for three days, and so the scheduler is not blocked by a
     * lock that will never clear.
     */
    public function reapStale(int $minutes = 60): int
    {
        $cutoff = (new DateTime())->modify("-$minutes minutes");

        return (int)Craft::$app->getDb()->createCommand()->update(Table::RUNS, [
            'status' => Run::STATUS_FAILED,
            'message' => Craft::t('erpy', 'The run stopped without finishing — the queue worker was probably killed.'),
            'finishedAt' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], [
            'and',
            ['status' => Run::STATUS_RUNNING],
            ['<', 'startedAt', Db::prepareDateForDb($cutoff)],
        ])->execute();
    }

    public function isRunning(Connection $connection, string $entity, int $direction): bool
    {
        return $this->query()
            ->where([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'direction' => $direction,
                'status' => Run::STATUS_RUNNING,
            ])
            ->exists();
    }

    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->runRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::RUNS, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }
}
