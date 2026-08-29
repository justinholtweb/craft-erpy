<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * One price line: this SKU, for this audience, at this quantity, between these dates.
 *
 * This is the entity that justifies the whole plugin for a B2B merchant. Their ERP holds
 * negotiated pricing per customer, quantity breaks, campaign prices with end dates and
 * currency-specific lists, and none of it can sensibly be retyped into Commerce. Every ERP models
 * it differently and all of them reduce to this row.
 */
class ErpPrice extends ErpDocument
{
    public string $sku = '';

    /** The ERP's price list / price group / sales price code. Null means the base price. */
    public ?string $priceListCode = null;

    /** Set when this price belongs to exactly one customer. */
    public ?string $customerCode = null;

    /** Set when it belongs to a customer group / price group / customer class. */
    public ?string $customerGroupCode = null;

    public ?string $currency = null;

    public float $unitPrice = 0.0;

    /** A percentage off the base price, for ERPs that express discounts rather than prices. */
    public ?float $discountPercent = null;

    /** The quantity at which this price starts applying. 1 (or 0) means it always does. */
    public float $minQuantity = 1.0;

    public ?string $unitOfMeasure = null;

    public ?DateTimeInterface $startsAt = null;
    public ?DateTimeInterface $endsAt = null;

    /** Whether the price already has tax in it. Wrong answers here are expensive. */
    public bool $priceIncludesTax = false;

    public function naturalKey(): string
    {
        return implode('|', [
            $this->sku,
            $this->priceListCode ?? '',
            $this->customerCode ?? '',
            $this->customerGroupCode ?? '',
            $this->currency ?? '',
            rtrim(rtrim(number_format($this->minQuantity, 4, '.', ''), '0'), '.'),
        ]);
    }

    public function isActiveOn(DateTimeInterface $moment): bool
    {
        if ($this->startsAt !== null && $moment < $this->startsAt) {
            return false;
        }

        if ($this->endsAt !== null && $moment > $this->endsAt) {
            return false;
        }

        return true;
    }
}
