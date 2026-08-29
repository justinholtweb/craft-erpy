<?php

namespace justinholtweb\erpy\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;

/**
 * Pull one entity from one ERP, from the queue.
 */
class SyncJob extends BaseJob
{
    public ?int $connectionId = null;

    public string $entity = '';

    public bool $full = false;

    public bool $dryRun = false;

    public string $trigger = Run::TRIGGER_QUEUE;

    public function execute($queue): void
    {
        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->connectionId);

        if (!$connection || !$connection->enabled) {
            return;
        }

        $this->setProgress($queue, 0.05, Craft::t('erpy', 'Talking to {name}', ['name' => $connection->name]));

        $run = Plugin::getInstance()->getSync()->run($connection, $this->entity, [
            'trigger' => $this->trigger,
            'full' => $this->full,
            'dryRun' => $this->dryRun,
        ]);

        $this->setProgress($queue, 1);

        // A failed run has already been recorded in full. Throwing here would give the merchant
        // a red queue job *and* a red run saying the same thing, and would re-run a sync that is
        // usually failing for a reason retrying cannot fix (wrong credentials, ERP offline).
        if ($run->status === Run::STATUS_FAILED) {
            Craft::warning(sprintf(
                'Erpy sync of %s from %s failed: %s',
                $this->entity,
                $connection->name,
                $run->message ?? 'no reason given',
            ), 'erpy');
        }
    }

    protected function defaultDescription(): ?string
    {
        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->connectionId);

        return Craft::t('erpy', 'Syncing {entity} from {name}', [
            'entity' => strtolower(Entity::displayName($this->entity)),
            'name' => $connection?->name ?? 'the ERP',
        ]);
    }

    public function getTtr(): int
    {
        // Catalogue pulls are measured in tens of thousands of records; the default 300 seconds
        // would have the queue declare a healthy sync dead halfway through.
        return 3600;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
