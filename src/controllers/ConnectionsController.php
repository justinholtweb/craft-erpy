<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\jobs\SyncJob;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Connections: the list, the editor, the connection test and the "sync now" button.
 */
class ConnectionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('erpy-viewConnections');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $connections = $plugin->getConnections()->all();
        $summary = [];

        foreach ($connections as $connection) {
            $summary[$connection->id] = [
                'connector' => $plugin->getConnectors()->describeOne($connection->connector),
                'entities' => $connection->activeEntities(),
                'problems' => $plugin->getDeadLetters()->openCount($connection),
                'lastRun' => $plugin->getRuns()->recent($connection, 1)[0] ?? null,
            ];
        }

        return $this->renderTemplate('erpy/connections/_index', [
            'connections' => $connections,
            'summary' => $summary,
            'connectors' => $plugin->getConnectors()->describe(),
            'canManage' => Craft::$app->getUser()->checkPermission('erpy-manageConnections'),
        ]);
    }

    public function actionEdit(?int $connectionId = null, ?Connection $connection = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($connection === null) {
            $connection = $connectionId
                ? $plugin->getConnections()->getById($connectionId)
                : new Connection(['connector' => (string)$this->request->getQueryParam('connector')]);
        }

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $connector = $connection->getConnector();
        $lastRuns = [];

        if ($connection->id) {
            foreach ($connection->activeEntities() as $entity) {
                $lastRuns[$entity] = $plugin->getRuns()->lastFor($connection, $entity, Direction::PULL);
            }
        }

        return $this->renderTemplate('erpy/connections/_edit', [
            'connection' => $connection,
            'connector' => $connector ? $plugin->getConnectors()->describeOne($connection->connector) : null,
            'connectors' => $plugin->getConnectors()->describe(),
            'settingsFields' => $connector ? $connector::settingsFields() : [],
            'capabilities' => $connector ? $connector::capabilities() : null,
            'entities' => Entity::syncOrder(),
            'lastRuns' => $lastRuns,
            'redirectUri' => Plugin::redirectUri(),
            // Null unless the connector needs a human to consent once, which is what decides
            // whether the Connect button appears at all.
            'oauth' => $plugin->getConnections()->oauthState($connection),
            'webhookUrl' => $connection->handle
                ? \craft\helpers\UrlHelper::siteUrl('erpy/webhook/' . $connection->handle)
                : null,
            'canManage' => Craft::$app->getUser()->checkPermission('erpy-manageConnections'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('erpy-manageConnections');

        $plugin = Plugin::getInstance();
        $id = $this->request->getBodyParam('connectionId');
        $connection = $id ? $plugin->getConnections()->getById((int)$id) : new Connection();

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $storedConnector = $connection->id ? $connection->connector : null;
        $storedSettings = $connection->settings;

        $connection->name = (string)$this->request->getBodyParam('name', $connection->name);
        $connection->handle = (string)$this->request->getBodyParam('handle', $connection->handle);
        $connection->connector = (string)$this->request->getBodyParam('connector', $connection->connector);
        $connection->enabled = (bool)$this->request->getBodyParam('enabled', false);
        $connection->storeId = (int)$this->request->getBodyParam('storeId') ?: null;
        $connection->settings = array_merge($connection->settings, (array)$this->request->getBodyParam('settings', []));
        $connection->sync = $this->normaliseSync((array)$this->request->getBodyParam('sync', []));

        $this->requireAdminToRepoint($connection, $storedConnector, $storedSettings);

        if (!$plugin->getConnections()->save($connection)) {
            $this->setFailFlash(Craft::t('erpy', 'Couldn’t save the connection.'));
            Craft::$app->getUrlManager()->setRouteParams(['connection' => $connection]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('erpy', 'Connection saved.'));

        return $this->redirectToPostedUrl($connection);
    }

    /**
     * Where a connection sends its requests — and its credentials — is an admin's decision.
     *
     * "Manage connections" covers names, credentials and what syncs. It does not cover pointing a
     * connection at a different host or switching its connector: that is the move that, before
     * 5.1.1, sent the stored credentials to a host of the editor's choosing, and it is how a
     * connection reaches an internal address. Private hosts are not refused outright because
     * on-premises ERPs (SAP Business One's Service Layer, a self-hosted Odoo) routinely live on one.
     *
     * @throws ForbiddenHttpException
     */
    private function requireAdminToRepoint(Connection $connection, ?string $storedConnector, array $storedSettings): void
    {
        if (Craft::$app->getUser()->getIsAdmin()) {
            return;
        }

        if ($storedConnector !== null && $storedConnector !== $connection->connector) {
            throw new ForbiddenHttpException(Craft::t('erpy', 'Only an admin can change which system a connection talks to.'));
        }

        $connector = $connection->getConnector();
        $fields = $connector ? $connector::settingsFields() : [];

        if (\justinholtweb\erpy\services\Connections::endpointsChanged($fields, $storedSettings, $connection->settings)) {
            throw new ForbiddenHttpException(Craft::t('erpy', 'Only an admin can change where a connection sends its requests.'));
        }
    }

    /**
     * The form posts checkboxes and strings; the model wants booleans and ints, and a direction
     * that the connector can actually do.
     */
    private function normaliseSync(array $posted): array
    {
        $sync = [];

        foreach ($posted as $entity => $config) {
            if (!Entity::exists((string)$entity)) {
                continue;
            }

            $sync[$entity] = [
                'enabled' => (bool)($config['enabled'] ?? false),
                'direction' => (int)($config['direction'] ?? Direction::PULL),
                'interval' => max(0, (int)($config['interval'] ?? 0)),
                'filters' => (array)($config['filters'] ?? []),
            ];
        }

        return $sync;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-manageConnections');

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        return $this->asJson(['success' => Plugin::getInstance()->getConnections()->delete($connection)]);
    }

    /**
     * Prove the credentials work, and say something useful when they do not.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $connector = $connection->getConnector();

        if (!$connector) {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('erpy', 'The add-on for “{handle}” is not installed.', ['handle' => $connection->connector]),
            ]);
        }

        $result = $connector->test();

        return $this->asJson([
            'ok' => $result->ok,
            'message' => $result->message,
            'details' => $result->details,
            'hints' => $result->hints,
            'durationMs' => $result->durationMs,
        ]);
    }

    /**
     * Queue a sync. Queued rather than run inline because a catalogue pull can take an hour and
     * no control panel request should.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-runSync');

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $entity = (string)$this->request->getBodyParam('entity', '');
        $full = (bool)$this->request->getBodyParam('full', false);
        $entities = $entity !== '' ? [$entity] : $connection->activeEntities();
        $queued = 0;

        foreach ($entities as $each) {
            if (!$connection->syncs($each, Direction::PULL)) {
                continue;
            }

            Craft::$app->getQueue()->push(new SyncJob([
                'connectionId' => $connection->id,
                'entity' => $each,
                'full' => $full,
                'trigger' => Run::TRIGGER_MANUAL,
            ]));

            $queued++;
        }

        return $this->asJson([
            'success' => $queued > 0,
            'queued' => $queued,
            'message' => $queued > 0
                ? Craft::t('erpy', '{n} sync jobs queued.', ['n' => $queued])
                : Craft::t('erpy', 'Nothing is switched on to sync.'),
        ]);
    }

    /**
     * A dry run: everything the sync would do, and nothing it would change.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-runSync');

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));
        $entity = (string)$this->request->getRequiredBodyParam('entity');

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $run = Plugin::getInstance()->getSync()->run($connection, $entity, [
            'dryRun' => true,
            'trigger' => Run::TRIGGER_MANUAL,
            'maxPages' => 1,
            'force' => true,
        ]);

        return $this->asJson([
            'runId' => $run->id,
            'created' => $run->created,
            'updated' => $run->updated,
            'skipped' => $run->skipped,
            'failed' => $run->failed,
            'url' => \craft\helpers\UrlHelper::cpUrl('erpy/runs/' . $run->id),
        ]);
    }

    public function actionForget(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-manageConnections');

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $entity = $this->request->getBodyParam('entity') ?: null;
        $plugin = Plugin::getInstance();

        $forgotten = $plugin->getLinks()->forgetAll($connection, $entity);
        $plugin->getCursors()->reset($connection, $entity);

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('erpy', '{n} pairings forgotten. The next sync will match everything again by SKU or code.', ['n' => $forgotten]),
        ]);
    }
}
