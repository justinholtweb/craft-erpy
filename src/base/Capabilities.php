<?php

namespace justinholtweb\erpy\base;

/**
 * What a connector can actually do.
 *
 * This is the single most important thing a connector declares. Erpy builds the entire connection
 * UI from it — which entities appear, which directions are offered, whether a delta sync is even
 * possible — so a connector never ships a template and can never advertise a flow it has not
 * implemented.
 */
class Capabilities
{
    /**
     * @var array<string,int> entity => Direction bitmask
     */
    private array $entities = [];

    /**
     * @var array<string,bool> entity => whether the ERP can filter by "modified since"
     */
    private array $delta = [];

    /**
     * @var array<string,int> entity => how many records the ERP will return per page
     */
    private array $pageSize = [];

    /**
     * @var array<string,bool> entity => whether writes can be batched into one request
     */
    private array $batch = [];

    private bool $webhooks = false;

    private bool $multiCompany = false;

    private bool $sandbox = false;

    public static function make(): self
    {
        return new self();
    }

    /**
     * Declare support for an entity.
     *
     * @param string $entity one of the Entity constants
     * @param int $direction a Direction bitmask
     * @param bool $delta whether the ERP supports a modified-since filter for this entity
     * @param int $pageSize the ERP's own page size, so the engine pages the way the ERP expects
     * @param bool $batch whether writes for this entity can be sent as one request
     */
    public function supports(
        string $entity,
        int $direction = Direction::PULL,
        bool $delta = false,
        int $pageSize = 100,
        bool $batch = false,
    ): self {
        $this->entities[$entity] = $direction;
        $this->delta[$entity] = $delta;
        $this->pageSize[$entity] = max(1, $pageSize);
        $this->batch[$entity] = $batch;

        return $this;
    }

    /** The ERP can call us, so we do not have to poll it. */
    public function withWebhooks(bool $value = true): self
    {
        $this->webhooks = $value;

        return $this;
    }

    /** The ERP partitions data by company/division/subsidiary/tenant. */
    public function withMultiCompany(bool $value = true): self
    {
        $this->multiCompany = $value;

        return $this;
    }

    /** The vendor offers a free sandbox a merchant can point Erpy at before going live. */
    public function withSandbox(bool $value = true): self
    {
        $this->sandbox = $value;

        return $this;
    }

    public function handles(string $entity, ?int $direction = null): bool
    {
        if (!isset($this->entities[$entity])) {
            return false;
        }

        if ($direction === null) {
            return true;
        }

        return Direction::allows($this->entities[$entity], $direction);
    }

    public function directionFor(string $entity): int
    {
        return $this->entities[$entity] ?? 0;
    }

    public function supportsDelta(string $entity): bool
    {
        return $this->delta[$entity] ?? false;
    }

    public function pageSizeFor(string $entity): int
    {
        return $this->pageSize[$entity] ?? 100;
    }

    public function supportsBatch(string $entity): bool
    {
        return $this->batch[$entity] ?? false;
    }

    public function supportsWebhooks(): bool
    {
        return $this->webhooks;
    }

    public function supportsMultiCompany(): bool
    {
        return $this->multiCompany;
    }

    public function hasSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * @return string[] the entities this connector handles, in canonical sync order
     */
    public function entities(): array
    {
        return array_values(array_filter(
            Entity::syncOrder(),
            fn(string $entity) => isset($this->entities[$entity]),
        ));
    }

    public function toArray(): array
    {
        $out = [];

        foreach ($this->entities() as $entity) {
            $out[$entity] = [
                'direction' => $this->entities[$entity],
                'delta' => $this->supportsDelta($entity),
                'pageSize' => $this->pageSizeFor($entity),
                'batch' => $this->supportsBatch($entity),
            ];
        }

        return [
            'entities' => $out,
            'webhooks' => $this->webhooks,
            'multiCompany' => $this->multiCompany,
            'sandbox' => $this->sandbox,
        ];
    }
}
