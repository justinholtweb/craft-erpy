<?php

namespace justinholtweb\erpy\models;

use craft\base\Model;
use DateTime;

/**
 * What happened to one document inside a run.
 */
class RunItem extends Model
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_FAILED = 'failed';

    public ?int $id = null;
    public ?int $runId = null;
    public ?string $naturalKey = null;
    public ?string $remoteId = null;
    public ?int $localId = null;
    public string $action = self::ACTION_SKIPPED;
    public ?string $message = null;
    public ?string $changes = null;
    public ?int $durationMs = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function changeList(): array
    {
        return json_decode((string)$this->changes, true) ?: [];
    }
}
