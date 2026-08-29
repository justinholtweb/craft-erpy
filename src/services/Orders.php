<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderHistory;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use DateTime;
use justinholtweb\erpy\base\ApplyResult;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderLine;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPayment;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\FieldMap;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * Orders, in both directions.
 *
 * `build()` is one of Erpy's two invariants: it is the only place a Commerce order becomes an ERP
 * document. The queue job, the console command and the control panel's "preview payload" button
 * all go through it, which is what makes the preview trustworthy — it is not a rendering of what
 * would be sent, it is the thing that gets sent.
 */
class Orders extends Component
{
    // ---------------------------------------------------------------------------------------
    // Outbound
    // ---------------------------------------------------------------------------------------

    /**
     * Turn a Commerce order into a canonical ERP order.
     */
    public function build(Connection $connection, Order $order): ErpOrder
    {
        $map = Plugin::getInstance()->getMapping()->get($connection, Entity::ORDER, Direction::PUSH);
        $customer = $order->getCustomer();
        $account = $customer ? Plugin::getInstance()->getAccounts()->forUser($customer, $connection) : null;

        $document = new ErpOrder([
            'orderNumber' => (string)$order->number,
            'reference' => $order->reference,
            'orderedAt' => $order->dateOrdered ?? $order->dateCreated,
            'email' => $order->email,
            'currency' => (string)($order->currency ?: $order->paymentCurrency),
            // Commerce 5 attaches an inactive user to every guest checkout, so `getCustomer()`
            // being non-null does not mean somebody registered. What a connector actually needs
            // to know is whether this order belongs to a real account.
            'isGuest' => $customer === null || !$customer->active,
            'customerCode' => $account?->customerCode ?? $map->option('guestCustomerCode'),
            'customerRemoteId' => $this->customerRemoteId($connection, $account?->customerCode),
            'billingAddress' => $this->addressFrom($order->getBillingAddress(), ErpAddress::TYPE_BILLING),
            'shippingAddress' => $this->addressFrom($order->getShippingAddress(), ErpAddress::TYPE_SHIPPING),
            'shippingMethodCode' => $this->shippingCode($order, $map),
            'shippingMethodName' => $order->getShippingMethod()?->getName(),
            'shippingTotal' => (float)$order->getTotalShippingCost(),
            'discountTotal' => abs((float)$order->getTotalDiscount()),
            'itemTotal' => (float)$order->getItemSubtotal(),
            'taxTotal' => (float)$order->getTotalTax(),
            'total' => (float)$order->getTotalPrice(),
            'amountPaid' => (float)$order->getTotalPaid(),
            'isPaid' => $order->getIsPaid(),
            'couponCode' => $order->couponCode,
            'customerNote' => $order->message ?: null,
            'priceListCode' => $account?->priceListCode,
            'paymentTermsCode' => $account?->paymentTermsCode ?? null,
            'salespersonCode' => $account?->salespersonCode,
            'warehouse' => $map->option('warehouse'),
        ]);

        $document->lines = $this->buildLines($order, $map);
        $document->paymentMethodCode = $this->paymentCode($order, $map);
        $document->paymentReference = $this->paymentReference($order);
        $document->raw = ['orderId' => $order->id];

        $context = ['order' => $order, 'customer' => $customer, 'account' => $account];
        $mapping = Plugin::getInstance()->getMapping();

        // Merchant mapping runs last so it can override anything above, including fields the
        // connector would otherwise fill in from its own defaults. Rules targeting a canonical
        // field rewrite the document; everything else becomes an ERP field the connector passes
        // through untouched.
        $mapping->overlay($map, $document, $context);

        foreach ($mapping->apply($map, $document, $context) as $target => $value) {
            $document->customFields[$target] = $value;
        }

        return $document;
    }

    /**
     * @return ErpOrderLine[]
     */
    private function buildLines(Order $order, FieldMap $map): array
    {
        $lines = [];
        $number = 1;

        foreach ($order->getLineItems() as $item) {
            $line = new ErpOrderLine([
                'lineNumber' => $number * 10,
                'sku' => (string)$item->getSku(),
                'description' => $item->getDescription(),
                'quantity' => (float)$item->qty,
                'unitPrice' => (float)$item->getPrice(),
                'discount' => abs((float)$item->getDiscount()),
                'taxAmount' => (float)$item->getTax(),
                'lineTotal' => (float)$item->getSubtotal() - abs((float)$item->getDiscount()),
                'warehouse' => $map->option('warehouse'),
            ]);

            // Options and personalisation have to reach whoever picks the order, and every ERP
            // has somewhere to put a line note even when it has nowhere to put structured data.
            $options = $item->getOptions();

            if ($options !== []) {
                foreach ($options as $key => $value) {
                    $line->notes[] = is_scalar($value) ? "$key: $value" : $key;
                }
            }

            if ($item->note) {
                $line->notes[] = $item->note;
            }

            $lines[] = $line;
            $number++;
        }

        // Shipping as a line is how most ERPs actually want it, but not all — the connector
        // decides, because the ERP's own conventions are its business, not the engine's.
        if ($map->option('shippingAsLine', false) && (float)$order->getTotalShippingCost() > 0) {
            $lines[] = new ErpOrderLine([
                'lineNumber' => $number * 10,
                'sku' => (string)$map->option('shippingSku', 'SHIPPING'),
                'description' => $order->getShippingMethod()?->getName() ?? Craft::t('erpy', 'Shipping'),
                'quantity' => 1,
                'unitPrice' => (float)$order->getTotalShippingCost(),
                'lineTotal' => (float)$order->getTotalShippingCost(),
                'isShipping' => true,
            ]);
        }

        return $lines;
    }

    private function addressFrom(?Address $address, string $type): ?ErpAddress
    {
        if (!$address) {
            return null;
        }

        return new ErpAddress([
            'type' => $type,
            'fullName' => $address->fullName ?: trim(($address->firstName ?? '') . ' ' . ($address->lastName ?? '')) ?: null,
            'organization' => $address->organization,
            'addressLine1' => $address->addressLine1,
            'addressLine2' => $address->addressLine2,
            'addressLine3' => $address->addressLine3,
            'locality' => $address->locality,
            'administrativeArea' => $address->administrativeArea,
            'postalCode' => $address->postalCode,
            'countryCode' => $address->countryCode,
            // Craft addresses have no phone of their own; a merchant who collects one has put it
            // in a custom field, which the mapping rules can reach.
            'phone' => null,
        ]);
    }

    private function shippingCode(Order $order, FieldMap $map): ?string
    {
        $handle = $order->shippingMethodHandle;

        if (!$handle) {
            return null;
        }

        $mapped = (array)$map->option('shippingMethods', []);

        return $mapped[$handle] ?? $handle;
    }

    private function paymentCode(Order $order, FieldMap $map): ?string
    {
        $gateway = $order->getGateway();

        if (!$gateway) {
            return null;
        }

        $mapped = (array)$map->option('paymentMethods', []);

        return $mapped[$gateway->handle] ?? $gateway->handle;
    }

    private function paymentReference(Order $order): ?string
    {
        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->status === 'success' && in_array($transaction->type, ['purchase', 'capture'], true)) {
                return $transaction->reference ?: $transaction->hash;
            }
        }

        return null;
    }

    private function customerRemoteId(Connection $connection, ?string $customerCode): ?string
    {
        if (!$customerCode) {
            return null;
        }

        return Plugin::getInstance()->getLinks()
            ->find($connection, Entity::CUSTOMER, $customerCode)?->remoteId;
    }

    // ---------------------------------------------------------------------------------------
    // Inbound
    // ---------------------------------------------------------------------------------------

    public function applyStatus(Connection $connection, ErpOrderStatus $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        $order = $this->findOrder($document->orderNumber);

        if (!$order) {
            return ApplyResult::skipped(Craft::t('erpy', 'No order numbered “{number}”.', ['number' => $document->orderNumber]));
        }

        $statusId = $this->resolveOrderStatusId($document, $map);

        if ($dryRun) {
            return ApplyResult::skipped($statusId
                ? Craft::t('erpy', 'Would move the order to a new status.')
                : Craft::t('erpy', 'No Commerce status is mapped to “{status}”.', ['status' => (string)$document->status]));
        }

        $changes = [];

        if ($statusId && $order->orderStatusId !== $statusId) {
            $changes['orderStatus'] = [$order->orderStatusId, $statusId];
            $order->orderStatusId = $statusId;

            if (!Craft::$app->getElements()->saveElement($order, false)) {
                return ApplyResult::failed(Craft::t('erpy', 'Craft would not save the order.'));
            }
        } elseif ($document->message || $document->status) {
            // A note that is not a status change has to be written straight to the history:
            // Commerce only records one (and only sends the status email) when the status
            // actually moves, so a "picking started" note would otherwise vanish.
            $this->writeHistory($order, $document);
            $changes['note'] = [null, $document->status ?? $document->message];
        }

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: Entity::ORDER_STATUS,
            naturalKey: $document->naturalKey(),
            localId: $order->id,
            remoteId: $document->remoteId,
            remoteKey: $document->invoiceNumber,
            contentHash: $document->contentHash(),
            pulledAt: new DateTime(),
        );

        return $changes === []
            ? ApplyResult::skipped(Craft::t('erpy', 'Unchanged.'), $order->id)
            : ApplyResult::updated($order->id, $changes);
    }

    private function writeHistory(Order $order, ErpOrderStatus $document): void
    {
        try {
            $history = new OrderHistory([
                'orderId' => $order->id,
                'prevOrderStatusId' => $order->orderStatusId,
                'newOrderStatusId' => $order->orderStatusId,
                'userId' => null,
                'message' => trim(sprintf('%s %s', (string)$document->status, (string)$document->message)),
                'storeId' => $order->storeId,
            ]);

            Commerce::getInstance()->getOrderHistories()->saveOrderHistory($history);
        } catch (Throwable $e) {
            Craft::warning('Erpy could not write an order history entry: ' . $e->getMessage(), 'erpy');
        }
    }

    private function resolveOrderStatusId(ErpOrderStatus $document, FieldMap $map): ?int
    {
        $statuses = (array)$map->option('orderStatuses', []);
        $key = (string)($document->statusCode ?: $document->status);

        // An explicit mapping from the ERP's own status string wins; the booleans are the
        // fallback, because they are the part every ERP agrees on.
        if ($key !== '' && !empty($statuses[$key])) {
            return (int)$statuses[$key];
        }

        $fallbackKey = match (true) {
            $document->isCancelled => 'cancelled',
            $document->isShipped => 'shipped',
            $document->isPartiallyShipped => 'partiallyShipped',
            $document->isInvoiced => 'invoiced',
            $document->isOnHold => 'onHold',
            $document->isPicking => 'picking',
            default => null,
        };

        return $fallbackKey && !empty($statuses[$fallbackKey]) ? (int)$statuses[$fallbackKey] : null;
    }

    public function applyShipment(Connection $connection, ErpShipment $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        $order = $this->findOrder($document->orderNumber);

        if (!$order) {
            return ApplyResult::skipped(Craft::t('erpy', 'No order numbered “{number}”.', ['number' => $document->orderNumber]));
        }

        $links = Plugin::getInstance()->getLinks();

        // ERPs re-send fulfilments freely — nightly exports, webhook retries, a warehouse
        // reprinting a packing slip. The natural key is what stops the same shipment being
        // recorded, and emailed, twice.
        if ($links->find($connection, Entity::SHIPMENT, $document->naturalKey())) {
            return ApplyResult::skipped(Craft::t('erpy', 'Already recorded.'), $order->id);
        }

        if ($dryRun) {
            return ApplyResult::skipped(Craft::t('erpy', 'Would record a shipment.'));
        }

        // The link is written before the order is touched. Saving an order fires status emails
        // and every third-party handler on them; if one of those fatals, the ERP's retry must not
        // arrive as a second shipment.
        $links->record(
            connection: $connection,
            entity: Entity::SHIPMENT,
            naturalKey: $document->naturalKey(),
            localId: $order->id,
            remoteId: $document->remoteId ?? $document->shipmentNumber,
            remoteKey: $document->trackingNumber,
            contentHash: $document->contentHash(),
            quantity: $document->totalQuantity() ?: null,
            pulledAt: new DateTime(),
        );

        $this->writeHistory($order, new ErpOrderStatus([
            'orderNumber' => $document->orderNumber,
            'status' => Craft::t('erpy', 'Shipped'),
            'message' => $this->shipmentNote($document),
        ]));

        $statusId = (int)$map->option('shippedOrderStatusId');
        $changes = ['shipment' => [null, $document->trackingNumber ?? $document->shipmentNumber]];

        if ($statusId && $order->orderStatusId !== $statusId && $this->isFullyShipped($order, $connection)) {
            $order->orderStatusId = $statusId;
            $changes['orderStatus'] = ['—', $statusId];
            Craft::$app->getElements()->saveElement($order, false);
        }

        return ApplyResult::created($order->id, $changes);
    }

    private function shipmentNote(ErpShipment $document): string
    {
        $parts = array_filter([
            $document->carrier,
            $document->service,
            $document->trackingNumber,
        ]);

        return $parts ? implode(' · ', $parts) : Craft::t('erpy', 'Shipment recorded by the ERP.');
    }

    /**
     * Whether everything ordered has now shipped, counting every shipment the ERP has sent for
     * this order rather than just this one — partial fulfilment is the normal case.
     */
    private function isFullyShipped(Order $order, Connection $connection): bool
    {
        $ordered = 0.0;

        foreach ($order->getLineItems() as $item) {
            $ordered += (float)$item->qty;
        }

        if ($ordered <= 0) {
            return true;
        }

        // Every shipment recorded for this order, this one included — it was written to the
        // identity map before we got here, precisely so this sum is complete.
        $shipped = 0.0;
        $unquantified = false;

        foreach (Plugin::getInstance()->getLinks()->forLocalId($connection, Entity::SHIPMENT, $order->id) as $link) {
            if ($link->quantity === null) {
                $unquantified = true;
                continue;
            }

            $shipped += $link->quantity;
        }

        // An ERP that posts fulfilments without line detail cannot tell us how much shipped. The
        // safe reading is that the order is complete — a warehouse that says "shipped" and means
        // "partly shipped" is a data problem the merchant can see in the log, whereas an order
        // stuck in "processing" forever is one they will only hear about from the customer.
        if ($unquantified && $shipped <= 0) {
            return true;
        }

        return $shipped >= $ordered;
    }

    public function applyInvoice(Connection $connection, ErpInvoice $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        if (trim($document->invoiceNumber) === '') {
            return ApplyResult::failed(Craft::t('erpy', 'An invoice arrived with no number.'), false);
        }

        if ($dryRun) {
            return ApplyResult::skipped(Craft::t('erpy', 'Would store the invoice.'));
        }

        $order = $document->orderNumber ? $this->findOrder($document->orderNumber) : null;

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: Entity::INVOICE,
            naturalKey: $document->naturalKey(),
            localId: $order?->id,
            remoteId: $document->remoteId ?? $document->invoiceNumber,
            remoteKey: $document->orderNumber,
            contentHash: $document->contentHash(),
            pulledAt: new DateTime(),
        );

        if ($order && $document->isPaid && $map->option('markPaidOrders', false)) {
            $this->writeHistory($order, new ErpOrderStatus([
                'orderNumber' => (string)$document->orderNumber,
                'status' => Craft::t('erpy', 'Invoice {number} paid', ['number' => $document->invoiceNumber]),
            ]));
        }

        return ApplyResult::updated($order?->id ?? 0, ['invoice' => [null, $document->invoiceNumber]]);
    }

    public function applyPayment(Connection $connection, ErpPayment $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        if ($dryRun) {
            return ApplyResult::skipped(Craft::t('erpy', 'Would record a payment.'));
        }

        $order = $document->orderNumber ? $this->findOrder($document->orderNumber) : null;

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: Entity::PAYMENT,
            naturalKey: $document->naturalKey(),
            localId: $order?->id,
            remoteId: $document->remoteId,
            remoteKey: $document->reference,
            contentHash: $document->contentHash(),
            pulledAt: new DateTime(),
        );

        if ($order) {
            $this->writeHistory($order, new ErpOrderStatus([
                'orderNumber' => (string)$document->orderNumber,
                'status' => $document->isRefund
                    ? Craft::t('erpy', 'Refund recorded in the ERP')
                    : Craft::t('erpy', 'Payment recorded in the ERP'),
                'message' => number_format($document->amount, 2) . ' ' . $document->currency,
            ]));
        }

        return ApplyResult::created($order?->id ?? 0);
    }

    public function findOrder(string $number): ?Order
    {
        if (trim($number) === '') {
            return null;
        }

        return Order::find()->number($number)->status(null)->one()
            ?? Order::find()->reference($number)->status(null)->one();
    }
}
