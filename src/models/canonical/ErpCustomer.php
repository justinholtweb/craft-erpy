<?php

namespace justinholtweb\erpy\models\canonical;

/**
 * A business partner / debtor / account / customer, depending on which ERP is asked.
 *
 * The B2B fields here are the ones a storefront actually needs to behave correctly: which price
 * list this account is on, what its payment terms are, whether it is over its credit limit and
 * therefore should not be allowed to check out on account at all.
 */
class ErpCustomer extends ErpDocument
{
    /** The ERP's customer number. Stored on the Craft user/customer so orders can quote it. */
    public string $code = '';

    public string $name = '';
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $website = null;

    public bool $enabled = true;

    /** The ERP has put this account on stop. Nothing should be sold to it on account. */
    public bool $onHold = false;

    public ?string $taxId = null;
    public ?string $taxCategory = null;
    public bool $taxExempt = false;

    public ?string $currency = null;

    /** The price list this account buys on — the link between a user and their B2B pricing. */
    public ?string $priceListCode = null;

    public ?string $customerGroupCode = null;
    public ?string $paymentTermsCode = null;
    public ?string $paymentMethodCode = null;
    public ?string $shippingMethodCode = null;
    public ?string $salespersonCode = null;

    public ?float $creditLimit = null;
    public ?float $balance = null;

    /** The ERP's default discount for this account, where it works that way. */
    public ?float $discountPercent = null;

    public ?string $language = null;

    /** @var ErpAddress[] */
    public array $addresses = [];

    /** @var array<int,array{name?:string,email?:string,phone?:string,role?:string}> */
    public array $contacts = [];

    public function naturalKey(): string
    {
        return $this->code;
    }

    public function defaultAddress(string $type): ?ErpAddress
    {
        $fallback = null;

        foreach ($this->addresses as $address) {
            if ($address->type !== $type) {
                continue;
            }

            if ($address->isDefault) {
                return $address;
            }

            $fallback ??= $address;
        }

        return $fallback;
    }
}
