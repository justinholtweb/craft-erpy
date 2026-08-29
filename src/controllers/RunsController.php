<?php

namespace justinholtweb\erpy\controllers;

use craft\web\Controller;
use justinholtweb\erpy\models\RunItem;
use justinholtweb\erpy\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Sync activity: what ran, when, and what it did to each record.
 */
class RunsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('erpy-viewRuns');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $connectionId = (int)$this->request->getQueryParam('connection');
        $status = (string)$this->request->getQueryParam('status', '');

        $query = $plugin->getRuns()->query()->limit(100);

        if ($connectionId) {
            $query->where(['connectionId' => $connectionId]);
        }

        if ($status !== '') {
            $query->andWhere(['status' => $status]);
        }

        return $this->renderTemplate('erpy/runs/_index', [
            'runs' => array_map(
                static fn(array $row) => new \justinholtweb\erpy\models\Run($row),
                $query->all(),
            ),
            'connections' => $plugin->getConnections()->all(),
            'connectionId' => $connectionId,
            'status' => $status,
        ]);
    }

    public function actionDetail(int $runId): Response
    {
        $plugin = Plugin::getInstance();
        $run = $plugin->getRuns()->getById($runId);

        if (!$run) {
            throw new NotFoundHttpException('No such run.');
        }

        $action = (string)$this->request->getQueryParam('action', '');

        return $this->renderTemplate('erpy/runs/_detail', [
            'run' => $run,
            'connection' => $plugin->getConnections()->getById((int)$run->connectionId),
            'items' => $plugin->getRuns()->items($runId, $action ?: null),
            'filter' => $action,
            'actions' => [
                RunItem::ACTION_CREATED,
                RunItem::ACTION_UPDATED,
                RunItem::ACTION_SKIPPED,
                RunItem::ACTION_FAILED,
            ],
            'requests' => $plugin->getLog()->forRun($runId, 100),
        ]);
    }
}
