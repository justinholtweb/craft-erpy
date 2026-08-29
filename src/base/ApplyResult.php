<?php

namespace justinholtweb\erpy\base;

use justinholtweb\erpy\models\RunItem;

/**
 * What happened when one inbound document was applied to Commerce.
 */
class ApplyResult
{
    public function __construct(
        public string $action = RunItem::ACTION_SKIPPED,
        public ?int $localId = null,
        public ?string $message = null,
        /** Field-by-field before/after, for the run detail screen. */
        public array $changes = [],
        /** False when the failure is the document's fault and retrying cannot help. */
        public bool $retryable = true,
    ) {
    }

    public static function created(int $localId, array $changes = []): self
    {
        return new self(action: RunItem::ACTION_CREATED, localId: $localId, changes: $changes);
    }

    public static function updated(int $localId, array $changes = []): self
    {
        return new self(action: RunItem::ACTION_UPDATED, localId: $localId, changes: $changes);
    }

    public static function skipped(?string $message = null, ?int $localId = null): self
    {
        return new self(action: RunItem::ACTION_SKIPPED, localId: $localId, message: $message);
    }

    public static function failed(string $message, bool $retryable = true): self
    {
        return new self(action: RunItem::ACTION_FAILED, message: $message, retryable: $retryable);
    }

    public function isFailure(): bool
    {
        return $this->action === RunItem::ACTION_FAILED;
    }

    public function changedAnything(): bool
    {
        return in_array($this->action, [RunItem::ACTION_CREATED, RunItem::ACTION_UPDATED], true);
    }
}
