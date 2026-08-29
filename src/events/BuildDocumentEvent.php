<?php

namespace justinholtweb\erpy\events;

use justinholtweb\erpy\models\canonical\ErpDocument;
use justinholtweb\erpy\models\Connection;
use yii\base\Event;

/**
 * Fired after Erpy has built an outbound document and before the connector sees it.
 *
 * This is where a merchant's developer puts the rule their ERP consultant insists on and no
 * mapping screen could express — a project code derived from three fields, a warehouse chosen by
 * postcode. Set `isValid = false` to hold the document back.
 */
class BuildDocumentEvent extends Event
{
    public Connection $connection;

    public string $entity = '';

    public ErpDocument $document;

    /** The Commerce element the document was built from, when there is one. */
    public mixed $source = null;

    public bool $isValid = true;
}
