<?php

namespace justinholtweb\erpy\models\canonical;

/**
 * One postal address. Commerce stores addresses as elements with a `countryCode`; ERPs store them
 * as anything from three lines and a country name to a normalised address book with its own ids.
 */
class ErpAddress extends ErpDocument
{
    public const TYPE_BILLING = 'billing';
    public const TYPE_SHIPPING = 'shipping';

    public string $type = self::TYPE_SHIPPING;

    /** The ERP's own code for this address, where it has an address book. */
    public ?string $code = null;

    public ?string $fullName = null;
    public ?string $organization = null;
    public ?string $addressLine1 = null;
    public ?string $addressLine2 = null;
    public ?string $addressLine3 = null;
    public ?string $locality = null;
    public ?string $administrativeArea = null;
    public ?string $postalCode = null;

    /** ISO 3166-1 alpha-2. Connectors are responsible for converting whatever the ERP uses. */
    public ?string $countryCode = null;

    public ?string $phone = null;
    public bool $isDefault = false;

    public function naturalKey(): string
    {
        return $this->code ?: implode('|', array_filter([
            $this->type,
            $this->addressLine1,
            $this->postalCode,
            $this->countryCode,
        ]));
    }

    public function isEmpty(): bool
    {
        return trim((string)$this->addressLine1) === '' && trim((string)$this->postalCode) === '';
    }
}
