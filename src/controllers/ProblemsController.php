<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\erpy\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Dead letters, called "Problems" in the control panel because that is what a merchant is
 * looking for when they come here.
 */
class ProblemsController extends Controller
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
        $connection = $connectionId ? $plugin->getConnections()->getById($connectionId) : null;

        return $this->renderTemplate('erpy/problems/_index', [
            'letters' => $plugin->getDeadLetters()->open($connection),
            'connections' => $plugin->getConnections()->all(),
            'connectionId' => $connectionId,
            'canReplay' => Craft::$app->getUser()->checkPermission('erpy-replayDocuments'),
        ]);
    }

    public function actionDetail(int $letterId): Response
    {
        $plugin = Plugin::getInstance();
        $letter = $plugin->getDeadLetters()->getById($letterId);

        if (!$letter) {
            throw new NotFoundHttpException('No such problem.');
        }

        return $this->renderTemplate('erpy/problems/_detail', [
            'letter' => $letter,
            'connection' => $plugin->getConnections()->getById((int)$letter->connectionId),
            'document' => json_encode($letter->documentArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'canReplay' => Craft::$app->getUser()->checkPermission('erpy-replayDocuments'),
        ]);
    }

    public function actionReplay(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-replayDocuments');

        $letter = Plugin::getInstance()->getDeadLetters()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$letter) {
            throw new NotFoundHttpException('No such problem.');
        }

        $success = Plugin::getInstance()->getDeadLetters()->replay($letter);

        return $this->asJson([
            'success' => $success,
            'message' => $success
                ? Craft::t('erpy', 'Sent. The problem has been cleared.')
                : Craft::t('erpy', 'It failed again — check the log for what the ERP said this time.'),
        ]);
    }

    public function actionDismiss(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-replayDocuments');

        $plugin = Plugin::getInstance();
        $letter = $plugin->getDeadLetters()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$letter) {
            throw new NotFoundHttpException('No such problem.');
        }

        $connection = $plugin->getConnections()->getById((int)$letter->connectionId);

        if ($connection) {
            $plugin->getDeadLetters()->resolve($connection, $letter->entity, $letter->naturalKey);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionReplayAll(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-replayDocuments');

        $plugin = Plugin::getInstance();
        $connectionId = (int)$this->request->getBodyParam('connection');
        $connection = $connectionId ? $plugin->getConnections()->getById($connectionId) : null;

        $succeeded = 0;
        $failed = 0;

        foreach ($plugin->getDeadLetters()->open($connection) as $letter) {
            // Documents the ERP rejected outright are skipped: replaying them unchanged just
            // produces the same rejection and a longer log.
            if (!$letter->retryable) {
                continue;
            }

            $plugin->getDeadLetters()->replay($letter) ? $succeeded++ : $failed++;
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('erpy', '{ok} sent, {bad} still failing.', ['ok' => $succeeded, 'bad' => $failed]),
        ]);
    }
}
