<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\commerce\base\Purchasable;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Account;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;

/**
 * Contract pricing, resolved at cart time.
 *
 * The precedence rule is the one every ERP uses and every merchant expects, so it is worth
 * stating plainly: the most *specific* audience wins, not the cheapest price. A price negotiated
 * with this customer beats their group's, which beats their price list's, which beats the base
 * price — even when the specific one is higher. Within one audience, the largest quantity break
 * the cart qualifies for wins.
 *
 * Getting this backwards (cheapest-wins) is a classic B2B bug: it silently hands every customer
 * the best price in the system.
 */
class Pricing extends Component
{
    private const SPECIFICITY_CUSTOMER = 3;
    private const SPECIFICITY_GROUP = 2;
    private const SPECIFICITY_LIST = 1;

    /** @var array<string,array|null> */
    private array $memo = [];

    /**
     * The price this user should pay for this purchasable at this quantity, or null if the ERP
     * has nothing to say and Commerce's own price should stand.
     */
    public function priceFor(Purchasable $purchasable, float $quantity = 1, ?User $user = null, ?Connection $connection = null): ?float
    {
        $sku = (string)$purchasable->getSku();

        if ($sku === '') {
            return null;
        }

        foreach ($this->connectionsFor($connection) as $candidate) {
            $account = Plugin::getInstance()->getAccounts()->forUser($user, $candidate);
            $row = $this->bestRow($candidate, $sku, $quantity, $account);

            if ($row !== null) {
                return $this->rowPrice($row, $purchasable);
            }
        }

        return null;
    }

    /**
     * Every price break the ERP holds for this purchasable and this user, cheapest quantity
     * first — what a B2B storefront renders as a "buy 10 and save" table.
     *
     * @return array<int,array{minQuantity:float,unitPrice:float,currency:?string,priceListCode:?string}>
     */
    public function breaksFor(Purchasable $purchasable, ?User $user = null, ?Connection $connection = null): array
    {
        $sku = (string)$purchasable->getSku();

        if ($sku === '') {
            return [];
        }

        foreach ($this->connectionsFor($connection) as $candidate) {
            $account = Plugin::getInstance()->getAccounts()->forUser($user, $candidate);
            $rows = $this->applicableRows($candidate, $sku, $account);

            if ($rows === []) {
                continue;
            }

            // Only the winning audience's breaks are shown. Mixing a customer's negotiated tiers
            // with their price list's would advertise a quantity break that will not be honoured.
            $topSpecificity = max(array_map(fn(array $row) => $this->specificity($row, $account), $rows));
            $breaks = [];

            foreach ($rows as $row) {
                if ($this->specificity($row, $account) !== $topSpecificity) {
                    continue;
                }

                $breaks[] = [
                    'minQuantity' => (float)$row['minQuantity'],
                    'unitPrice' => $this->rowPrice($row, $purchasable),
                    'currency' => $row['currency'],
                    'priceListCode' => $row['priceListCode'],
                ];
            }

            usort($breaks, static fn(array $a, array $b) => $a['minQuantity'] <=> $b['minQuantity']);

            return $breaks;
        }

        return [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function bestRow(Connection $connection, string $sku, float $quantity, ?Account $account): ?array
    {
        $best = null;
        $bestSpecificity = 0;
        $bestMinQuantity = -1.0;

        foreach ($this->applicableRows($connection, $sku, $account) as $row) {
            if ((float)$row['minQuantity'] > $quantity) {
                continue;
            }

            $specificity = $this->specificity($row, $account);

            if ($specificity === 0) {
                continue;
            }

            if ($specificity < $bestSpecificity) {
                continue;
            }

            // A more specific audience always wins outright; within one audience, the largest
            // quantity break the cart qualifies for wins.
            if ($specificity > $bestSpecificity || (float)$row['minQuantity'] > $bestMinQuantity) {
                $best = $row;
                $bestSpecificity = $specificity;
                $bestMinQuantity = (float)$row['minQuantity'];
            }
        }

        return $best;
    }

    /**
     * Which audience a row is for, from this account's point of view. Zero means the row is for
     * somebody else and must be ignored.
     */
    private function specificity(array $row, ?Account $account): int
    {
        if (!empty($row['customerCode'])) {
            return $account && $row['customerCode'] === $account->customerCode ? self::SPECIFICITY_CUSTOMER : 0;
        }

        if (!empty($row['customerGroupCode'])) {
            return $account && $row['customerGroupCode'] === $account->customerGroupCode ? self::SPECIFICITY_GROUP : 0;
        }

        if (!empty($row['priceListCode'])) {
            return $account && $row['priceListCode'] === $account->priceListCode ? self::SPECIFICITY_LIST : 0;
        }

        // A row with no audience at all is a base price; it is written onto the variant instead,
        // so reaching here means the merchant switched that off and wants it resolved live.
        return self::SPECIFICITY_LIST;
    }

    /**
     * Rows for this SKU that are in date, memoized per request — a cart page asks about the same
     * SKU once per line item and once per "you might also like" tile.
     */
    private function applicableRows(Connection $connection, string $sku, ?Account $account): array
    {
        $key = $connection->id . '|' . $sku . '|' . ($account?->customerCode ?? '');

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $now = Db::prepareDateForDb(new DateTime());

        $rows = (new Query())
            ->from(Table::PRICES)
            ->where(['connectionId' => $connection->id, 'sku' => $sku])
            ->andWhere(['or', ['startsAt' => null], ['<=', 'startsAt', $now]])
            ->andWhere(['or', ['endsAt' => null], ['>=', 'endsAt', $now]])
            ->all();

        return $this->memo[$key] = $rows;
    }

    private function rowPrice(array $row, Purchasable $purchasable): float
    {
        // A discount-percentage row expresses itself against the catalogue price rather than
        // carrying its own. Several ERPs only ever send these.
        if ($row['discountPercent'] !== null && (float)$row['unitPrice'] === 0.0) {
            $base = (float)$purchasable->getPrice();

            return round($base * (1 - ((float)$row['discountPercent'] / 100)), 4);
        }

        return (float)$row['unitPrice'];
    }

    /**
     * @return Connection[]
     */
    private function connectionsFor(?Connection $connection): array
    {
        if ($connection) {
            return [$connection];
        }

        return array_values(array_filter(
            Plugin::getInstance()->getConnections()->enabled(),
            static fn(Connection $c) => $c->syncs(\justinholtweb\erpy\base\Entity::PRICE),
        ));
    }

    public function countFor(Connection $connection): int
    {
        return (int)(new Query())->from(Table::PRICES)->where(['connectionId' => $connection->id])->count();
    }

    public function clear(Connection $connection): int
    {
        $this->memo = [];

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::PRICES, ['connectionId' => $connection->id])
            ->execute();
    }

    public function resetMemo(): void
    {
        $this->memo = [];
    }
}
