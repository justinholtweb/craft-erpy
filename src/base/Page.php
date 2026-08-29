<?php

namespace justinholtweb\erpy\base;

/**
 * One page of canonical documents, plus whatever the connector needs to ask for the next one.
 */
class Page
{
    /**
     * @param object[] $items canonical DTOs
     * @param string|null $cursor pass back to the connector for the next page; null ends the run
     * @param int|null $total the ERP's own count, when it volunteers one
     */
    public function __construct(
        public array $items = [],
        public ?string $cursor = null,
        public ?int $total = null,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->cursor !== null && $this->items !== [];
    }

    public static function empty(): self
    {
        return new self();
    }
}
