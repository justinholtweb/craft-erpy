<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\jobs\SyncJob;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The inbound endpoint, for ERPs that can tell us something changed instead of making us ask.
 *
 * It deliberately does almost nothing: verify the secret, note what changed, queue a targeted
 * sync, answer 200. A webhook handler that does real work is a webhook handler that times out,
 * and an ERP that times out will retry — which is how one changed price becomes forty requests.
 */
class WebhookController extends Controller
{
    protected array|bool|int $allowAnonymous = ['receive'];

    public $enableCsrfValidation = false;

    public function actionReceive(string $connectionHandle): Response
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getByHandle($connectionHandle);

        if (!$connection || !$connection->enabled) {
            throw new NotFoundHttpException('No such connection.');
        }

        $expected = (string)$connection->getSetting('webhookSecret', '');

        if ($expected === '') {
            throw new ForbiddenHttpException('This connection has no webhook secret set, so it will not accept webhooks.');
        }

        $provided = (string)($this->request->getHeaders()->get('X-Erpy-Secret')
            ?: $this->request->getParam('secret', ''));

        // Constant-time, so the endpoint cannot be used to guess the secret one character at a
        // time. Both sides are hashed first because hash_equals needs equal-length strings.
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $provided))) {
            Craft::warning("Erpy rejected a webhook for “$connectionHandle” with a bad secret.", 'erpy');

            throw new ForbiddenHttpException('Bad secret.');
        }

        $entity = (string)$this->request->getParam('entity', '');
        $entities = Entity::exists($entity) ? [$entity] : $connection->activeEntities();
        $queued = 0;

        foreach ($entities as $each) {
            if (!$connection->syncs($each, \justinholtweb\erpy\base\Direction::PULL)) {
                continue;
            }

            Craft::$app->getQueue()->push(new SyncJob([
                'connectionId' => $connection->id,
                'entity' => $each,
                'trigger' => Run::TRIGGER_WEBHOOK,
            ]));

            $queued++;
        }

        $plugin->getLog()->note($connection, Craft::t('erpy', 'Webhook received; queued {n} syncs.', ['n' => $queued]));

        return $this->asJson(['ok' => true, 'queued' => $queued]);
    }
}
