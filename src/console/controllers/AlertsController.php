<?php

namespace justinholtweb\erpy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\erpy\Plugin;
use justinholtweb\erpy\services\Alerts;
use yii\console\ExitCode;

/**
 * Failure alerts from the command line.
 *
 *     php craft erpy/alerts/check     # evaluate every connection, send what is owed
 *     php craft erpy/alerts/test      # send a sample through every configured channel
 *
 * `erpy/sync/due` runs the same check after its syncs, so a site that already has that on cron
 * needs nothing more.
 */
class AlertsController extends Controller
{
    public $defaultAction = 'check';

    /**
     * Evaluate every enabled connection and send any alert or recovery that is owed.
     */
    public function actionCheck(): int
    {
        $results = Plugin::getInstance()->getAlerts()->check();

        if ($results === []) {
            $this->stdout("No enabled connections.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $open = 0;

        foreach ($results as $result) {
            $isOpen = $result['state'] === Alerts::STATE_OPEN;
            $open += $isOpen ? 1 : 0;

            $this->stdout(str_pad($result['connection'], 20));
            $this->stdout(str_pad(Alerts::incidentLabel($result['incident']), 26));
            $this->stdout(str_pad($isOpen ? 'open' : 'ok', 6), $isOpen ? Console::FG_RED : Console::FG_GREEN);

            if ($result['transition'] !== null) {
                $this->stdout(' ' . $result['transition'], Console::FG_YELLOW);
            }

            if ($result['notified']) {
                $this->stdout(' · notified', Console::FG_YELLOW);
            }

            $this->stdout("\n");

            if ($isOpen && $result['detail']) {
                $this->stdout('  ' . $result['detail'] . "\n", Console::FG_GREY);
            }
        }

        $this->stdout(sprintf("\n%d open incident%s.\n", $open, $open === 1 ? '' : 's'));

        // Exit 0 either way: an open incident is news, not a failure of this command, and cron
        // would otherwise email somebody about the alert on top of the alert.
        return ExitCode::OK;
    }

    /**
     * Send a sample alert through every configured channel.
     */
    public function actionTest(): int
    {
        $result = Plugin::getInstance()->getAlerts()->sendTest();

        if ($result['email'] === null && $result['webhook'] === null) {
            $this->stderr("No recipients and no webhook are configured — nothing to send to.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $ok = true;

        if ($result['email'] !== null) {
            $this->stdout('Email:   ' . ($result['email'] ? "sent\n" : "failed — check Craft's email settings\n"), $result['email'] ? Console::FG_GREEN : Console::FG_RED);
            $ok = $result['email'];
        }

        if ($result['webhook'] !== null) {
            $this->stdout('Webhook: ' . ($result['webhook'] === true ? "sent\n" : 'failed — ' . $result['webhook'] . "\n"), $result['webhook'] === true ? Console::FG_GREEN : Console::FG_RED);
            $ok = $ok && $result['webhook'] === true;
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
