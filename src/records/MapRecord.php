<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * Field mapping rules for one connection, entity and direction.
 */
class MapRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::MAPS;
    }
}
