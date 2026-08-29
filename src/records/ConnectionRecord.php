<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * One configured link to one ERP company.
 */
class ConnectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CONNECTIONS;
    }
}
