<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\connectors\FileConnector;
use justinholtweb\erpy\jobs\SyncJob;
use justinholtweb\erpy\models\Run;
use justinholtweb\erpy\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Files a merchant uploads for a file connection whose source is "uploaded in the control panel".
 *
 * Kept in the connection's inbox under Craft's storage folder — never anywhere web-readable — as
 * `<entity>.<ext>`, replacing the last one. A sync of that entity is queued straight away.
 */
class FilesController extends Controller
{
    private const EXTENSIONS = ['csv', 'tsv', 'txt', 'xml', 'json'];

    public function actionUpload(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-runSync');

        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $connector = $connection->getConnector();
        $entity = (string)$this->request->getRequiredBodyParam('entity');

        if (!$connector instanceof FileConnector || $connection->getSetting('source') !== FileConnector::SOURCE_UPLOAD) {
            throw new BadRequestHttpException('This connection does not read uploaded files.');
        }

        if (FileConnector::entityFields($entity) === []) {
            throw new BadRequestHttpException('Files cannot be uploaded for that entity.');
        }

        $file = UploadedFile::getInstanceByName('file');

        if ($file === null || $file->getHasError()) {
            return $this->asFailure(Craft::t('erpy', 'The file did not arrive. It may be larger than this server accepts.'));
        }

        $extension = strtolower($file->getExtension());

        if (!in_array($extension, self::EXTENSIONS, true)) {
            return $this->asFailure(Craft::t('erpy', 'Upload a CSV, TXT, XML or JSON file.'));
        }

        $inbox = FileConnector::inbox($connection);

        if (!is_dir($inbox) && !@mkdir($inbox, 0775, true) && !is_dir($inbox)) {
            return $this->asFailure(Craft::t('erpy', 'Erpy could not create its upload folder.'));
        }

        // One file per entity: the newest upload is the stock list, not one of several.
        foreach (glob($inbox . DIRECTORY_SEPARATOR . $entity . '.*') ?: [] as $previous) {
            @unlink($previous);
        }

        $target = $inbox . DIRECTORY_SEPARATOR . $entity . '.' . $extension;

        if (!$file->saveAs($target)) {
            return $this->asFailure(Craft::t('erpy', 'The file could not be saved.'));
        }

        $queued = false;

        if ($connection->enabled && $connection->syncs($entity, Direction::PULL)) {
            Craft::$app->getQueue()->push(new SyncJob([
                'connectionId' => $connection->id,
                'entity' => $entity,
                'trigger' => Run::TRIGGER_MANUAL,
            ]));
            $queued = true;
        }

        $plugin->getLog()->note($connection, Craft::t('erpy', '{name} uploaded for {entity}.', ['name' => $file->name, 'entity' => Entity::displayName($entity)]));

        return $this->asSuccess($queued
            ? Craft::t('erpy', 'Uploaded {name}; a sync of {entity} is queued.', ['name' => $file->name, 'entity' => Entity::displayName($entity)])
            : Craft::t('erpy', 'Uploaded {name}. It is read on the next sync of {entity}.', ['name' => $file->name, 'entity' => Entity::displayName($entity)]));
    }
}
