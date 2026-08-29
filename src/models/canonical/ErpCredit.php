<?php

namespace justinholtweb\erpy\models\canonical;

/**
 * An account's financial standing, as the ERP sees it right now.
 *
 * This is what makes "pay on account" safe to offer: a storefront that can read a credit limit
 * and an aged balance can refuse a purchase order that would put the customer over, instead of
 * finding out three days later when accounts receivable notices.
 */
class ErpCredit extends ErpDocument
{
    public string $customerCode = '';

    public string $currency = 'USD';

    public ?float $creditLimit = null;

    /** Everything owed, invoiced or not. */
    public float $balance = 0.0;

    /** Orders placed but not yet invoiced, which most ERPs count against the limit. */
    public float $openOrders = 0.0;

    public float $overdueAmount = 0.0;

    public bool $onHold = false;

    public ?string $paymentTermsCode = null;

    /** @var array<int,array{invoiceNumber:string,balance:float,dueAt?:string,daysOverdue?:int}> */
    public array $openInvoices = [];

    public function naturalKey(): string
    {
        return $this->customerCode;
    }

    /**
     * How much this account may still spend. Null means the ERP sets no limit, which is not the
     * same as zero and must never be treated as it.
     */
    public function availableCredit(): ?float
    {
        if ($this->creditLimit === null) {
            return null;
        }

        return $this->creditLimit - $this->balance - $this->openOrders;
    }

    public function wouldExceedLimit(float $amount): bool
    {
        if ($this->onHold) {
            return true;
        }

        $available = $this->availableCredit();

        return $available !== null && $amount > $available;
    }
}
