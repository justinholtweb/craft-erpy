<?php

namespace justinholtweb\erpy\twig;

use craft\commerce\base\Purchasable;
use craft\elements\User;
use justinholtweb\erpy\models\Account;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;
use yii\base\Behavior;

/**
 * `craft.erpy` — what a storefront needs from the ERP without knowing which ERP it is.
 *
 * Everything here answers from data Erpy has already pulled, so a template can call it on every
 * page without adding a request to somebody's ERP.
 */
class ErpyVariable extends Behavior
{
    /**
     * The ERP profile of the logged-in user, or of a user you name.
     */
    public function account(?User $user = null, ?string $connectionHandle = null): ?Account
    {
        $user ??= \Craft::$app->getUser()->getIdentity();
        $connection = $connectionHandle
            ? Plugin::getInstance()->getConnections()->getByHandle($connectionHandle)
            : null;

        return Plugin::getInstance()->getAccounts()->forUser($user, $connection);
    }

    /**
     * The price this customer pays for this purchasable at this quantity, or null when the ERP
     * has no opinion and Commerce's own price stands.
     */
    public function price(Purchasable $purchasable, float $quantity = 1, ?User $user = null): ?float
    {
        return Plugin::getInstance()->getPricing()->priceFor(
            $purchasable,
            $quantity,
            $user ?? \Craft::$app->getUser()->getIdentity(),
        );
    }

    /**
     * Quantity breaks for this purchasable and this customer — the "buy 10 and save" table.
     */
    public function priceBreaks(Purchasable $purchasable, ?User $user = null): array
    {
        return Plugin::getInstance()->getPricing()->breaksFor(
            $purchasable,
            $user ?? \Craft::$app->getUser()->getIdentity(),
        );
    }

    /**
     * Whether this customer can put another {amount} on account.
     *
     * Answers true when no ERP knows about them, and when the ERP sets no limit — an unknown
     * customer is not the same as a customer with no credit, and a storefront that confuses the
     * two stops selling.
     */
    public function canSpend(float $amount, ?User $user = null): bool
    {
        $account = $this->account($user);

        return $account === null || $account->canSpend($amount);
    }

    /**
     * Open invoices the ERP is waiting to be paid, for an account-history page.
     */
    public function openInvoices(?User $user = null): array
    {
        return $this->account($user)?->openInvoiceList() ?? [];
    }

    /**
     * Whether an order has reached the ERP yet, and what it is called there.
     *
     * @return array{delivered:bool, remoteId:?string, remoteKey:?string, error:?string}
     */
    public function orderStatus(string $orderNumber, ?string $connectionHandle = null): array
    {
        $connections = $connectionHandle
            ? array_filter([Plugin::getInstance()->getConnections()->getByHandle($connectionHandle)])
            : Plugin::getInstance()->getConnections()->enabled();

        foreach ($connections as $connection) {
            $link = Plugin::getInstance()->getLinks()->find($connection, \justinholtweb\erpy\base\Entity::ORDER, $orderNumber);

            if ($link) {
                return [
                    'delivered' => $link->isDelivered(),
                    'remoteId' => $link->remoteId,
                    'remoteKey' => $link->remoteKey,
                    'error' => $link->lastError,
                ];
            }
        }

        return ['delivered' => false, 'remoteId' => null, 'remoteKey' => null, 'error' => null];
    }

    /**
     * @return Connection[]
     */
    public function connections(): array
    {
        return array_values(Plugin::getInstance()->getConnections()->enabled());
    }

    public function connection(string $handle): ?Connection
    {
        return Plugin::getInstance()->getConnections()->getByHandle($handle);
    }
}
