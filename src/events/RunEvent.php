<?php

namespace justinholtweb\erpy\events;

use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\Run;
use yii\base\Event;

/**
 * Fired around a sync run. Set `isValid = false` on the "before" event to stop it.
 */
class RunEvent extends Event
{
    public Run $run;

    public Connection $connection;

    public bool $isValid = true;
}
