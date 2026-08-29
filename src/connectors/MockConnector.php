<?php

namespace justinholtweb\erpy\connectors;

use Craft;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * A pretend ERP that ships with the gateway.
 *
 * It exists for three reasons, in ascending order of importance. It lets a merchant click through
 * the whole plugin before buying an ERP licence. It gives support a way to prove a problem is in
 * the connector rather than in the engine. And it is what Erpy's own test suite runs against, so
 * the engine is exercised end to end without a single network call.
 *
 * Everything it returns is deterministic from a seed, and everything pushed to it is kept in
 * memory where a test can assert on it.
 */
class MockConnector extends Connector
{
    /** @var array<string,array<int,object>> what has been pushed here, for tests to inspect */
    private static array $received = [];

    /** @var array<string,string> naturalKey => remote id, so a repeat push is detected */
    private static array $known = [];

    public static function handle(): string
    {
        return 'mock';
    }

    public static function displayName(): string
    {
        return 'Mock ERP';
    }

    public static function vendor(): string
    {
        return 'Erpy';
    }

    public static function description(): string
    {
        return 'A pretend ERP with deterministic data. Use it to try the mapping and sync screens, or to prove a problem is in the connector rather than in Erpy.';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::BOTH, delta: true, pageSize: 50)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 50)
            ->supports(Entity::PRICE, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::ORDER, Direction::PUSH, batch: false)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 50)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: 50)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 50)
            ->supports(Entity::PAYMENT, Direction::BOTH, pageSize: 50)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 50)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::number('itemCount', Craft::t('erpy', 'Items to invent'), [
                'instructions' => Craft::t('erpy', 'How many products, prices and stock lines this pretend ERP holds.'),
                'default' => 25,
                'min' => 0,
                'max' => 5000,
            ]),
            Field::text('skuPrefix', Craft::t('erpy', 'SKU prefix'), [
                'default' => 'MOCK-',
            ]),
            Field::number('failEvery', Craft::t('erpy', 'Fail every Nth record'), [
                'instructions' => Craft::t('erpy', 'For trying out the dead-letter screen. Zero never fails.'),
                'default' => 0,
                'min' => 0,
            ]),
        ];
    }

    protected function probe(): HealthResult
    {
        return HealthResult::pass(
            Craft::t('erpy', 'The mock ERP is always available.'),
            [
                Craft::t('erpy', 'Items') => (string)$this->itemCount(),
                Craft::t('erpy', 'Documents received') => (string)array_sum(array_map('count', self::$received)),
            ],
        );
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->paginate($criteria, function(int $index): ErpProduct {
            $sku = $this->sku($index);

            return new ErpProduct([
                'sku' => $sku,
                'name' => 'Mock item ' . $index,
                'description' => 'Invented by the Erpy mock connector.',
                'enabled' => $index % 11 !== 0,
                'blocked' => $index % 23 === 0,
                'category' => 'GROUP-' . (($index % 4) + 1),
                'unitOfMeasure' => 'EACH',
                'price' => round(5 + ($index % 40) * 1.75, 2),
                'currency' => 'USD',
                'cost' => round(2 + ($index % 40) * 0.9, 2),
                'weight' => round(0.2 + ($index % 10) * 0.15, 3),
                'weightUnit' => 'kg',
                'barcode' => str_pad((string)(5000000000000 + $index), 13, '0', STR_PAD_LEFT),
                'remoteId' => 'ITEM' . $index,
                'remoteKey' => $sku,
                'modifiedAt' => new \DateTime('-' . ($index % 30) . ' days'),
                'raw' => ['No' => $sku, 'Item_Category_Code' => 'GROUP-' . (($index % 4) + 1)],
            ]);
        });
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        return $this->paginate($criteria, function(int $index): ErpPrice {
            // A third of the lines are contract pricing, so the resolver's precedence rules get
            // exercised rather than just the base-price path.
            $isContract = $index % 3 === 0;

            return new ErpPrice([
                'sku' => $this->sku($index),
                'priceListCode' => $isContract ? 'TRADE' : null,
                'customerGroupCode' => $isContract && $index % 6 === 0 ? 'WHOLESALE' : null,
                'currency' => 'USD',
                'unitPrice' => round((5 + ($index % 40) * 1.75) * ($isContract ? 0.85 : 1), 2),
                'minQuantity' => $isContract && $index % 9 === 0 ? 10 : 1,
                'unitOfMeasure' => 'EACH',
                'remoteId' => 'PRICE' . $index,
                'modifiedAt' => new \DateTime('-' . ($index % 14) . ' days'),
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        return $this->paginate($criteria, function(int $index): ErpStock {
            return new ErpStock([
                'sku' => $this->sku($index),
                'warehouse' => 'MAIN',
                'onHand' => (float)(($index * 7) % 120),
                'allocated' => (float)($index % 5),
                'incoming' => (float)($index % 3) * 10,
                'remoteId' => 'STOCK' . $index,
                'modifiedAt' => new \DateTime('-' . ($index % 3) . ' days'),
            ]);
        });
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->paginate($criteria, function(int $index): ErpCustomer {
            $code = 'CUST' . str_pad((string)$index, 5, '0', STR_PAD_LEFT);

            return new ErpCustomer([
                'code' => $code,
                'name' => 'Mock Customer ' . $index,
                'email' => 'customer' . $index . '@example.test',
                'currency' => 'USD',
                'priceListCode' => $index % 2 === 0 ? 'TRADE' : null,
                'customerGroupCode' => $index % 6 === 0 ? 'WHOLESALE' : null,
                'paymentTermsCode' => 'NET30',
                'creditLimit' => 5000.0 + ($index % 10) * 1000,
                'balance' => (float)(($index * 37) % 4000),
                'onHold' => $index % 17 === 0,
                'remoteId' => 'BP' . $index,
                'addresses' => [
                    new ErpAddress([
                        'type' => ErpAddress::TYPE_BILLING,
                        'fullName' => 'Mock Customer ' . $index,
                        'addressLine1' => $index . ' Example Street',
                        'locality' => 'Charlotte',
                        'administrativeArea' => 'NC',
                        'postalCode' => '28202',
                        'countryCode' => 'US',
                        'isDefault' => true,
                    ]),
                ],
                'modifiedAt' => new \DateTime('-' . ($index % 20) . ' days'),
            ]);
        }, max: 20);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        // Nothing to invent: statuses only exist for orders this mock has actually received.
        $items = [];

        foreach (self::$received[Entity::ORDER] ?? [] as $order) {
            $items[] = new ErpOrderStatus([
                'orderNumber' => $order->orderNumber,
                'status' => 'Released',
                'statusCode' => 'RELEASED',
                'isPicking' => true,
                'remoteId' => self::$known[$order->orderNumber] ?? null,
                'modifiedAt' => new \DateTime(),
            ]);
        }

        return new Page($items);
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        $items = [];

        foreach (self::$received[Entity::ORDER] ?? [] as $index => $order) {
            $items[] = new ErpShipment([
                'orderNumber' => $order->orderNumber,
                'shipmentNumber' => 'SHIP' . ($index + 1),
                'trackingNumber' => '1Z999AA1' . str_pad((string)$index, 10, '0', STR_PAD_LEFT),
                'carrier' => 'UPS',
                'service' => 'Ground',
                'shippedAt' => new \DateTime(),
                'lines' => array_map(
                    static fn($line) => ['sku' => $line->sku, 'quantity' => $line->quantity],
                    $order->lines,
                ),
            ]);
        }

        return new Page($items);
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        $items = [];

        foreach (self::$received[Entity::ORDER] ?? [] as $index => $order) {
            $items[] = new ErpInvoice([
                'invoiceNumber' => 'INV' . str_pad((string)($index + 1), 6, '0', STR_PAD_LEFT),
                'orderNumber' => $order->orderNumber,
                'customerCode' => $order->customerCode,
                'issuedAt' => new \DateTime(),
                'currency' => $order->currency,
                'total' => $order->total,
                'balance' => $order->total,
                'status' => 'Open',
            ]);
        }

        return new Page($items);
    }

    protected function fetchPayments(FetchCriteria $criteria): Page
    {
        $items = [];

        foreach (self::$received[Entity::ORDER] ?? [] as $index => $order) {
            $items[] = new \justinholtweb\erpy\models\canonical\ErpPayment([
                'reference' => 'PAY' . str_pad((string)($index + 1), 6, '0', STR_PAD_LEFT),
                'orderNumber' => $order->orderNumber,
                'customerCode' => $order->customerCode,
                'amount' => $order->total,
                'currency' => $order->currency,
                'methodCode' => 'BANK',
                'paidAt' => new \DateTime(),
            ]);
        }

        return new Page($items);
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->paginate($criteria, function(int $index): ErpCredit {
            return new ErpCredit([
                'customerCode' => 'CUST' . str_pad((string)$index, 5, '0', STR_PAD_LEFT),
                'currency' => 'USD',
                'creditLimit' => 5000.0 + ($index % 10) * 1000,
                'balance' => (float)(($index * 37) % 4000),
                'openOrders' => (float)(($index * 11) % 500),
                'overdueAmount' => $index % 8 === 0 ? 250.0 : 0.0,
                'onHold' => $index % 17 === 0,
                'paymentTermsCode' => 'NET30',
            ]);
        }, max: 20);
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(object $document, ?string $remoteId = null): PushResult
    {
        return $this->accept(Entity::ORDER, $document, $remoteId, 'SO');
    }

    protected function pushCustomer(object $document, ?string $remoteId = null): PushResult
    {
        return $this->accept(Entity::CUSTOMER, $document, $remoteId, 'BP');
    }

    protected function pushPayment(object $document, ?string $remoteId = null): PushResult
    {
        return $this->accept(Entity::PAYMENT, $document, $remoteId, 'PAY');
    }

    private function accept(string $entity, object $document, ?string $remoteId, string $prefix): PushResult
    {
        $key = $document->naturalKey();

        if ($remoteId === null && isset(self::$known[$key])) {
            return PushResult::alreadyExists(self::$known[$key], $key);
        }

        $failEvery = (int)$this->setting('failEvery', 0);

        if ($failEvery > 0 && (count(self::$received[$entity] ?? []) + 1) % $failEvery === 0) {
            return PushResult::rejected(Craft::t('erpy', 'The mock ERP was told to reject every {n}th document.', ['n' => $failEvery]));
        }

        self::$received[$entity][] = $document;
        $id = $remoteId ?? ($prefix . str_pad((string)count(self::$received[$entity]), 6, '0', STR_PAD_LEFT));
        self::$known[$key] = $id;

        return PushResult::ok($id, $key, ['echo' => $document->toArray()]);
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    /**
     * Page through invented records, honouring the cursor exactly the way a real ERP would — so
     * the engine's paging is genuinely exercised rather than short-circuited.
     */
    private function paginate(FetchCriteria $criteria, callable $make, ?int $max = null): Page
    {
        $total = $max ?? $this->itemCount();
        $offset = (int)($criteria->cursor ?? 0);
        $limit = max(1, $criteria->limit);
        $items = [];

        for ($index = $offset; $index < min($offset + $limit, $total); $index++) {
            $document = $make($index + 1);

            if ($criteria->ids !== [] && !in_array((string)$document->remoteId, $criteria->ids, true)) {
                continue;
            }

            // A delta request only returns what changed, which is what makes the watermark tests
            // meaningful rather than decorative.
            if ($criteria->since !== null && $document->modifiedAt !== null && $document->modifiedAt < $criteria->since) {
                continue;
            }

            $items[] = $document;
        }

        $next = $offset + $limit;

        return new Page($items, $next < $total ? (string)$next : null, $total);
    }

    private function itemCount(): int
    {
        return max(0, (int)$this->setting('itemCount', 25));
    }

    private function sku(int $index): string
    {
        return $this->setting('skuPrefix', 'MOCK-') . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------------------------------------
    // Test helpers
    // ---------------------------------------------------------------------------------------

    /**
     * @return object[]
     */
    public static function received(string $entity): array
    {
        return self::$received[$entity] ?? [];
    }

    public static function forget(): void
    {
        self::$received = [];
        self::$known = [];
    }
}
