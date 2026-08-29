<?php

namespace justinholtweb\erpy\models;

use craft\base\Model;
use DateTime;

/**
 * A Craft user's B2B standing, as the ERP sees it.
 *
 * Kept as its own row rather than as user fields because it is ERP-owned data that changes
 * hourly, and because a storefront needs to read it on every cart page without loading a user
 * element's whole field layout.
 */
class Account extends Model
{
    public ?int $id = null;
    public ?int $connectionId = null;
    public ?int $userId = null;
    public string $customerCode = '';
    public ?string $name = null;
    public ?string $priceListCode = null;
    public ?string $customerGroupCode = null;
    public ?string $currency = null;
    public ?string $paymentTermsCode = null;
    public ?string $salespersonCode = null;
    public ?float $discountPercent = null;
    public ?float $creditLimit = null;
    public ?float $balance = null;
    public ?float $openOrders = null;
    public ?float $overdueAmount = null;
    public bool $onHold = false;
    public bool $taxExempt = false;
    public ?string $taxId = null;
    public ?string $openInvoices = null;
    public ?DateTime $creditCheckedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function openInvoiceList(): array
    {
        return json_decode((string)$this->openInvoices, true) ?: [];
    }

    /**
     * How much this account may still spend. Null means the ERP sets no limit, which is not the
     * same as zero — a storefront that confuses the two stops selling to its best customers.
     */
    public function availableCredit(): ?float
    {
        if ($this->creditLimit === null) {
            return null;
        }

        return $this->creditLimit - (float)$this->balance - (float)$this->openOrders;
    }

    public function canSpend(float $amount): bool
    {
        if ($this->onHold) {
            return false;
        }

        $available = $this->availableCredit();

        return $available === null || $amount <= $available;
    }

    /** How stale the credit figures are, in seconds. */
    public function creditAge(): ?int
    {
        return $this->creditCheckedAt ? time() - $this->creditCheckedAt->getTimestamp() : null;
    }
}
