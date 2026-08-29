<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\erpy\base\ApplyResult;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\Account;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\FieldMap;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * ERP customers, and the Craft users they belong to.
 *
 * Creating Craft users from an ERP is off by default and always will be. An ERP customer list is
 * full of accounts that have never seen a website, and turning ten thousand of them into
 * password-less Craft users the first time somebody clicks "sync" is not a mistake you can
 * quietly undo.
 */
class Accounts extends Component
{
    // ---------------------------------------------------------------------------------------
    // Inbound
    // ---------------------------------------------------------------------------------------

    public function applyCustomer(Connection $connection, ErpCustomer $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        if (trim($document->code) === '') {
            return ApplyResult::failed(Craft::t('erpy', 'The ERP sent a customer with no code.'), false);
        }

        $user = $this->resolveUser($connection, $document, $map);

        if (!$user && !$map->option('createMissingUsers', false)) {
            // Still worth recording: the account row is what makes contract pricing work for a
            // user who registers later with the same email address.
            if (!$dryRun) {
                $this->saveAccount($connection, $document, null);
            }

            return ApplyResult::skipped(Craft::t('erpy', 'No Craft user matches this customer; stored the ERP profile only.'));
        }

        if ($dryRun) {
            return ApplyResult::skipped($user
                ? Craft::t('erpy', 'Would update {email}.', ['email' => $user->email])
                : Craft::t('erpy', 'Would create a user for {email}.', ['email' => $document->email]));
        }

        $isNew = false;

        if (!$user) {
            if (trim((string)$document->email) === '') {
                return ApplyResult::skipped(Craft::t('erpy', 'Cannot create a user: the ERP sent no email address.'));
            }

            $user = new User();
            $user->email = $document->email;
            $user->username = $document->email;
            $user->active = false;
            $isNew = true;
        }

        $changes = $this->populateUser($user, $document, $map, $isNew);

        if ($changes !== [] || $isNew) {
            if (!Craft::$app->getElements()->saveElement($user)) {
                return ApplyResult::failed($this->errorSummary($user), false);
            }

            if ($isNew && ($groupId = (int)$map->option('userGroupId'))) {
                try {
                    Craft::$app->getUsers()->assignUserToGroups($user->id, [$groupId]);
                } catch (Throwable $e) {
                    Craft::warning('Erpy could not assign a user group: ' . $e->getMessage(), 'erpy');
                }
            }
        }

        $this->saveAccount($connection, $document, $user->id);

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: Entity::CUSTOMER,
            naturalKey: $document->naturalKey(),
            localId: $user->id,
            localUid: $user->uid,
            remoteId: $document->remoteId,
            remoteKey: $document->code,
            contentHash: $document->contentHash(),
            pulledAt: new DateTime(),
        );

        return $isNew ? ApplyResult::created($user->id, $changes) : ApplyResult::updated($user->id, $changes);
    }

    /**
     * Match an ERP customer to a Craft user: by the identity map first, then by email.
     *
     * Email is the fallback rather than the rule because two ERP accounts sharing a purchasing
     * manager's address is common, and whichever synced last would otherwise win.
     */
    private function resolveUser(Connection $connection, ErpCustomer $document, FieldMap $map): ?User
    {
        $link = Plugin::getInstance()->getLinks()->find($connection, Entity::CUSTOMER, $document->naturalKey());

        if ($link?->localId) {
            $user = Craft::$app->getUsers()->getUserById($link->localId);

            if ($user) {
                return $user;
            }
        }

        $account = $this->getByCustomerCode($connection, $document->code);

        if ($account?->userId) {
            $user = Craft::$app->getUsers()->getUserById($account->userId);

            if ($user) {
                return $user;
            }
        }

        if ($map->option('matchByEmail', true) && trim((string)$document->email) !== '') {
            return Craft::$app->getUsers()->getUserByUsernameOrEmail($document->email);
        }

        return null;
    }

    private function populateUser(User $user, ErpCustomer $document, FieldMap $map, bool $isNew): array
    {
        $changes = [];

        if ($isNew || $map->option('updateUserNames', false)) {
            $name = trim($document->name);

            if ($name !== '' && $user->fullName !== $name) {
                $changes['fullName'] = [$user->fullName, $name];
                // Commerce and Craft both fall back to `fullName`, and splitting an ERP company
                // name into first/last would produce nonsense for "Acme Industrial Supplies Ltd".
                $user->fullName = $name;
            }
        }

        foreach (Plugin::getInstance()->getMapping()->apply($map, $document) as $handle => $value) {
            $layout = $user->getFieldLayout();

            if (!$layout || !$layout->getFieldByHandle($handle)) {
                continue;
            }

            if ($user->getFieldValue($handle) != $value) {
                $changes[$handle] = ['…', is_scalar($value) ? $value : '…'];
                $user->setFieldValue($handle, $value);
            }
        }

        return $changes;
    }

    public function applyCredit(Connection $connection, ErpCredit $document, FieldMap $map, bool $dryRun = false): ApplyResult
    {
        if (trim($document->customerCode) === '') {
            return ApplyResult::failed(Craft::t('erpy', 'A credit record arrived with no customer code.'), false);
        }

        $existing = $this->getByCustomerCode($connection, $document->customerCode);

        if ($dryRun) {
            return ApplyResult::skipped(Craft::t('erpy', 'Would update the credit standing.'));
        }

        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'creditLimit' => $document->creditLimit,
            'balance' => $document->balance,
            'openOrders' => $document->openOrders,
            'overdueAmount' => $document->overdueAmount,
            'onHold' => $document->onHold,
            'currency' => $document->currency,
            'paymentTermsCode' => $document->paymentTermsCode ?? $existing?->paymentTermsCode,
            'openInvoices' => json_encode($document->openInvoices),
            'creditCheckedAt' => $now,
            'dateUpdated' => $now,
        ];

        Craft::$app->getDb()->createCommand()->upsert(Table::ACCOUNTS, array_merge([
            'connectionId' => $connection->id,
            'customerCode' => $document->customerCode,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ], $values), $values)->execute();

        if ($existing && abs((float)$existing->balance - $document->balance) < 0.0001 && $existing->onHold === $document->onHold) {
            return ApplyResult::skipped(Craft::t('erpy', 'Unchanged.'), $existing->userId);
        }

        return $existing
            ? ApplyResult::updated((int)$existing->userId, ['balance' => [$existing->balance, $document->balance]])
            : ApplyResult::created(0);
    }

    private function saveAccount(Connection $connection, ErpCustomer $document, ?int $userId): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        $values = array_filter([
            'userId' => $userId,
            'name' => $document->name,
            'priceListCode' => $document->priceListCode,
            'customerGroupCode' => $document->customerGroupCode,
            'currency' => $document->currency,
            'paymentTermsCode' => $document->paymentTermsCode,
            'salespersonCode' => $document->salespersonCode,
            'discountPercent' => $document->discountPercent,
            'creditLimit' => $document->creditLimit,
            'balance' => $document->balance,
            'taxId' => $document->taxId,
        ], static fn($value) => $value !== null);

        // These two are booleans and a legitimate `false` must survive the filter above.
        $values['onHold'] = $document->onHold;
        $values['taxExempt'] = $document->taxExempt;
        $values['dateUpdated'] = $now;

        Craft::$app->getDb()->createCommand()->upsert(Table::ACCOUNTS, array_merge([
            'connectionId' => $connection->id,
            'customerCode' => $document->code,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ], $values), $values)->execute();
    }

    // ---------------------------------------------------------------------------------------
    // Lookups
    // ---------------------------------------------------------------------------------------

    public function getByCustomerCode(Connection $connection, string $code): ?Account
    {
        $row = (new Query())
            ->from(Table::ACCOUNTS)
            ->where(['connectionId' => $connection->id, 'customerCode' => $code])
            ->one();

        return $row ? new Account($row) : null;
    }

    public function getByUserId(Connection $connection, int $userId): ?Account
    {
        $row = (new Query())
            ->from(Table::ACCOUNTS)
            ->where(['connectionId' => $connection->id, 'userId' => $userId])
            ->one();

        return $row ? new Account($row) : null;
    }

    /**
     * The account for whoever is currently shopping, on the connection that owns this store.
     */
    public function forUser(?User $user, ?Connection $connection = null): ?Account
    {
        if (!$user) {
            return null;
        }

        $connections = $connection ? [$connection] : Plugin::getInstance()->getConnections()->enabled();

        foreach ($connections as $candidate) {
            $account = $this->getByUserId($candidate, $user->id);

            if ($account) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Attach a Craft user to an ERP customer code by hand, for the common case where the two
     * systems were populated independently and nothing matches on email.
     */
    public function link(Connection $connection, int $userId, string $customerCode): bool
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->upsert(Table::ACCOUNTS, [
            'connectionId' => $connection->id,
            'customerCode' => $customerCode,
            'userId' => $userId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], ['userId' => $userId, 'dateUpdated' => $now])->execute();

        Plugin::getInstance()->getLinks()->record(
            connection: $connection,
            entity: Entity::CUSTOMER,
            naturalKey: $customerCode,
            localId: $userId,
        );

        return true;
    }

    private function errorSummary(\craft\base\ElementInterface $element): string
    {
        $messages = [];

        foreach ($element->getErrors() as $attribute => $errors) {
            $messages[] = $attribute . ': ' . implode(' ', $errors);
        }

        return $messages ? implode('; ', $messages) : Craft::t('erpy', 'Craft refused to save it and said nothing about why.');
    }
}
