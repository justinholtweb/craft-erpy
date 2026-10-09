<?php

namespace justinholtweb\erpy\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\erpy\Plugin;

/**
 * A dashboard tile: every connection's latest run, its unresolved problems and any open alert.
 *
 * The Problems screen is where the detail lives; this is what makes somebody go there. It shows
 * the same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class HealthWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('erpy', 'ERP health');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/erpy/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('erpy-viewRuns');
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return Craft::t('erpy', 'ERP health');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission('erpy-viewRuns')) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('erpy/_widgets/health', [
            'overview' => Plugin::getInstance()->getAlerts()->overview(),
        ]);
    }
}
