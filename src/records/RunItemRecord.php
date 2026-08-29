<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * One document handled inside a run.
 */
class RunItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RUN_ITEMS;
    }
}
