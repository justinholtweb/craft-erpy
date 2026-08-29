<?php

namespace justinholtweb\erpy\base;

use DateTimeInterface;

/**
 * What the engine is asking a connector to go and get.
 *
 * A connector reads this and builds one request; it never decides *what* to fetch on its own.
 * That keeps "resync everything", "resync since yesterday" and "resync just this SKU" the same
 * code path in every connector.
 */
class FetchCriteria
{
    /** Only records changed at or after this moment. Null means everything. */
    public ?DateTimeInterface $since = null;

    /**
     * The opaque cursor the connector handed back on the previous page. Connectors put whatever
     * they like in here — an OData `@odata.nextLink`, a NetSuite offset, an Odoo row id.
     */
    public ?string $cursor = null;

    /** How many records to ask for. Defaults to the connector's declared page size. */
    public int $limit = 100;

    /** Restrict to specific ERP record ids, for a targeted resync of one product or customer. */
    public array $ids = [];

    /** Free-form connector-specific filters, taken from the connection's sync settings. */
    public array $filters = [];

    /** The company/division/subsidiary to read from, when the ERP partitions data. */
    public ?string $company = null;

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function isDelta(): bool
    {
        return $this->since !== null;
    }

    public function withCursor(?string $cursor): self
    {
        $clone = clone $this;
        $clone->cursor = $cursor;

        return $clone;
    }
}
