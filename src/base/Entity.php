<?php

namespace justinholtweb\erpy\base;

use Craft;

/**
 * The canonical entity vocabulary.
 *
 * Every connector, mapping, run, link row and log line names one of these. ERPs call them
 * fifteen different things — Items, Stock Items, Inventory Parts, Articles, Products — but Erpy
 * only ever knows these ten, and a connector's job is to translate at the edge.
 */
abstract class Entity
{
    /** Sellable goods and their variants (ERP → Commerce). */
    public const PRODUCT = 'product';

    /** Price lists, tier/volume breaks and customer-specific pricing (ERP → Commerce). */
    public const PRICE = 'price';

    /** On-hand quantities, per warehouse/location (ERP → Commerce). */
    public const INVENTORY = 'inventory';

    /** Business partners / debtors / accounts, and the users attached to them (both ways). */
    public const CUSTOMER = 'customer';

    /** Sales orders (Commerce → ERP). */
    public const ORDER = 'order';

    /** Order state changes coming back from the ERP (ERP → Commerce). */
    public const ORDER_STATUS = 'orderStatus';

    /** Fulfilments, packing slips and tracking numbers (ERP → Commerce). */
    public const SHIPMENT = 'shipment';

    /** Posted sales invoices and credit notes (ERP → Commerce). */
    public const INVOICE = 'invoice';

    /** Payments and settlements (both ways). */
    public const PAYMENT = 'payment';

    /** Credit limit, balance and open-item status for a customer (ERP → Commerce). */
    public const CREDIT = 'credit';

    /**
     * Every entity, in the order they should be presented and — not by accident — the order they
     * must be synced in. Products before prices before inventory; customers before orders.
     */
    public static function all(): array
    {
        return [
            self::CUSTOMER,
            self::PRODUCT,
            self::PRICE,
            self::INVENTORY,
            self::ORDER,
            self::ORDER_STATUS,
            self::SHIPMENT,
            self::INVOICE,
            self::PAYMENT,
            self::CREDIT,
        ];
    }

    /**
     * Dependency order for a full sync. A connection that syncs everything runs them in this
     * sequence so an order never lands before the customer it belongs to.
     */
    public static function syncOrder(): array
    {
        return self::all();
    }

    public static function exists(string $entity): bool
    {
        return in_array($entity, self::all(), true);
    }

    public static function displayName(string $entity): string
    {
        return match ($entity) {
            self::PRODUCT => Craft::t('erpy', 'Products'),
            self::PRICE => Craft::t('erpy', 'Prices'),
            self::INVENTORY => Craft::t('erpy', 'Inventory'),
            self::CUSTOMER => Craft::t('erpy', 'Customers'),
            self::ORDER => Craft::t('erpy', 'Orders'),
            self::ORDER_STATUS => Craft::t('erpy', 'Order status'),
            self::SHIPMENT => Craft::t('erpy', 'Shipments'),
            self::INVOICE => Craft::t('erpy', 'Invoices'),
            self::PAYMENT => Craft::t('erpy', 'Payments'),
            self::CREDIT => Craft::t('erpy', 'Credit'),
            default => $entity,
        };
    }

    /**
     * The canonical DTO class each entity carries.
     */
    public static function documentClass(string $entity): string
    {
        return match ($entity) {
            self::PRODUCT => \justinholtweb\erpy\models\canonical\ErpProduct::class,
            self::PRICE => \justinholtweb\erpy\models\canonical\ErpPrice::class,
            self::INVENTORY => \justinholtweb\erpy\models\canonical\ErpStock::class,
            self::CUSTOMER => \justinholtweb\erpy\models\canonical\ErpCustomer::class,
            self::ORDER => \justinholtweb\erpy\models\canonical\ErpOrder::class,
            self::ORDER_STATUS => \justinholtweb\erpy\models\canonical\ErpOrderStatus::class,
            self::SHIPMENT => \justinholtweb\erpy\models\canonical\ErpShipment::class,
            self::INVOICE => \justinholtweb\erpy\models\canonical\ErpInvoice::class,
            self::PAYMENT => \justinholtweb\erpy\models\canonical\ErpPayment::class,
            self::CREDIT => \justinholtweb\erpy\models\canonical\ErpCredit::class,
            default => throw new \InvalidArgumentException("Unknown entity \"$entity\"."),
        };
    }
}
