<?php
/**
 * Prints every installed connector's capabilities and connection fields as JSON.
 *
 * The add-on promo decks are built from this, so a slide saying what a connector syncs is read
 * out of the connector's own declaration rather than typed in by hand:
 *
 *   cd ~/Sites/plugin-testing
 *   ddev exec php /var/www/craft-erpy/tests/tools/dump-connectors.php > connectors.json
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\erpy\Plugin;

$out = [];
foreach (Plugin::getInstance()->connectors->byVendor() as $vendor => $connectors) {
    foreach ($connectors as $handle => $class) {
        $out[$handle] = [
            'class' => $class,
            'name' => $class::displayName(),
            'vendor' => $vendor,
            'description' => $class::description(),
            'capabilities' => $class::capabilities()->toArray(),
            'fields' => $class::settingsFields(),
        ];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
