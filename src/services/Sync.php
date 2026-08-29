<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use DateTime;
use justinholtweb\erpy\base\ApplyResult;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\events\ApplyDocumentEvent;
use justinholtweb\erpy\events\RunEvent;
use justinholtweb\erpy\models\canonical\ErpDocument;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\DeadLetter;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\models\RunItem;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * The pull engine.
 *
 * This is Erpy's first invariant: **every inbound sync runs here**. The control panel's "Sync
 * now" button, the queue job, the scheduled run, the console command and a webhook all end up in
 * `run()`, so paging, delta watermarks, the identity map, dry runs, retries, dead letters and the
 * run log cannot behave differently depending on how a sync was started. A connector cannot
 * bypass any of it, because a connector is only ever handed one page to translate.
 */
class Sync extends Component
{
    /**
     * @event RunEvent fired before a sync run starts; set `$event->isValid = false` to stop it
     */
    public const EVENT_BEFORE_RUN = 'beforeRun';

    /**
     * @event RunEvent fired after a sync run finishes, successfully or not
     */
    public const EVENT_AFTER_RUN = 'afterRun';

    /**
     * @event ApplyDocumentEvent fired for each document before it is written to Commerce
     */
    public const EVENT_BEFORE_APPLY_DOCUMENT = 'beforeApplyDocument';

    /** A runaway page loop is worse than an incomplete sync; this is the backstop. */
    private const MAX_PAGES = 5000;

    /**
     * Pull one entity from one ERP.
     *
     * @param array{
     *     trigger?:string, dryRun?:bool, full?:bool, ids?:string[],
     *     limit?:int, maxPages?:int, force?:bool
     * } $options
     */
    public function run(Connection $connection, string $entity, array $options = []): Run
    {
        $trigger = $options['trigger'] ?? Run::TRIGGER_MANUAL;
        $dryRun = (bool)($options['dryRun'] ?? false);
        $full = (bool)($options['full'] ?? false);
        $ids = (array)($options['ids'] ?? []);
        $force = (bool)($options['force'] ?? false);

        $guard = $this->guard($connection, $entity, $force);

        if ($guard !== null) {
            return $guard;
        }

        $connector = $connection->getConnector();
        $runs = Plugin::getInstance()->getRuns();
        $cursors = Plugin::getInstance()->getCursors();
        $map = Plugin::getInstance()->getMapping()->get($connection, $entity, Direction::PULL);

        // The watermark is read, and the run's start time is captured, *before* the first request
        // goes out. Advancing to anything later would open a window in which a record modified
        // mid-run is never seen again.
        $startedAt = new DateTime();
        $since = ($full || $ids) ? null : $cursors->watermark($connection, $entity, Direction::PULL);

        $run = $runs->start($connection, $entity, Direction::PULL, $trigger, $dryRun, $since?->format('c'));

        $event = new RunEvent(['run' => $run, 'connection' => $connection]);
        $this->trigger(self::EVENT_BEFORE_RUN, $event);

        if (!$event->isValid) {
            $runs->finish($run, Run::STATUS_SKIPPED, Craft::t('erpy', 'Stopped by an event handler.'));

            return $run;
        }

        $criteria = new FetchCriteria([
            'since' => $since,
            'limit' => $options['limit'] ?? $connector::capabilities()->pageSizeFor($entity),
            'ids' => $ids,
            'filters' => $connection->filtersFor($entity),
            'company' => $connection->getSetting('company') ?: null,
        ]);

        $maxPages = min((int)($options['maxPages'] ?? self::MAX_PAGES), self::MAX_PAGES);
        $page = 0;
        $seenCursors = [];
        $fatal = null;

        try {
            while ($page < $maxPages) {
                $page++;
                $result = $connector->fetchPage($entity, $criteria);

                foreach ($result->items as $document) {
                    if (!$document instanceof ErpDocument) {
                        $runs->item($run, RunItem::ACTION_FAILED, null, null, null, Craft::t('erpy', 'The connector returned something that is not a canonical document.'));
                        continue;
                    }

                    $this->applyOne($connection, $entity, $document, $map, $run, $dryRun);
                }

                if (!$result->hasMore()) {
                    break;
                }

                // A connector that hands back the cursor it was just given would page forever.
                if (in_array($result->cursor, $seenCursors, true)) {
                    Plugin::getInstance()->getLog()->error(
                        $connection,
                        Craft::t('erpy', '{connector} repeated a page cursor, so paging was stopped.', [
                            'connector' => $connector::displayName(),
                        ]),
                        $run->id,
                    );
                    break;
                }

                $seenCursors[] = $result->cursor;
                $criteria = $criteria->withCursor($result->cursor);
            }
        } catch (Throwable $e) {
            $fatal = $e;
            Plugin::getInstance()->getLog()->error($connection, $e->getMessage(), $run->id);
        }

        $status = $fatal ? Run::STATUS_FAILED : null;
        $message = $fatal?->getMessage();

        // The watermark only moves when the ERP was actually reachable and nothing blew up.
        // A failed run that advanced its watermark would silently skip everything it missed.
        if (!$fatal && !$dryRun && !$ids) {
            $cursors->advance($connection, $entity, Direction::PULL, $startedAt);
        } else {
            $cursors->touch($connection, $entity, Direction::PULL);
        }

        $runs->finish($run, $status, $message);
        Plugin::getInstance()->getCatalog()->resetIndex();

        $this->trigger(self::EVENT_AFTER_RUN, new RunEvent(['run' => $run, 'connection' => $connection]));

        return $run;
    }

    /**
     * Every entity this connection pulls, in dependency order.
     *
     * The order matters and is not alphabetical: customers before orders so an order has an
     * account to belong to, products before prices and inventory so a price has a variant to land
     * on.
     *
     * @return Run[]
     */
    public function runAll(Connection $connection, array $options = []): array
    {
        $runs = [];

        foreach (Entity::syncOrder() as $entity) {
            if (!$connection->syncs($entity, Direction::PULL)) {
                continue;
            }

            $runs[] = $this->run($connection, $entity, $options);
        }

        return $runs;
    }

    /**
     * Everything a scheduled sweep should run right now.
     *
     * @return array<int,array{connection:Connection,entity:string}>
     */
    public function due(): array
    {
        $due = [];
        $cursors = Plugin::getInstance()->getCursors();

        foreach (Plugin::getInstance()->getConnections()->enabled() as $connection) {
            foreach ($connection->activeEntities() as $entity) {
                if (!$connection->syncs($entity, Direction::PULL)) {
                    continue;
                }

                if ($cursors->isDue($connection, $entity, Direction::PULL)) {
                    $due[] = ['connection' => $connection, 'entity' => $entity];
                }
            }
        }

        return $due;
    }

    /**
     * Apply one document, with everything that has to happen around it.
     */
    private function applyOne(Connection $connection, string $entity, ErpDocument $document, $map, Run $run, bool $dryRun): void
    {
        $startedAt = microtime(true);

        $event = new ApplyDocumentEvent([
            'connection' => $connection,
            'entity' => $entity,
            'document' => $document,
        ]);
        $this->trigger(self::EVENT_BEFORE_APPLY_DOCUMENT, $event);

        if (!$event->isValid) {
            Plugin::getInstance()->getRuns()->item($run, RunItem::ACTION_SKIPPED, $document->naturalKey(), $document->remoteId, null, Craft::t('erpy', 'Skipped by an event handler.'));

            return;
        }

        try {
            // The merchant's corrections to the connector's own reading of the ERP are applied
            // first, so an applier only ever sees a document the merchant agrees with.
            Plugin::getInstance()->getMapping()->overlay($map, $event->document, ['connection' => $connection]);

            $result = $this->apply($connection, $entity, $event->document, $map, $dryRun);
        } catch (Throwable $e) {
            $result = ApplyResult::failed($e->getMessage());
        }

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        Plugin::getInstance()->getRuns()->item(
            run: $run,
            action: $result->action,
            naturalKey: $document->naturalKey(),
            remoteId: $document->remoteId,
            localId: $result->localId,
            message: $result->message,
            changes: $result->changes,
            durationMs: $durationMs,
        );

        $deadLetters = Plugin::getInstance()->getDeadLetters();

        if ($result->isFailure() && !$dryRun) {
            $deadLetters->record(
                connection: $connection,
                entity: $entity,
                direction: Direction::PULL,
                naturalKey: $document->naturalKey(),
                localId: $result->localId,
                document: $document->toArray() + ['raw' => $document->raw],
                error: (string)$result->message,
                retryable: $result->retryable,
            );
        } elseif ($result->changedAnything()) {
            // A document that succeeds clears its own dead letter, so a merchant who fixes the
            // cause upstream does not have to come back and tidy up.
            $deadLetters->resolve($connection, $entity, $document->naturalKey());
        }
    }

    /**
     * Route one canonical document to whichever service owns that side of Commerce.
     */
    public function apply(Connection $connection, string $entity, ErpDocument $document, $map, bool $dryRun = false): ApplyResult
    {
        $plugin = Plugin::getInstance();

        return match ($entity) {
            Entity::PRODUCT => $plugin->getCatalog()->applyProduct($connection, $document, $map, $dryRun),
            Entity::PRICE => $plugin->getCatalog()->applyPrice($connection, $document, $map, $dryRun),
            Entity::INVENTORY => $plugin->getCatalog()->applyStock($connection, $document, $map, $dryRun),
            Entity::CUSTOMER => $plugin->getAccounts()->applyCustomer($connection, $document, $map, $dryRun),
            Entity::CREDIT => $plugin->getAccounts()->applyCredit($connection, $document, $map, $dryRun),
            Entity::ORDER_STATUS => $plugin->getOrders()->applyStatus($connection, $document, $map, $dryRun),
            Entity::SHIPMENT => $plugin->getOrders()->applyShipment($connection, $document, $map, $dryRun),
            Entity::INVOICE => $plugin->getOrders()->applyInvoice($connection, $document, $map, $dryRun),
            Entity::PAYMENT => $plugin->getOrders()->applyPayment($connection, $document, $map, $dryRun),
            default => ApplyResult::failed(Craft::t('erpy', 'Erpy does not know how to apply “{entity}”.', ['entity' => $entity]), false),
        };
    }

    /**
     * Re-apply a stored inbound document.
     */
    public function replayInbound(Connection $connection, DeadLetter $letter): bool
    {
        $class = Entity::documentClass($letter->entity);
        $data = $letter->documentArray();
        /** @var ErpDocument $document */
        $document = new $class($data);
        $map = Plugin::getInstance()->getMapping()->get($connection, $letter->entity, Direction::PULL);

        // A replay is a run of one, so it appears in the history like anything else rather than
        // silently changing data with no record.
        $run = Plugin::getInstance()->getRuns()->start($connection, $letter->entity, Direction::PULL, Run::TRIGGER_MANUAL);
        $this->applyOne($connection, $letter->entity, $document, $map, $run, false);
        Plugin::getInstance()->getRuns()->finish($run);

        return $run->failed === 0;
    }

    /**
     * Everything that has to be true before a run is worth starting. Returns a finished, skipped
     * Run when it is not — so a caller always gets a Run back and never has to null-check.
     */
    private function guard(Connection $connection, string $entity, bool $force): ?Run
    {
        $runs = Plugin::getInstance()->getRuns();

        $skip = function(string $message) use ($runs, $connection, $entity): Run {
            $run = $runs->start($connection, $entity, Direction::PULL, Run::TRIGGER_MANUAL);
            $runs->finish($run, Run::STATUS_SKIPPED, $message);

            return $run;
        };

        if (!$connection->enabled) {
            return $skip(Craft::t('erpy', 'The connection is switched off.'));
        }

        $connector = $connection->getConnector();

        if (!$connector) {
            return $skip(Craft::t('erpy', 'The add-on for “{handle}” is not installed.', ['handle' => $connection->connector]));
        }

        if (!$connector::capabilities()->handles($entity, Direction::PULL)) {
            return $skip(Craft::t('erpy', '{connector} cannot pull {entity}.', [
                'connector' => $connector::displayName(),
                'entity' => Entity::displayName($entity),
            ]));
        }

        // An entity the merchant has switched off stays off, whoever asks. Otherwise a webhook or
        // a stray console command could sync something that was deliberately turned back.
        if (!$connection->syncs($entity, Direction::PULL)) {
            return $skip(Craft::t('erpy', '{entity} is switched off for this connection.', [
                'entity' => Entity::displayName($entity),
            ]));
        }

        // Two concurrent runs of the same entity would fight over the same records and both
        // advance the watermark. The reaper below is what stops a killed worker blocking this
        // forever.
        if (!$force) {
            $runs->reapStale(Plugin::getInstance()->getSettings()->staleRunMinutes);

            if ($runs->isRunning($connection, $entity, Direction::PULL)) {
                return $skip(Craft::t('erpy', 'A sync of this entity is already running.'));
            }
        }

        return null;
    }
}
