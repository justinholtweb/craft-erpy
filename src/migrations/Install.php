<?php

namespace justinholtweb\erpy\migrations;

use craft\db\Migration;
use justinholtweb\erpy\db\Table;

/**
 * Erpy's schema.
 *
 * Two decisions here carry the plugin. First, `erpy_links` — the identity map — has a unique index
 * on both sides of every pairing, so a duplicate can be prevented by the database rather than by
 * remembering to check. Second, nothing in this schema has a foreign key to a Commerce or Craft
 * element: elements get deleted, and losing a sync history because somebody tidied up a product
 * is worse than holding an id that no longer resolves.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();

        // Failure alerts (5.2.0). Defined once, in the migration that added it.
        m261009_000000_alerts::createAlertsTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::ALERTS);
        $this->dropTableIfExists(Table::RUN_ITEMS);
        $this->dropTableIfExists(Table::RUNS);
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::DEAD_LETTERS);
        $this->dropTableIfExists(Table::CURSORS);
        $this->dropTableIfExists(Table::PRICES);
        $this->dropTableIfExists(Table::ACCOUNTS);
        $this->dropTableIfExists(Table::LINKS);
        $this->dropTableIfExists(Table::MAPS);
        $this->dropTableIfExists(Table::CONNECTIONS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::CONNECTIONS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'connector' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'storeId' => $this->integer()->null(),
            'settings' => $this->text(),
            'sync' => $this->text(),
            'tokens' => $this->text(),
            'sortOrder' => $this->smallInteger()->unsigned()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The identity map. `localId` is nullable because a document can be known to the ERP
        // before it exists in Commerce (a product being pulled for the first time), and
        // `remoteId` is nullable because an order exists in Commerce before the ERP accepts it.
        $this->createTable(Table::LINKS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'entity' => $this->string(32)->notNull(),
            'localId' => $this->integer()->null(),
            'localUid' => $this->char(36)->null(),
            'naturalKey' => $this->string(255)->notNull(),
            'remoteId' => $this->string(255)->null(),
            'remoteKey' => $this->string(255)->null(),
            'contentHash' => $this->char(32)->null(),
            // Shipment rows carry the quantity they fulfilled. Without it "is this order fully
            // shipped?" cannot be answered across several partial deliveries, and partial
            // delivery is the normal case in every warehouse that has ever run out of something.
            'quantity' => $this->decimal(14, 4)->null(),
            'lastPulledAt' => $this->dateTime()->null(),
            'lastPushedAt' => $this->dateTime()->null(),
            'lastError' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::MAPS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'entity' => $this->string(32)->notNull(),
            'direction' => $this->tinyInteger()->notNull(),
            'rules' => $this->mediumText(),
            'options' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::RUNS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'entity' => $this->string(32)->notNull(),
            'direction' => $this->tinyInteger()->notNull(),
            'trigger' => $this->string(32)->notNull(),
            'status' => $this->string(16)->notNull(),
            'dryRun' => $this->boolean()->notNull()->defaultValue(false),
            'created' => $this->integer()->notNull()->defaultValue(0),
            'updated' => $this->integer()->notNull()->defaultValue(0),
            'skipped' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'requests' => $this->integer()->notNull()->defaultValue(0),
            'cursorBefore' => $this->string(255)->null(),
            'cursorAfter' => $this->string(255)->null(),
            'message' => $this->text(),
            'startedAt' => $this->dateTime()->null(),
            'finishedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::RUN_ITEMS, [
            'id' => $this->primaryKey(),
            'runId' => $this->integer()->notNull(),
            'naturalKey' => $this->string(255)->null(),
            'remoteId' => $this->string(255)->null(),
            'localId' => $this->integer()->null(),
            'action' => $this->string(16)->notNull(),
            'message' => $this->text(),
            'changes' => $this->mediumText(),
            'durationMs' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->null(),
            'runId' => $this->integer()->null(),
            'type' => $this->string(16)->notNull()->defaultValue('request'),
            'method' => $this->string(10)->null(),
            'url' => $this->text(),
            'requestHeaders' => $this->text(),
            'requestBody' => $this->mediumText(),
            'status' => $this->integer()->null(),
            'responseBody' => $this->mediumText(),
            'durationMs' => $this->integer()->null(),
            'attempt' => $this->tinyInteger()->null(),
            'message' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::DEAD_LETTERS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'entity' => $this->string(32)->notNull(),
            'direction' => $this->tinyInteger()->notNull(),
            'naturalKey' => $this->string(255)->notNull(),
            'localId' => $this->integer()->null(),
            'document' => $this->mediumText(),
            'error' => $this->text(),
            'attempts' => $this->integer()->notNull()->defaultValue(1),
            'retryable' => $this->boolean()->notNull()->defaultValue(true),
            'lastAttemptAt' => $this->dateTime()->null(),
            'resolvedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CURSORS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'entity' => $this->string(32)->notNull(),
            'direction' => $this->tinyInteger()->notNull(),
            'cursor' => $this->string(255)->null(),
            'watermark' => $this->dateTime()->null(),
            'lastRunAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Contract pricing does not go into Commerce's catalog pricing rules, and that is a
        // deliberate decision rather than a shortcut: a mid-market ERP routinely holds tens of
        // thousands of negotiated price lines, and one Commerce pricing rule per line would make
        // catalog price generation the slowest thing on the site. They live here and are resolved
        // for the one customer and the handful of SKUs actually in a cart.
        $this->createTable(Table::PRICES, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'naturalKey' => $this->string(255)->notNull(),
            'sku' => $this->string(255)->notNull(),
            'purchasableId' => $this->integer()->null(),
            'priceListCode' => $this->string(64)->null(),
            'customerCode' => $this->string(64)->null(),
            'customerGroupCode' => $this->string(64)->null(),
            'currency' => $this->string(3)->null(),
            'unitPrice' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'discountPercent' => $this->decimal(8, 4)->null(),
            'minQuantity' => $this->decimal(14, 4)->notNull()->defaultValue(1),
            'unitOfMeasure' => $this->string(24)->null(),
            'priceIncludesTax' => $this->boolean()->notNull()->defaultValue(false),
            'startsAt' => $this->dateTime()->null(),
            'endsAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ACCOUNTS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'userId' => $this->integer()->null(),
            'customerCode' => $this->string(64)->notNull(),
            'name' => $this->string()->null(),
            'priceListCode' => $this->string(64)->null(),
            'customerGroupCode' => $this->string(64)->null(),
            'currency' => $this->string(3)->null(),
            'paymentTermsCode' => $this->string(64)->null(),
            'salespersonCode' => $this->string(64)->null(),
            'discountPercent' => $this->decimal(8, 4)->null(),
            'creditLimit' => $this->decimal(14, 4)->null(),
            'balance' => $this->decimal(14, 4)->null(),
            'openOrders' => $this->decimal(14, 4)->null(),
            'overdueAmount' => $this->decimal(14, 4)->null(),
            'onHold' => $this->boolean()->notNull()->defaultValue(false),
            'taxExempt' => $this->boolean()->notNull()->defaultValue(false),
            'taxId' => $this->string(64)->null(),
            'openInvoices' => $this->mediumText(),
            'creditCheckedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::CONNECTIONS, ['handle'], true);
        $this->createIndex(null, Table::CONNECTIONS, ['connector'], false);

        // These two indexes *are* the duplicate protection. One local record maps to one ERP
        // record per entity, and vice versa; the database refuses anything else.
        $this->createIndex(null, Table::LINKS, ['connectionId', 'entity', 'naturalKey'], true);
        $this->createIndex(null, Table::LINKS, ['connectionId', 'entity', 'remoteId'], false);
        $this->createIndex(null, Table::LINKS, ['connectionId', 'entity', 'localId'], false);

        $this->createIndex(null, Table::MAPS, ['connectionId', 'entity', 'direction'], true);

        $this->createIndex(null, Table::RUNS, ['connectionId', 'entity', 'direction'], false);
        $this->createIndex(null, Table::RUNS, ['status'], false);
        $this->createIndex(null, Table::RUNS, ['dateCreated'], false);

        $this->createIndex(null, Table::RUN_ITEMS, ['runId'], false);
        $this->createIndex(null, Table::RUN_ITEMS, ['runId', 'action'], false);

        $this->createIndex(null, Table::LOG, ['connectionId', 'dateCreated'], false);
        $this->createIndex(null, Table::LOG, ['runId'], false);
        $this->createIndex(null, Table::LOG, ['status'], false);

        $this->createIndex(null, Table::DEAD_LETTERS, ['connectionId', 'entity', 'naturalKey'], false);
        $this->createIndex(null, Table::DEAD_LETTERS, ['resolvedAt'], false);

        $this->createIndex(null, Table::CURSORS, ['connectionId', 'entity', 'direction'], true);

        $this->createIndex(null, Table::PRICES, ['connectionId', 'naturalKey'], true);
        $this->createIndex(null, Table::PRICES, ['connectionId', 'sku'], false);
        $this->createIndex(null, Table::PRICES, ['connectionId', 'customerCode', 'sku'], false);
        $this->createIndex(null, Table::PRICES, ['connectionId', 'priceListCode', 'sku'], false);
        $this->createIndex(null, Table::PRICES, ['purchasableId'], false);

        $this->createIndex(null, Table::ACCOUNTS, ['connectionId', 'customerCode'], true);
        $this->createIndex(null, Table::ACCOUNTS, ['connectionId', 'userId'], false);

        // Deleting a connection takes its history with it — the rows are meaningless without the
        // credentials that produced them, and merchants expect "remove this ERP" to mean it.
        $this->addForeignKey(null, Table::LINKS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::MAPS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::RUNS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::RUN_ITEMS, ['runId'], Table::RUNS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::LOG, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::DEAD_LETTERS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::CURSORS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PRICES, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::ACCOUNTS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');

        // A deleted user takes their ERP profile with them, but the profile is not allowed to
        // stop the user being deleted, so this is the one element reference Erpy holds.
        $this->addForeignKey(null, Table::ACCOUNTS, ['userId'], \craft\db\Table::USERS, ['id'], 'CASCADE');

        // The log survives its run being pruned, so this one nulls rather than cascades.
        $this->addForeignKey(null, Table::LOG, ['runId'], Table::RUNS, ['id'], 'SET NULL');
    }
}
