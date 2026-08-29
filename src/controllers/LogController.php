<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\erpy\models\LogEntry;
use justinholtweb\erpy\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log: every request, with credentials already redacted at the transport.
 */
class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('erpy-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $connectionId = (int)$this->request->getQueryParam('connection');
        $errorsOnly = (bool)$this->request->getQueryParam('errors');

        $query = $plugin->getLog()->query()->limit(200);

        if ($connectionId) {
            $query->where(['connectionId' => $connectionId]);
        }

        if ($errorsOnly) {
            $query->andWhere(['or', ['>=', 'status', 400], ['status' => 0], ['type' => 'error']]);
        }

        return $this->renderTemplate('erpy/log/_index', [
            'entries' => array_map(static fn(array $row) => new LogEntry($row), $query->all()),
            'connections' => $plugin->getConnections()->all(),
            'connectionId' => $connectionId,
            'errorsOnly' => $errorsOnly,
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $plugin = Plugin::getInstance();
        $entry = $plugin->getLog()->getById($entryId);

        if (!$entry) {
            throw new NotFoundHttpException('No such log entry.');
        }

        return $this->renderTemplate('erpy/log/_detail', [
            'entry' => $entry,
            'connection' => $plugin->getConnections()->getById((int)$entry->connectionId),
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-manageConnections');

        $connectionId = (int)$this->request->getBodyParam('connection');
        $connection = $connectionId ? Plugin::getInstance()->getConnections()->getById($connectionId) : null;
        $deleted = Plugin::getInstance()->getLog()->clear($connection);

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('erpy', '{n} log entries deleted.', ['n' => $deleted]),
        ]);
    }
}
