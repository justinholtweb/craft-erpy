<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * A payment or settlement, in either direction: a Commerce capture on its way to the ERP's cash
 * receipts, or an ERP-side payment coming back so an account's balance is honest.
 */
class ErpPayment extends ErpDocument
{
    public ?string $reference = null;

    public ?string $orderNumber = null;
    public ?string $invoiceNumber = null;
    public ?string $customerCode = null;

    public float $amount = 0.0;
    public string $currency = 'USD';

    public ?string $methodCode = null;
    public ?string $gateway = null;
    public ?string $transactionId = null;

    public ?DateTimeInterface $paidAt = null;

    public bool $isRefund = false;

    public function naturalKey(): string
    {
        return $this->reference
            ?: ($this->transactionId ?: ($this->orderNumber . '|' . number_format($this->amount, 2, '.', '')));
    }
}
