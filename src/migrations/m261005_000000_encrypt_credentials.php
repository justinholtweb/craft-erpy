<?php

namespace justinholtweb\erpy\migrations;

use craft\db\Migration;
use craft\db\Query;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\helpers\Secret;
use justinholtweb\erpy\Plugin;

/**
 * Encrypts credentials already at rest (5.1.1): every connection's OAuth tokens, and any secret
 * setting stored as a literal rather than an `$ENV` reference.
 *
 * Idempotent — an encrypted value carries a prefix and is skipped. A connection whose connector
 * add-on is not installed keeps its literal secrets until it is next saved with the add-on present,
 * because only the add-on knows which of its settings are secrets.
 */
class m261005_000000_encrypt_credentials extends Migration
{
    public function safeUp(): bool
    {
        $connectors = Plugin::getInstance()?->getConnectors();

        foreach ((new Query())->select(['id', 'connector', 'settings', 'tokens'])->from(Table::CONNECTIONS)->all() as $row) {
            $update = [];

            $tokens = (string)($row['tokens'] ?? '');
            if ($tokens !== '' && !Secret::isEncrypted($tokens)) {
                $update['tokens'] = Secret::encodeTokens(json_decode($tokens, true) ?: []);
            }

            $class = $connectors?->classFor((string)$row["connector"]);
            $settings = json_decode((string)($row['settings'] ?? ''), true) ?: [];
            $changed = false;

            foreach ($class ? Field::secretNames($class::settingsFields()) : [] as $name) {
                if (isset($settings[$name])) {
                    $protected = Secret::protectSetting($settings[$name]);
                    if ($protected !== $settings[$name]) {
                        $settings[$name] = $protected;
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $update['settings'] = json_encode($settings);
            }

            if ($update !== []) {
                $this->update(Table::CONNECTIONS, $update, ['id' => $row['id']]);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261005_000000_encrypt_credentials cannot be reverted: the credentials stay encrypted.\n";

        return false;
    }
}
