<?php

namespace justinholtweb\erpy\models;

use craft\base\Model;
use DateTime;

/**
 * One row of the identity map.
 */
class Link extends Model
{
    public ?int $id = null;
    public ?int $connectionId = null;
    public string $entity = '';
    public ?int $localId = null;
    public ?string $localUid = null;
    public string $naturalKey = '';
    public ?string $remoteId = null;
    public ?string $remoteKey = null;
    public ?string $contentHash = null;
    public ?float $quantity = null;
    public ?DateTime $lastPulledAt = null;
    public ?DateTime $lastPushedAt = null;
    public ?string $lastError = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** Whether the ERP has acknowledged this document at all. */
    public function isDelivered(): bool
    {
        return $this->remoteId !== null && $this->remoteId !== '';
    }
}
