<?php

namespace justinholtweb\erpy\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;

/**
 * The sweeper: find everything whose interval has elapsed and queue it.
 *
 * Craft has no scheduler, so this is pushed by the same request that would otherwise do nothing —
 * cheap to run, and it queues rather than syncs so one slow ERP cannot starve the others.
 */
class ScheduledSyncJob extends BaseJob
{
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->scheduleEnabled) {
            return;
        }

        $due = $plugin->getSync()->due();
        $total = max(1, count($due));

        foreach (array_values($due) as $index => $item) {
            $this->setProgress($queue, $index / $total);

            Craft::$app->getQueue()->push(new SyncJob([
                'connectionId' => $item['connection']->id,
                'entity' => $item['entity'],
                'trigger' => Run::TRIGGER_SCHEDULE,
            ]));
        }

        $this->setProgress($queue, 1);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('erpy', 'Checking which ERP syncs are due');
    }
}
