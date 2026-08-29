<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * On-hand stock for one SKU in one location.
 *
 * `available` is the number worth showing a customer and it is rarely the same as `onHand`:
 * warehouses hold stock that is already allocated to somebody else's order. Connectors that can
 * get the ERP's own availability figure should send it; ones that cannot leave it null and the
 * engine falls back to on-hand minus allocated.
 */
class ErpStock extends ErpDocument
{
    public string $sku = '';

    /** The ERP location / warehouse code. Null means "the whole company". */
    public ?string $warehouse = null;

    public float $onHand = 0.0;

    public ?float $allocated = null;

    /** The ERP's own availability, when it computes one. */
    public ?float $available = null;

    /** Quantity on inbound purchase orders. */
    public ?float $incoming = null;

    public ?DateTimeInterface $nextRestockAt = null;

    /** Whether the ERP allows this item to be sold past zero. */
    public bool $allowBackorder = false;

    public function naturalKey(): string
    {
        return $this->sku . '|' . ($this->warehouse ?? '');
    }

    /**
     * The number to put in front of a customer.
     */
    public function sellable(): float
    {
        if ($this->available !== null) {
            return max(0.0, $this->available);
        }

        return max(0.0, $this->onHand - (float)($this->allocated ?? 0));
    }
}
