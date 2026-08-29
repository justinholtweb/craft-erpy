<?php

namespace justinholtweb\erpy\models;

use craft\base\Model;
use DateTime;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;

/**
 * A document that could not be delivered, kept whole so it can be replayed.
 */
class DeadLetter extends Model
{
    public ?int $id = null;
    public ?int $connectionId = null;
    public string $entity = '';
    public int $direction = Direction::PUSH;
    public string $naturalKey = '';
    public ?int $localId = null;
    public ?string $document = null;
    public ?string $error = null;
    public int $attempts = 1;
    public bool $retryable = true;
    public ?DateTime $lastAttemptAt = null;
    public ?DateTime $resolvedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function documentArray(): array
    {
        return json_decode((string)$this->document, true) ?: [];
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    public function label(): string
    {
        return Entity::displayName($this->entity) . ' · ' . $this->naturalKey;
    }
}
