<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\erpy\base\ApplyResult;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpStock;
use justinholtweb\erpy\models\canonical\ErpVariant;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\FieldMap;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * Turning ERP catalogue documents into Commerce records.
 *
 * The governing opinion: the ERP owns *what a thing is* and Commerce owns *how it is sold*. So a
 * product sync writes SKU, price, weight, dimensions and availability without ever touching the
 * merchandising a marketer spent a week on — unless the merchant explicitly maps it, which they
 * can, field by field.
 */
class Catalog extends Component
{
    /**
     * SKU → variant id, built once per run. A 40,000-SKU pull that looked each one up
     * individually would spend its whole night in the elements table.
     *
     * @var array<int,array<string,int>>
     */
    private array $skuIndex = [];

    // ---------------------------------------------------------------------------------------
    // Products
    // ---------------------------------------------------------------------------------------

    public function applyProduct(Connection $connection, ErpProduct $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        if (trim($document->sku) === '') {
            return ApplyResult::failed(Craft::t('erpy', 'The ERP sent an item with no SKU.'), false);
        }

        $links = Plugin::getInstance()->getLinks();

        // Unchanged records are the overwhelming majority of any delta sync, and this comparison
        // is the difference between a nightly catalogue sync taking minutes and taking hours.
        if (!$dryRun && $links->isUnchanged($connection, \justinholtweb\erpy\base\Entity::PRODUCT, $document->naturalKey(), $document->contentHash())) {
            return ApplyResult::skipped(Craft::t('erpy', 'Unchanged.'));
        }

        $variant = $this->findVariantBySku($connection, $document->sku);
        $product = $variant?->getOwner();

        if (!$product instanceof Product) {
            $product = null;
        }

        $isNew = $product === null;

        if ($isNew && !$map->option('createMissing', true)) {
            return ApplyResult::skipped(Craft::t('erpy', 'No product with this SKU, and creating products is switched off.'));
        }

        if (!$isNew && !$map->option('updateExisting', true)) {
            return ApplyResult::skipped(Craft::t('erpy', 'Product already exists, and updating is switched off.'));
        }

        $typeId = (int)$map->option('productTypeId');

        if ($isNew && !$typeId) {
            return ApplyResult::failed(Craft::t('erpy', 'Pick a product type on the mapping screen before pulling products.'), false);
        }

        if ($dryRun) {
            return $isNew
                ? new ApplyResult(action: \justinholtweb\erpy\models\RunItem::ACTION_CREATED, changes: ['sku' => [null, $document->sku]])
                : new ApplyResult(action: \justinholtweb\erpy\models\RunItem::ACTION_UPDATED, localId: $product->id, changes: $this->previewChanges($product, $document, $map));
        }

        try {
            $changes = $this->populateProduct($connection, $product, $document, $map, $typeId, $isNew);
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }

        /** @var Product $product */
        $product = $changes['product'];
        unset($changes['product']);

        if (!Craft::$app->getElements()->saveElement($product)) {
            return ApplyResult::failed($this->errorSummary($product), false);
        }

        $this->rememberSku($connection, $document->sku, $product);

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: \justinholtweb\erpy\base\Entity::PRODUCT,
            naturalKey: $document->naturalKey(),
            localId: $product->id,
            localUid: $product->uid,
            remoteId: $document->remoteId,
            remoteKey: $document->remoteKey ?? $document->sku,
            contentHash: $document->contentHash(),
            pulledAt: new DateTime(),
        );

        return $isNew
            ? ApplyResult::created($product->id, $changes)
            : ApplyResult::updated($product->id, $changes);
    }

    /**
     * @return array{product:Product}&array<string,array{0:mixed,1:mixed}>
     */
    private function populateProduct(Connection $connection, ?Product $product, ErpProduct $document, FieldMap $map, int $typeId, bool $isNew): array
    {
        $changes = [];

        if ($isNew) {
            $product = new Product();
            $product->typeId = $typeId;
            $product->siteId = $this->siteId($connection);
            $product->title = $document->name !== '' ? $document->name : $document->sku;
            $product->enabled = $this->shouldBeEnabled($document, $map);
        } else {
            // A product that already exists keeps its title unless the merchant asked otherwise:
            // ERP item descriptions are written by whoever set up the item master, and they are
            // very rarely what should appear on a storefront.
            if ($map->option('updateTitle', false) && $document->name !== '' && $product->title !== $document->name) {
                $changes['title'] = [$product->title, $document->name];
                $product->title = $document->name;
            }

            if ($map->option('updateStatus', true)) {
                $enabled = $this->shouldBeEnabled($document, $map);

                if ($product->enabled !== $enabled) {
                    $changes['enabled'] = [$product->enabled, $enabled];
                    $product->enabled = $enabled;
                }
            }
        }

        $variants = $this->buildVariants($product, $document, $map, $changes, $isNew);
        $product->setVariants($variants);

        foreach (Plugin::getInstance()->getMapping()->apply($map, $document, ['connection' => $connection]) as $handle => $value) {
            $this->setMappedValue($product, $handle, $value, $changes);
        }

        $changes['product'] = $product;

        return $changes;
    }

    /**
     * @return Variant[]
     */
    private function buildVariants(Product $product, ErpProduct $document, FieldMap $map, array &$changes, bool $isNew): array
    {
        $existing = [];

        if (!$isNew) {
            foreach ($product->getVariants(true) as $variant) {
                $existing[(string)$variant->sku] = $variant;
            }
        }

        // An ERP that models variants sends them; one that does not sends a product per SKU, and
        // Commerce is happy to call that a single-variant product. Both land here as a list.
        $incoming = $document->hasVariants()
            ? $document->variants
            : [new ErpVariant([
                'sku' => $document->sku,
                'name' => $document->name,
                'enabled' => $document->enabled && !$document->blocked,
                'price' => $document->price,
                'weight' => $document->weight,
                'barcode' => $document->barcode,
                'remoteId' => $document->remoteId,
            ])];

        $variants = [];
        $isFirst = true;

        foreach ($incoming as $erpVariant) {
            $variant = $existing[$erpVariant->sku] ?? new Variant();
            $wasNew = !$variant->id;

            $variant->sku = $erpVariant->sku;
            $variant->enabled = $erpVariant->enabled;

            // Set on creation only. The ERP knows whether an item is stocked or is a service
            // line; after that it is the merchant's call, and overwriting it every sync would
            // undo a deliberate change every night.
            if ($wasNew) {
                $variant->inventoryTracked = $document->tracksInventory;
            }

            if ($variant->title === null || $variant->title === '' || $map->option('updateTitle', false)) {
                $variant->title = $erpVariant->name !== '' ? $erpVariant->name : $erpVariant->sku;
            }

            if ($erpVariant->price !== null && $map->option('updatePrice', true)) {
                $before = $variant->basePrice;

                if ($before === null || abs((float)$before - $erpVariant->price) > 0.0001) {
                    $changes['price.' . $erpVariant->sku] = [$before, $erpVariant->price];
                }

                $variant->setBasePrice($erpVariant->price);
            }

            if ($map->option('updateDimensions', true)) {
                $variant->weight = $erpVariant->weight ?? $document->weight ?? $variant->weight;
                $variant->length = $document->length ?? $variant->length;
                $variant->width = $document->width ?? $variant->width;
                $variant->height = $document->height ?? $variant->height;
            }

            // Commerce needs exactly one default variant, and a product whose default was deleted
            // upstream would otherwise save without one.
            if ($isFirst) {
                $variant->isDefault = true;
                $isFirst = false;
            }

            if ($wasNew && !$isNew) {
                $changes['variant.' . $erpVariant->sku] = [null, 'added'];
            }

            $variants[] = $variant;
            unset($existing[$erpVariant->sku]);
        }

        // Variants the ERP no longer sends are disabled rather than deleted. Deleting one takes
        // its order history's purchasable with it, and no merchant has ever wanted that.
        if ($map->option('disableRemovedVariants', true)) {
            foreach ($existing as $sku => $variant) {
                if ($variant->enabled) {
                    $changes['variant.' . $sku] = ['enabled', 'disabled'];
                    $variant->enabled = false;
                }

                $variants[] = $variant;
            }
        } else {
            foreach ($existing as $variant) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    private function shouldBeEnabled(ErpProduct $document, FieldMap $map): bool
    {
        if (!$map->option('respectErpStatus', true)) {
            return true;
        }

        return $document->enabled && !$document->blocked;
    }

    private function setMappedValue(Product $product, string $handle, mixed $value, array &$changes): void
    {
        // A mapping target of `title` or `slug` is an element attribute; anything else is a
        // custom field, and asking for a field that does not exist should say so rather than
        // silently doing nothing.
        if (in_array($handle, ['title', 'slug', 'postDate', 'expiryDate'], true)) {
            $before = $product->$handle ?? null;

            if ($before != $value) {
                $changes[$handle] = [$before, $value];
                $product->$handle = $value;
            }

            return;
        }

        $layout = $product->getFieldLayout();

        if (!$layout || !$layout->getFieldByHandle($handle)) {
            $changes['!' . $handle] = [null, 'no such field on this product type'];

            return;
        }

        $before = $product->getFieldValue($handle);

        if ($before != $value) {
            $changes[$handle] = [is_scalar($before) ? $before : '…', is_scalar($value) ? $value : '…'];
            $product->setFieldValue($handle, $value);
        }
    }

    private function previewChanges(Product $product, ErpProduct $document, FieldMap $map): array
    {
        $changes = [];

        if ($document->name !== '' && $product->title !== $document->name && $map->option('updateTitle', false)) {
            $changes['title'] = [$product->title, $document->name];
        }

        $variant = $product->getDefaultVariant();

        if ($variant && $document->price !== null && abs((float)$variant->basePrice - $document->price) > 0.0001) {
            $changes['price'] = [$variant->basePrice, $document->price];
        }

        return $changes;
    }

    // ---------------------------------------------------------------------------------------
    // Prices
    // ---------------------------------------------------------------------------------------

    /**
     * Store one ERP price line.
     *
     * Base prices — no customer, no group, no list — are written straight onto the variant, which
     * is what a B2C store wants and all it ever needs. Everything else is contract pricing and
     * lands in Erpy's own table, to be resolved for one customer and a handful of SKUs at cart
     * time rather than pre-computed across the whole catalogue.
     */
    public function applyPrice(Connection $connection, ErpPrice $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        if (trim($document->sku) === '') {
            return ApplyResult::failed(Craft::t('erpy', 'A price line arrived with no SKU.'), false);
        }

        $isBasePrice = $this->isVariantBasePrice($document, $map);

        if ($dryRun) {
            return ApplyResult::skipped($isBasePrice
                ? Craft::t('erpy', 'Would set the base price.')
                : Craft::t('erpy', 'Would store a contract price.'));
        }

        if ($isBasePrice && $map->option('writeBasePriceToVariant', true)) {
            return $this->applyBasePrice($connection, $document);
        }

        return $this->storeContractPrice($connection, $document);
    }

    /**
     * Whether a price line is *the* catalogue price, and so belongs on the variant.
     *
     * Being for everybody is not enough. A base-audience line with a minimum quantity above one
     * is a quantity break, and writing it to the variant would hand the "buy 50" price to a
     * customer buying one. A line with an end date, or one that has not started, is a
     * promotion: written to the variant it would outlive its window, because a delta sync never
     * re-sends the unchanged regular price that should replace it. Both stay in `erpy_prices`,
     * where Pricing resolves them at cart time — breaks by quantity, promotions by date.
     */
    private function isVariantBasePrice(ErpPrice $document, FieldMap $map): bool
    {
        $forEverybody = $document->customerCode === null
            && $document->customerGroupCode === null
            && ($document->priceListCode === null || $document->priceListCode === $map->option('basePriceListCode'));

        if (!$forEverybody || $document->minQuantity > 1) {
            return false;
        }

        return $document->endsAt === null
            && ($document->startsAt === null || $document->startsAt <= new DateTime());
    }

    private function applyBasePrice(Connection $connection, ErpPrice $document): ApplyResult
    {
        $variant = $this->findVariantBySku($connection, $document->sku);

        if (!$variant) {
            return ApplyResult::skipped(Craft::t('erpy', 'No variant with the SKU “{sku}”.', ['sku' => $document->sku]));
        }

        if (abs((float)$variant->basePrice - $document->unitPrice) < 0.0001) {
            return ApplyResult::skipped(Craft::t('erpy', 'Unchanged.'), $variant->id);
        }

        $before = $variant->basePrice;
        $variant->setBasePrice($document->unitPrice);

        if (!Craft::$app->getElements()->saveElement($variant)) {
            return ApplyResult::failed($this->errorSummary($variant), false);
        }

        return ApplyResult::updated($variant->id, ['price' => [$before, $document->unitPrice]]);
    }

    private function storeContractPrice(Connection $connection, ErpPrice $document): ApplyResult
    {
        $variant = $this->findVariantBySku($connection, $document->sku);
        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'sku' => $document->sku,
            'purchasableId' => $variant?->id,
            'priceListCode' => $document->priceListCode,
            'customerCode' => $document->customerCode,
            'customerGroupCode' => $document->customerGroupCode,
            'currency' => $document->currency,
            'unitPrice' => $document->unitPrice,
            'discountPercent' => $document->discountPercent,
            'minQuantity' => $document->minQuantity,
            'unitOfMeasure' => $document->unitOfMeasure,
            'priceIncludesTax' => $document->priceIncludesTax,
            'startsAt' => $document->startsAt ? Db::prepareDateForDb($document->startsAt) : null,
            'endsAt' => $document->endsAt ? Db::prepareDateForDb($document->endsAt) : null,
            'dateUpdated' => $now,
        ];

        $existing = (new Query())
            ->select(['id', 'unitPrice'])
            ->from(Table::PRICES)
            ->where(['connectionId' => $connection->id, 'naturalKey' => $document->naturalKey()])
            ->one();

        Craft::$app->getDb()->createCommand()->upsert(Table::PRICES, array_merge([
            'connectionId' => $connection->id,
            'naturalKey' => $document->naturalKey(),
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ], $values), $values)->execute();

        if (!$existing) {
            return ApplyResult::created($variant?->id ?? 0);
        }

        if (abs((float)$existing['unitPrice'] - $document->unitPrice) < 0.0001) {
            return ApplyResult::skipped(Craft::t('erpy', 'Unchanged.'), $variant?->id);
        }

        return ApplyResult::updated($variant?->id ?? 0, ['price' => [$existing['unitPrice'], $document->unitPrice]]);
    }

    /**
     * Remove contract prices this connection no longer sends.
     *
     * Run at the end of a full price sync only. A delta sync has no idea what it did not see, and
     * deleting on that basis would wipe a customer's negotiated pricing the first time an ERP
     * answered a modified-since query with an empty page.
     */
    public function pruneContractPrices(Connection $connection, DateTime $syncedBefore): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::PRICES, [
            'and',
            ['connectionId' => $connection->id],
            ['<', 'dateUpdated', Db::prepareDateForDb($syncedBefore)],
        ])->execute();
    }

    // ---------------------------------------------------------------------------------------
    // Inventory
    // ---------------------------------------------------------------------------------------

    public function applyStock(Connection $connection, ErpStock $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        $variant = $this->findVariantBySku($connection, $document->sku);

        if (!$variant) {
            return ApplyResult::skipped(Craft::t('erpy', 'No variant with the SKU “{sku}”.', ['sku' => $document->sku]));
        }

        if (!$variant->inventoryTracked) {
            return ApplyResult::skipped(Craft::t('erpy', 'Inventory is not tracked for this variant.'), $variant->id);
        }

        $quantity = (int)floor($document->sellable() - (float)$map->option('stockBuffer', 0));
        $quantity = max(0, $quantity);

        if ($dryRun) {
            return ApplyResult::skipped(Craft::t('erpy', 'Would set stock to {qty}.', ['qty' => $quantity]));
        }

        $location = $this->resolveInventoryLocation($document, $map);
        $current = $this->currentStock($variant, $location?->id);

        if ($current === $quantity) {
            return ApplyResult::skipped(Craft::t('erpy', 'Unchanged.'), $variant->id);
        }

        try {
            $attributes = ['note' => Craft::t('erpy', 'Set by Erpy from {connection}.', ['connection' => $connection->name])];

            if ($location) {
                $attributes['inventoryLocationId'] = $location->id;
            }

            Commerce::getInstance()->getInventory()->updatePurchasableInventoryLevel($variant, $quantity, $attributes);
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: \justinholtweb\erpy\base\Entity::INVENTORY,
            naturalKey: $document->naturalKey(),
            localId: $variant->id,
            remoteId: $document->remoteId,
            contentHash: $document->contentHash(),
            pulledAt: new DateTime(),
        );

        return ApplyResult::updated($variant->id, ['stock' => [$current, $quantity]]);
    }

    private function resolveInventoryLocation(ErpStock $document, FieldMap $map): ?object
    {
        $locations = Commerce::getInstance()->getInventoryLocations();

        // The merchant's warehouse map wins, then a location whose handle matches the ERP's code,
        // then nothing — which lets Commerce fall back to the store's first location.
        $mapped = (array)$map->option('warehouses', []);
        $handle = $mapped[(string)$document->warehouse] ?? $document->warehouse;

        if (!$handle) {
            return null;
        }

        if (is_numeric($handle)) {
            return $locations->getInventoryLocationById((int)$handle);
        }

        return $locations->getInventoryLocationByHandle((string)$handle);
    }

    private function currentStock(Variant $variant, ?int $locationId): ?int
    {
        try {
            $levels = Commerce::getInstance()->getInventory()->getInventoryLevelsForPurchasable($variant);

            foreach ($levels as $level) {
                if ($locationId === null || (int)$level->inventoryLocationId === $locationId) {
                    return (int)$level->availableTotal;
                }
            }
        } catch (Throwable) {
            // A purchasable with no inventory item yet has no level to read, which is not an
            // error — the update below will create one.
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------
    // Lookups
    // ---------------------------------------------------------------------------------------

    /**
     * Find a variant by SKU, using a per-run index.
     */
    public function findVariantBySku(Connection $connection, string $sku): ?Variant
    {
        $storeId = $connection->getStoreId();
        $id = $this->skuIndex[$storeId][$sku] ?? null;

        if ($id !== null) {
            $variant = Variant::find()->id($id)->status(null)->one();

            if ($variant) {
                return $variant;
            }
        }

        $variant = Variant::find()
            ->sku($sku)
            ->status(null)
            ->siteId($this->siteId($connection))
            ->one();

        if ($variant) {
            $this->skuIndex[$storeId][$sku] = $variant->id;
        }

        return $variant;
    }

    private function rememberSku(Connection $connection, string $sku, Product $product): void
    {
        $variant = $product->getDefaultVariant();

        if ($variant) {
            $this->skuIndex[$connection->getStoreId()][$sku] = $variant->id;
        }
    }

    private function siteId(Connection $connection): int
    {
        $store = Commerce::getInstance()->getStores()->getStoreById($connection->getStoreId());
        $siteIds = $store?->getSites()->map(fn($site) => $site->id)->all() ?? [];

        return (int)($siteIds[0] ?? Craft::$app->getSites()->getPrimarySite()->id);
    }

    private function errorSummary(\craft\base\ElementInterface $element): string
    {
        $messages = [];

        foreach ($element->getErrors() as $attribute => $errors) {
            $messages[] = $attribute . ': ' . implode(' ', $errors);
        }

        return $messages ? implode('; ', $messages) : Craft::t('erpy', 'Craft refused to save it and said nothing about why.');
    }

    public function resetIndex(): void
    {
        $this->skuIndex = [];
    }
}
