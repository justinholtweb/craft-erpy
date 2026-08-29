<?php

namespace justinholtweb\erpy\records;

use craft\db\ActiveRecord;
use justinholtweb\erpy\db\Table;

/**
 * The identity map row: which local record is which ERP record.
 *
 * Everything else in Erpy can be rebuilt from the ERP. This table cannot — lose it and the next
 * sync creates a second copy of every order. It is the one table worth backing up on its own.
 */
class LinkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LINKS;
    }
}
