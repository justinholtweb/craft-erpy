<?php

namespace justinholtweb\erpy\models\canonical;

use DateTimeInterface;

/**
 * What the ERP now thinks of an order Erpy sent it.
 *
 * Deliberately a small set of booleans rather than the ERP's own status vocabulary: every ERP has
 * a different one and merchants map it to Commerce order statuses themselves. The booleans are
 * what the engine can act on without being told.
 */
class ErpOrderStatus extends ErpDocument
{
    /** Commerce's order number, so the status can find its way home. */
    public string $orderNumber = '';

    /** The ERP's own status string, shown to the merchant and mappable to a Commerce status. */
    public ?string $status = null;

    public ?string $statusCode = null;

    public bool $isCancelled = false;
    public bool $isOnHold = false;
    public bool $isPicking = false;
    public bool $isShipped = false;
    public bool $isPartiallyShipped = false;
    public bool $isInvoiced = false;
    public bool $isClosed = false;

    public ?string $invoiceNumber = null;
    public ?DateTimeInterface $statusChangedAt = null;

    /** Why the ERP rejected or held the order, when it says. */
    public ?string $message = null;

    public function naturalKey(): string
    {
        return $this->orderNumber;
    }
}
