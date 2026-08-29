<?php

namespace justinholtweb\erpy\models\canonical;

/**
 * A variant, for the ERPs that model item variants natively (Business Central item variants,
 * NetSuite matrix items, Odoo product variants). ERPs that do not simply send one product per
 * SKU and Erpy treats each as a single-variant product, which is what Commerce does anyway.
 */
class ErpVariant extends ErpDocument
{
    public string $sku = '';
    public string $name = '';
    public bool $enabled = true;

    /** @var array<string,string> option code => value, e.g. `['COLOUR' => 'Red']` */
    public array $options = [];

    public ?float $price = null;
    public ?float $weight = null;
    public ?string $barcode = null;
    public ?float $stock = null;

    public function naturalKey(): string
    {
        return $this->sku;
    }
}
