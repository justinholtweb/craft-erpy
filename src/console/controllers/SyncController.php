<?php

namespace justinholtweb\erpy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use yii\console\ExitCode;

/**
 * Syncing from the command line — which is where a nightly catalogue pull belongs, because cron
 * has no request timeout and no merchant watching a spinner.
 *
 *     php craft erpy/sync/run acme
 *     php craft erpy/sync/run acme product --full
 *     php craft erpy/sync/due
 */
class SyncController extends Controller
{
    public $defaultAction = 'run';

    /** Ignore the delta watermark and pull everything. */
    public bool $full = false;

    /** Report what would change without changing anything. */
    public bool $dryRun = false;

    /** Run even if another sync of the same entity looks like it is still going. */
    public bool $force = false;

    /** Stop after this many pages. Handy for a first look at a big catalogue. */
    public ?int $maxPages = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['full', 'dryRun', 'force', 'maxPages'],
            default => [],
        });
    }

    public function optionAliases(): array
    {
        return ['f' => 'full', 'd' => 'dryRun'];
    }

    /**
     * Pull one entity, or everything the connection is configured for.
     *
     * @param string $connection the connection handle
     * @param string|null $entity one of: customer, product, price, inventory, orderStatus,
     *                            shipment, invoice, payment, credit
     */
    public function actionRun(string $connection, ?string $entity = null): int
    {
        $plugin = Plugin::getInstance();
        $model = $plugin->getConnections()->getByHandle($connection);

        if (!$model) {
            $this->stderr("No connection with the handle “$connection”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($entity !== null && !Entity::exists($entity)) {
            $this->stderr("“$entity” is not one of: " . implode(', ', Entity::all()) . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $options = [
            'trigger' => Run::TRIGGER_CONSOLE,
            'full' => $this->full,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
        ];

        if ($this->maxPages !== null) {
            $options['maxPages'] = $this->maxPages;
        }

        $runs = $entity !== null
            ? [$plugin->getSync()->run($model, $entity, $options)]
            : $plugin->getSync()->runAll($model, $options);

        if ($runs === []) {
            $this->stdout("Nothing is switched on to sync on “$connection”.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $failed = false;

        foreach ($runs as $run) {
            $this->report($run);
            $failed = $failed || $run->status === Run::STATUS_FAILED;
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function report(Run $run): void
    {
        $colour = match ($run->status) {
            Run::STATUS_SUCCESS => Console::FG_GREEN,
            Run::STATUS_PARTIAL => Console::FG_YELLOW,
            Run::STATUS_FAILED => Console::FG_RED,
            default => Console::FG_GREY,
        };

        $this->stdout(str_pad(Entity::displayName($run->entity), 16));
        $this->stdout(str_pad($run->status, 10), $colour);
        $this->stdout(sprintf(
            "%d created, %d updated, %d skipped, %d failed  (%s, %d requests)\n",
            $run->created,
            $run->updated,
            $run->skipped,
            $run->failed,
            $run->durationLabel(),
            $run->requests,
        ));

        if ($run->message) {
            $this->stdout('  ' . $run->message . "\n", Console::FG_RED);
        }
    }

    /**
     * Run every sync whose interval has elapsed. This is the one to put on cron.
     */
    public function actionDue(): int
    {
        $plugin = Plugin::getInstance();
        $due = $plugin->getSync()->due();

        if ($due === []) {
            $this->stdout("Nothing is due.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($due as $item) {
            $this->stdout($item['connection']->name . ' · ');
            $run = $plugin->getSync()->run($item['connection'], $item['entity'], [
                'trigger' => Run::TRIGGER_SCHEDULE,
            ]);
            $this->report($run);
        }

        return ExitCode::OK;
    }

    /**
     * Where every connection has got to.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $connections = $plugin->getConnections()->all();

        if ($connections === []) {
            $this->stdout("No connections yet.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($connections as $connection) {
            $connector = $connection->getConnector();

            $this->stdout("\n" . $connection->name, Console::BOLD);
            $this->stdout('  (' . ($connector ? $connector::displayName() : $connection->connector . ' — add-on not installed') . ')');
            $this->stdout($connection->enabled ? "  enabled\n" : "  disabled\n", $connection->enabled ? Console::FG_GREEN : Console::FG_GREY);

            foreach ($connection->activeEntities() as $entity) {
                $last = $plugin->getRuns()->lastFor($connection, $entity, Direction::PULL);
                $watermark = $plugin->getCursors()->watermark($connection, $entity, Direction::PULL);

                $this->stdout('  ' . str_pad(Entity::displayName($entity), 16));
                $this->stdout(str_pad($last ? $last->status : 'never run', 12), $last?->status === Run::STATUS_SUCCESS ? Console::FG_GREEN : Console::FG_YELLOW);
                $this->stdout('since ' . ($watermark ? $watermark->format('Y-m-d H:i') . ' UTC' : 'the beginning') . "\n", Console::FG_GREY);
            }

            $problems = $plugin->getDeadLetters()->openCount($connection);

            if ($problems > 0) {
                $this->stdout("  $problems unresolved problem" . ($problems === 1 ? '' : 's') . "\n", Console::FG_RED);
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Forget the delta watermark, so the next run pulls everything again.
     *
     * @param string $connection the connection handle
     * @param string|null $entity leave off to reset every entity
     */
    public function actionReset(string $connection, ?string $entity = null): int
    {
        $plugin = Plugin::getInstance();
        $model = $plugin->getConnections()->getByHandle($connection);

        if (!$model) {
            $this->stderr("No connection with the handle “$connection”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $count = $plugin->getCursors()->reset($model, $entity);
        $this->stdout("Reset $count watermark" . ($count === 1 ? '' : 's') . ".\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
