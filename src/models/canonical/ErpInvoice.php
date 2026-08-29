<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * A posted sales invoice or credit note.
 *
 * Pulled so a B2B storefront can show an account its own invoice history and outstanding balance
 * without the merchant building a second reporting pipeline.
 */
class ErpInvoice extends ErpDocument
{
    public string $invoiceNumber = '';

    public ?string $orderNumber = null;
    public ?string $customerCode = null;

    public ?DateTimeInterface $issuedAt = null;
    public ?DateTimeInterface $dueAt = null;

    public string $currency = 'USD';

    public float $subtotal = 0.0;
    public float $taxTotal = 0.0;
    public float $total = 0.0;
    public float $amountPaid = 0.0;
    public float $balance = 0.0;

    /** A credit note is a negative invoice; ERPs disagree about whether the sign says so. */
    public bool $isCreditNote = false;

    public bool $isPaid = false;
    public bool $isOverdue = false;

    public ?string $status = null;

    /** Where the ERP will serve the PDF, when it will. */
    public ?string $documentUrl = null;

    /** @var array<int,array{sku?:string,description?:string,quantity?:float,unitPrice?:float,lineTotal?:float}> */
    public array $lines = [];

    public function naturalKey(): string
    {
        return $this->invoiceNumber;
    }
}
