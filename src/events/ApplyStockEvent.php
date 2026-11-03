<?php

namespace justinholtweb\erpy\events;

use justinholtweb\erpy\base\ApplyResult;
use justinholtweb\erpy\models\canonical\ErpStock;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\FieldMap;
use yii\base\Event;

/**
 * Fired once per page of a stock pull, before any of it reaches Commerce.
 *
 * Commerce writes inventory one purchasable at a time and refreshes each one's stock cache on
 * every site as it goes, which is fine for an ERP sending a few changes and slow for a full
 * 40,000-line stock list. A site with a bulk writer takes the page here, writes what it can, and
 * puts an `ApplyResult` in `$results` under the same key as each document it handled. Erpy writes
 * the rest itself and still records every document in the run log, the identity map and the
 * dead letters, whoever wrote it.
 *
 * The documents have already been through `beforeApplyDocument` and the mapping overlay, and
 * `quantity()` gives the number Erpy itself would write, after the hold-back. Not fired on a dry
 * run.
 */
class ApplyStockEvent extends Event
{
    public Connection $connection;

    public FieldMap $map;

    /** @var array<int,ErpStock> */
    public array $documents = [];

    /** @var array<int,ApplyResult> the documents a handler wrote, keyed like `$documents` */
    public array $results = [];

    /** `available` or `onHand`: the Commerce figure the ERP's number is meant to set. */
    public string $target = 'available';

    /** The quantity Erpy would write for one of `$documents`. */
    public function quantity(ErpStock $document): int
    {
        return \justinholtweb\erpy\Plugin::getInstance()->getCatalog()->stockQuantity($document, $this->map);
    }
}
