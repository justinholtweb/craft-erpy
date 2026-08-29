<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The field-mapping screen: where the connector's defaults meet the merchant's reality.
 */
class MappingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('erpy-editMappings');

        return true;
    }

    public function actionEdit(int $connectionId, string $entity): Response
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getById($connectionId);

        if (!$connection || !Entity::exists($entity)) {
            throw new NotFoundHttpException('No such mapping.');
        }

        $direction = (int)$this->request->getQueryParam('direction', Direction::PULL);
        $connector = $connection->getConnector();

        return $this->renderTemplate('erpy/mappings/_edit', [
            'connection' => $connection,
            'connector' => $connector,
            'entity' => $entity,
            'direction' => $direction,
            'map' => $plugin->getMapping()->get($connection, $entity, $direction),
            'documentFields' => $plugin->getMapping()->documentFields($entity),
            'transforms' => $plugin->getMapping()->transformOptions(),
            'productTypes' => $this->productTypeOptions(),
            'orderStatuses' => $this->orderStatusOptions($connection),
            'userGroups' => $this->userGroupOptions(),
            'inventoryLocations' => $this->inventoryLocationOptions(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $connection = $plugin->getConnections()->getById((int)$this->request->getRequiredBodyParam('connectionId'));
        $entity = (string)$this->request->getRequiredBodyParam('entity');
        $direction = (int)$this->request->getBodyParam('direction', Direction::PULL);

        if (!$connection || !Entity::exists($entity)) {
            throw new NotFoundHttpException('No such mapping.');
        }

        $map = $plugin->getMapping()->get($connection, $entity, $direction);
        $map->rules = $this->normaliseRules((array)$this->request->getBodyParam('rules', []));
        $map->options = (array)$this->request->getBodyParam('options', []);

        if (!$plugin->getMapping()->save($map)) {
            $this->setFailFlash(Craft::t('erpy', 'Couldn’t save the mapping.'));

            return null;
        }

        $this->setSuccessFlash(Craft::t('erpy', 'Mapping saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Craft's editable tables post a row for every blank line the merchant left behind; a rule
     * with no target does nothing but slow the mapper down, so it never gets stored.
     */
    private function normaliseRules(array $posted): array
    {
        $rules = [];

        foreach ($posted as $row) {
            $target = trim((string)($row['target'] ?? ''));
            $source = trim((string)($row['source'] ?? ''));

            if ($target === '' || $source === '') {
                continue;
            }

            $rules[] = [
                'source' => $source,
                'target' => $target,
                'transform' => trim((string)($row['transform'] ?? '')),
                'default' => (string)($row['default'] ?? ''),
            ];
        }

        return $rules;
    }

    private function productTypeOptions(): array
    {
        $options = ['' => Craft::t('erpy', 'Choose a product type…')];

        if (!Plugin::commerceIsReady()) {
            return $options;
        }

        foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
            $options[$type->id] = $type->name;
        }

        return $options;
    }

    private function orderStatusOptions($connection): array
    {
        $options = ['' => Craft::t('erpy', 'Leave the status alone')];

        if (!Plugin::commerceIsReady()) {
            return $options;
        }

        foreach (Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($connection->getStoreId()) as $status) {
            $options[$status->id] = $status->name;
        }

        return $options;
    }

    private function userGroupOptions(): array
    {
        $options = ['' => Craft::t('erpy', 'No group')];

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $options[$group->id] = $group->name;
        }

        return $options;
    }

    private function inventoryLocationOptions(): array
    {
        $options = [];

        if (!Plugin::commerceIsReady()) {
            return $options;
        }

        foreach (Commerce::getInstance()->getInventoryLocations()->getAllInventoryLocations() as $location) {
            $options[$location->handle] = $location->name;
        }

        return $options;
    }
}
