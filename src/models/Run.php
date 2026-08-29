<?php

namespace justinholtweb\erpy\models;

use Craft;
use craft\base\Model;
use DateTime;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;

/**
 * One sync run: what was asked for, what happened, and how long it took.
 */
class Run extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_QUEUE = 'queue';
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_WEBHOOK = 'webhook';
    public const TRIGGER_CONSOLE = 'console';
    public const TRIGGER_EVENT = 'event';

    public ?int $id = null;
    public ?int $connectionId = null;
    public string $entity = '';
    public int $direction = Direction::PULL;
    public string $trigger = self::TRIGGER_MANUAL;
    public string $status = self::STATUS_RUNNING;
    public bool $dryRun = false;
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $failed = 0;
    public int $requests = 0;
    public ?string $cursorBefore = null;
    public ?string $cursorAfter = null;
    public ?string $message = null;
    public ?DateTime $startedAt = null;
    public ?DateTime $finishedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function total(): int
    {
        return $this->created + $this->updated + $this->skipped + $this->failed;
    }

    /** Records that actually changed something. */
    public function touched(): int
    {
        return $this->created + $this->updated;
    }

    public function durationMs(): ?int
    {
        if (!$this->startedAt || !$this->finishedAt) {
            return null;
        }

        return (int)round(($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000);
    }

    public function durationLabel(): string
    {
        $ms = $this->durationMs();

        if ($ms === null) {
            return '—';
        }

        if ($ms < 1000) {
            return $ms . 'ms';
        }

        $seconds = $ms / 1000;

        return $seconds < 60
            ? number_format($seconds, 1) . 's'
            : floor($seconds / 60) . 'm ' . str_pad((string)round($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
    }

    public function label(): string
    {
        return Craft::t('erpy', '{entity}, {direction}', [
            'entity' => Entity::displayName($this->entity),
            'direction' => Direction::displayName($this->direction),
        ]);
    }

    /** Craft's status-badge vocabulary, so the CP renders these without a custom stylesheet. */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => 'green',
            self::STATUS_PARTIAL => 'orange',
            self::STATUS_FAILED => 'red',
            self::STATUS_RUNNING => 'blue',
            default => 'grey',
        };
    }

    /**
     * A run that failed on every record is a failure; one that got most of them through is
     * partial. The distinction matters because the first means "your credentials are wrong" and
     * the second means "twelve products have a problem".
     */
    public function resolveStatus(): string
    {
        if ($this->failed === 0) {
            return self::STATUS_SUCCESS;
        }

        return $this->touched() > 0 || $this->skipped > 0 ? self::STATUS_PARTIAL : self::STATUS_FAILED;
    }
}
