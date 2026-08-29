<?php

namespace justinholtweb\erpy\events;

use justinholtweb\erpy\models\canonical\ErpDocument;
use justinholtweb\erpy\models\Connection;
use yii\base\Event;

/**
 * Fired for each inbound document before it reaches Commerce.
 *
 * The document is writable, which is the escape hatch for the one thing no mapping UI can do:
 * logic. Replace it, adjust it, or set `isValid = false` to drop it.
 */
class ApplyDocumentEvent extends Event
{
    public Connection $connection;

    public string $entity = '';

    public ErpDocument $document;

    public bool $isValid = true;
}
