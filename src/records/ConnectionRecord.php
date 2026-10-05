<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * One configured link to one ERP company.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $connector
 * @property bool $enabled
 * @property int|null $storeId
 * @property string|null $settings
 * @property string|null $tokens
 * @property string|null $sync
 * @property int $sortOrder
 */
class ConnectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CONNECTIONS;
    }
}
