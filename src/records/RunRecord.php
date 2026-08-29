<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * One sync run.
 */
class RunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RUNS;
    }
}
