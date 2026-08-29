<?php

namespace justinholtweb\erpy\models\canonical;

/**
 * A sellable item as the ERP understands it.
 *
 * ERPs are the system of record for what a thing *is* — its code, its unit of measure, its tax
 * class, whether it is discontinued. They are usually a poor system of record for how it is
 * *sold*: marketing copy, imagery and merchandising belong in Craft. Erpy's default mapping
 * respects that split, and a merchant who disagrees can map any field either way.
 */
class ErpProduct extends ErpDocument
{
    /** The ERP item number. This is the join between the two systems and must be unique. */
    public string $sku = '';

    public string $name = '';
    public ?string $description = null;

    /** Whether the ERP considers this item sellable right now. */
    public bool $enabled = true;

    /** Blocked, discontinued or otherwise not to be ordered even though it still exists. */
    public bool $blocked = false;

    /** The ERP's item category / product group / item class. */
    public ?string $category = null;

    public ?string $brand = null;
    public ?string $barcode = null;

    /** Base unit of measure — `EACH`, `BOX`, `KG`. */
    public ?string $unitOfMeasure = null;

    /** Base price in the ERP's own currency, before any price list applies. */
    public ?float $price = null;
    public ?string $currency = null;

    /** The ERP's cost, which some merchants surface as an internal margin field. */
    public ?float $cost = null;

    public ?float $weight = null;
    public ?string $weightUnit = null;
    public ?float $length = null;
    public ?float $width = null;
    public ?float $height = null;
    public ?string $dimensionUnit = null;

    public ?string $taxCategory = null;

    /** Whether stock is tracked at all. Non-stock and service items exist in every ERP. */
    public bool $tracksInventory = true;

    /** @var string[] */
    public array $images = [];

    /** @var array<string,mixed> ERP attributes/dimensions/characteristics, keyed by code */
    public array $attributes = [];

    /** @var ErpVariant[] */
    public array $variants = [];

    public function naturalKey(): string
    {
        return $this->sku;
    }

    public function hasVariants(): bool
    {
        return $this->variants !== [];
    }
}
