<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * A fulfilment posted in the ERP. Partial shipments are the normal case, not the exception, so
 * the lines carry quantities rather than a "shipped: yes" flag.
 */
class ErpShipment extends ErpDocument
{
    public string $orderNumber = '';

    /** The ERP's shipment / packing slip number. */
    public ?string $shipmentNumber = null;

    public ?string $trackingNumber = null;
    public ?string $trackingUrl = null;
    public ?string $carrier = null;
    public ?string $service = null;

    public ?DateTimeInterface $shippedAt = null;

    public ?string $warehouse = null;

    public ?float $weight = null;
    public ?string $weightUnit = null;

    /** @var array<int,array{sku:string,quantity:float,lineNumber?:int}> */
    public array $lines = [];

    public function naturalKey(): string
    {
        // A shipment with no number is keyed on its tracking number, and one with neither on its
        // contents — the same problem, and the same answer, as every other fulfilment feed.
        return $this->shipmentNumber
            ?: ($this->trackingNumber
                ? $this->orderNumber . '|' . strtolower($this->trackingNumber)
                : $this->orderNumber . '|' . md5((string)json_encode($this->lines)));
    }

    public function totalQuantity(): float
    {
        return array_sum(array_map(fn(array $line) => (float)($line['quantity'] ?? 0), $this->lines));
    }
}
