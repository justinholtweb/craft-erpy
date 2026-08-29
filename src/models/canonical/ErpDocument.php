<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * The base for every canonical document.
 *
 * These are deliberately plain: no validation, no database, no element behaviour. A connector
 * builds one from an ERP payload and the engine reads it. The whole architecture rests on this
 * layer staying boring — the moment a document knows about Commerce or about a particular ERP,
 * thirteen connectors start disagreeing.
 */
abstract class ErpDocument
{
    /** The ERP's own primary key. This is what lands in the identity map. */
    public ?string $remoteId = null;

    /** The human-facing key a merchant would search the ERP for — `SO-00194`, `CUST0042`. */
    public ?string $remoteKey = null;

    /** When the ERP last changed this record, used to advance the delta cursor. */
    public ?DateTimeInterface $modifiedAt = null;

    /** The untouched ERP payload, kept for the run detail screen and for mapping rules. */
    public array $raw = [];

    /** Values the merchant mapped that no canonical field covers. */
    public array $extra = [];

    public function __construct(array $config = [])
    {
        $this->configure($config);
    }

    protected function configure(array $config): void
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            } else {
                $this->extra[$key] = $value;
            }
        }
    }

    /**
     * The key the identity map uses when the ERP has not given us an id yet — a SKU, a customer
     * code, an order number. Every document must be able to name itself without one.
     */
    abstract public function naturalKey(): string;

    public function toArray(): array
    {
        $out = [];

        foreach (get_object_vars($this) as $key => $value) {
            if ($key === 'raw') {
                continue;
            }

            $out[$key] = $this->flatten($value);
        }

        return $out;
    }

    private function flatten(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof self) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn($item) => $this->flatten($item), $value);
        }

        return $value;
    }

    /**
     * A stable fingerprint of the document's meaningful content.
     *
     * The engine stores this against the identity map so a delta sync that returns a record whose
     * fields have not actually changed costs one comparison instead of one element save. On a
     * 40,000-SKU catalogue that is the difference between a nightly sync taking minutes and
     * taking hours.
     */
    public function contentHash(): string
    {
        $data = $this->toArray();
        unset($data['modifiedAt'], $data['extra']);

        return md5((string)json_encode($data));
    }
}
