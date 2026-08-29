<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * A sales order on its way into the ERP.
 *
 * This is the document merchants care most about being right, and the one where a bug is most
 * expensive: a duplicated order gets picked and shipped twice. The identity map keyed on
 * `orderNumber` is what stops that, and it is checked before the write, not after.
 */
class ErpOrder extends ErpDocument
{
    /** Commerce's order number. The idempotency key for the whole push. */
    public string $orderNumber = '';

    /** A shorter reference the merchant recognises — Commerce's `reference`. */
    public ?string $reference = null;

    public ?DateTimeInterface $orderedAt = null;
    public ?DateTimeInterface $requestedDeliveryAt = null;

    /** The ERP customer number this order belongs to. */
    public ?string $customerCode = null;

    /** The ERP's internal customer id, from the identity map, when there is one. */
    public ?string $customerRemoteId = null;

    /** Set for guests, so a connector can use its ERP's one-off / cash customer account. */
    public bool $isGuest = false;

    public ?string $email = null;
    public ?string $phone = null;

    public string $currency = 'USD';

    /** The rate used, for ERPs that want the order in company currency too. */
    public ?float $exchangeRate = null;

    public ?ErpAddress $billingAddress = null;
    public ?ErpAddress $shippingAddress = null;

    /** @var ErpOrderLine[] */
    public array $lines = [];

    public ?string $shippingMethodCode = null;
    public ?string $shippingMethodName = null;
    public float $shippingTotal = 0.0;
    public float $shippingTax = 0.0;

    public float $discountTotal = 0.0;

    /** @var array<int,array{code?:string,description?:string,amount?:float}> */
    public array $discounts = [];

    public float $itemTotal = 0.0;
    public float $taxTotal = 0.0;
    public float $total = 0.0;

    public bool $pricesIncludeTax = false;

    public ?string $paymentMethodCode = null;
    public ?string $paymentReference = null;
    public bool $isPaid = false;
    public float $amountPaid = 0.0;

    public ?string $couponCode = null;
    public ?string $customerNote = null;
    public ?string $salespersonCode = null;
    public ?string $priceListCode = null;
    public ?string $warehouse = null;

    /** Values a merchant mapped onto ERP fields Erpy has no opinion about. */
    public array $customFields = [];

    public function naturalKey(): string
    {
        return $this->orderNumber;
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function totalQuantity(): float
    {
        return array_sum(array_map(fn(ErpOrderLine $line) => $line->quantity, $this->lines));
    }
}
