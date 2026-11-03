<?php

namespace justinholtweb\erpy\base;

/**
 * One page of canonical documents, plus whatever the connector needs to ask for the next one.
 */
class Page
{
    /**
     * Records the connector found on this page but could not read at all — a CSV row with the
     * wrong number of columns, a row that is not valid in the file's encoding. The engine
     * records each as a failed item and a dead letter, so one bad line in a 40,000-line file
     * costs that line rather than the whole run.
     *
     * @var array<int,array{key:string,message:string,raw:array}>
     */
    public array $rejected = [];

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

    /**
     * Report a record that could not be turned into a document.
     *
     * `$key` should be the natural key the document would have had, when the connector can tell,
     * so the dead letter clears itself once a corrected file brings the same record back.
     */
    public function reject(string $key, string $message, array $raw = []): self
    {
        $this->rejected[] = ['key' => $key, 'message' => $message, 'raw' => $raw];

        return $this;
    }

    public function hasMore(): bool
    {
        // A page of nothing but rejects is still a page: stopping there would drop the rest of
        // the file because one stretch of it was unreadable.
        return $this->cursor !== null && ($this->items !== [] || $this->rejected !== []);
    }

    public static function empty(): self
    {
        return new self();
    }
}
