<?php

namespace justinholtweb\erpy\models\canonical;

/**
 * One line of a sales order.
 *
 * `unitPrice` is always exclusive of tax and always in the order's currency. Commerce and most
 * ERPs agree on that; the ones that do not are the connector's problem to normalise, not the
 * engine's.
 */
class ErpOrderLine extends ErpDocument
{
    public int $lineNumber = 0;

    public string $sku = '';

    /** The ERP's internal item id, when the identity map already knows it. */
    public ?string $remoteItemId = null;

    public ?string $description = null;

    public float $quantity = 0.0;

    public ?string $unitOfMeasure = null;

    public float $unitPrice = 0.0;

    /** A per-line discount amount, already subtracted from `lineTotal`. */
    public float $discount = 0.0;

    public ?float $discountPercent = null;

    public ?string $taxCode = null;

    public float $taxAmount = 0.0;

    /** Quantity × unit price, less discount, excluding tax. */
    public float $lineTotal = 0.0;

    public ?string $warehouse = null;

    /** Set when the line is a shipping charge rather than goods, for ERPs that want it inline. */
    public bool $isShipping = false;

    /** Options, personalisation and anything else that must reach the picker. */
    public array $notes = [];

    public function naturalKey(): string
    {
        return $this->sku . '|' . $this->lineNumber;
    }
}
