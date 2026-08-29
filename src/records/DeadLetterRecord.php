<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * A document the ERP would not take.
 */
class DeadLetterRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DEAD_LETTERS;
    }
}
