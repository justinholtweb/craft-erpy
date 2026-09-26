<?php

namespace justinholtweb\erpy\base;

use Craft;
use justinholtweb\erpy\auth\OAuth2AuthorizationCode;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\Plugin;
use Throwable;

/**
 * The base every add-on extends.
 *
 * A connector that extends this has three jobs and no others: declare what it can do, describe
 * its credentials, and turn one page of the ERP's own payloads into canonical documents (and one
 * canonical document back into the ERP's payload). Everything a connector is *not* asked to do —
 * paging, cursors, retries, throttling, logging, redaction, mapping, the identity map, the queue,
 * dry runs, dead letters — is why this plugin costs money and the add-ons do not.
 */
abstract class Connector implements ConnectorInterface
{
    protected Connection $connection;

    private ?Transport $transport = null;

    private ?AuthInterface $auth = null;

    /**
     * Which method handles which entity. A connector implements only the ones its capabilities
     * claim; anything else is a configuration error the engine reports rather than a fatal.
     */
    private const FETCH_METHODS = [
        Entity::PRODUCT => 'fetchProducts',
        Entity::PRICE => 'fetchPrices',
        Entity::INVENTORY => 'fetchInventory',
        Entity::CUSTOMER => 'fetchCustomers',
        Entity::ORDER => 'fetchOrders',
        Entity::ORDER_STATUS => 'fetchOrderStatuses',
        Entity::SHIPMENT => 'fetchShipments',
        Entity::INVOICE => 'fetchInvoices',
        Entity::PAYMENT => 'fetchPayments',
        Entity::CREDIT => 'fetchCredit',
    ];

    private const PUSH_METHODS = [
        Entity::ORDER => 'pushOrder',
        Entity::CUSTOMER => 'pushCustomer',
        Entity::PAYMENT => 'pushPayment',
        Entity::PRODUCT => 'pushProduct',
        Entity::INVENTORY => 'pushInventory',
        Entity::SHIPMENT => 'pushShipment',
    ];

    public static function vendor(): string
    {
        return '';
    }

    public static function description(): string
    {
        return '';
    }

    public static function setupUrl(): ?string
    {
        return null;
    }

    public static function settingsFields(): array
    {
        return [];
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
        $this->transport = null;
        $this->auth = null;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @inheritdoc
     */
    public function fetchPage(string $entity, FetchCriteria $criteria): Page
    {
        $method = self::FETCH_METHODS[$entity] ?? null;

        if ($method === null || !method_exists($this, $method)) {
            throw new \BadMethodCallException(sprintf(
                '%s claims to pull %s but does not implement %s().',
                static::displayName(),
                $entity,
                $method ?? $entity,
            ));
        }

        return $this->$method($criteria);
    }

    /**
     * @inheritdoc
     */
    public function pushDocument(string $entity, object $document, ?string $remoteId = null): PushResult
    {
        $method = self::PUSH_METHODS[$entity] ?? null;

        if ($method === null || !method_exists($this, $method)) {
            return PushResult::rejected(sprintf(
                '%s cannot write %s.',
                static::displayName(),
                $entity,
            ));
        }

        return $this->$method($document, $remoteId);
    }

    /**
     * @inheritdoc
     *
     * The default test asks the connector for the cheapest read it has and reports what came
     * back. A connector with something better to say — a company name, a licence expiry — should
     * override this, because a merchant staring at a failed connection needs a noun, not a 401.
     */
    public function test(): HealthResult
    {
        $startedAt = microtime(true);

        $auth = $this->auth();

        // An authorization-code connector with its app registration saved but no consent yet is
        // not "incomplete" — the merchant has filled in every field there is. What it is missing
        // is the Connect button, and saying "fill in every required field" sends them hunting
        // for a field that does not exist.
        $awaitingConsent = $auth instanceof OAuth2AuthorizationCode
            && $auth->hasClientCredentials()
            && !$auth->isAuthorized();

        // Incomplete means an auth that is not configured, or a required field left blank. A
        // connector with settings but no auth at all (the Mock, a file-based one) has nothing to
        // be "incomplete" about beyond its required fields — `!$auth?->isConfigured()` read a
        // missing auth as an unconfigured one and failed every test (GitHub #2).
        $authIncomplete = $auth !== null && !$awaitingConsent && !$auth->isConfigured();
        $missing = array_filter(
            Field::requiredNames(static::settingsFields()),
            fn(string $name) => in_array($this->setting($name), [null, ''], true),
        );

        if ($authIncomplete || $missing !== []) {
            return HealthResult::fail(
                Craft::t('erpy', 'Credentials are incomplete.'),
                [Craft::t('erpy', 'Fill in every required field above, then save before testing.')],
            );
        }

        try {
            // The connector's own probe gets to say it in its own words ("approve access in Exact
            // once"); the generic sentence is only for one that did not notice.
            $result = $this->probe();
        } catch (Throwable $e) {
            $result = HealthResult::fail($e->getMessage());
        }

        if ($awaitingConsent && $result->ok) {
            $result = HealthResult::fail(
                Craft::t('erpy', 'Not connected yet.'),
                [Craft::t('erpy', 'Use the Connect button to approve access in {erp} once.', ['erp' => static::displayName()])],
            );
        }


        $result->durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $result->message = $this->redactSecrets($result->message);

        foreach ($result->details as $key => $value) {
            $result->details[$key] = is_string($value) ? $this->redactSecrets($value) : $value;
        }

        return $result;
    }

    /**
     * The cheapest request that proves the credentials work. Override in every connector.
     */
    protected function probe(): HealthResult
    {
        return HealthResult::pass(Craft::t('erpy', 'Connector loaded. It does not implement a connection test.'));
    }

    /**
     * The authentication strategy for this connection, built once.
     */
    public function auth(): ?AuthInterface
    {
        if ($this->auth === null) {
            $this->auth = $this->buildAuth();

            if ($this->auth !== null) {
                $this->auth->setConnection($this->connection);

                // The strategy gets its own unauthenticated transport: an OAuth token request
                // cannot go through the client that is waiting on it for a header.
                if (method_exists($this->auth, 'setTransport')) {
                    $this->auth->setTransport(
                        (new Transport())
                            ->setConnection($this->connection)
                            ->setSecretValues($this->secretValues()),
                    );
                }
            }
        }

        return $this->auth;
    }

    protected function buildAuth(): ?AuthInterface
    {
        return null;
    }

    /**
     * The HTTP client for this connection, built once and shared across a whole run so throttling
     * and session cookies survive between pages.
     */
    public function transport(): Transport
    {
        if ($this->transport === null) {
            $this->transport = $this->buildTransport();
            $this->transport->setConnection($this->connection);
            $this->transport->setAuth($this->auth());
            $this->transport->setSecretValues($this->secretValues());
        }

        return $this->transport;
    }

    protected function buildTransport(): Transport
    {
        return new Transport();
    }

    /**
     * Replace the network with a callable. The engine never calls this; the test suite and the
     * "replay this request" button do.
     */
    public function useDouble(callable $double): void
    {
        $this->transport()->setDouble($double);

        $auth = $this->auth();

        if ($auth !== null && method_exists($auth, 'setTransport')) {
            $auth->setTransport(
                (new Transport())
                    ->setConnection($this->connection)
                    ->setDouble($double),
            );
        }
    }

    /**
     * Every configured credential value, so the transport can keep them out of the log even when
     * an ERP helpfully echoes them back in an error message.
     */
    /**
     * Strip any configured credential out of a string bound for a merchant's screen.
     */
    protected function redactSecrets(string $value): string
    {
        $secrets = $this->secretValues();

        return $secrets === [] ? $value : str_replace($secrets, '••••••••', $value);
    }

    protected function secretValues(): array
    {
        $values = [];

        foreach (Field::secretNames(static::settingsFields()) as $name) {
            $value = $this->connection->getSetting($name);

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Shorthand for a connection setting, with `$ENV_VAR` already resolved.
     */
    protected function setting(string $name, mixed $default = null): mixed
    {
        return $this->connection->getSetting($name, $default);
    }

    protected function boolSetting(string $name, bool $default = false): bool
    {
        $value = $this->setting($name, $default);

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool)$value;
    }

    /**
     * The page size to ask the ERP for, never larger than the ERP's declared maximum.
     */
    protected function pageSize(string $entity, FetchCriteria $criteria): int
    {
        return min($criteria->limit, static::capabilities()->pageSizeFor($entity));
    }

    /**
     * A short note in the run log, for the things a connector knows and the engine cannot —
     * "skipped 12 items with no sales UoM", say.
     */
    protected function note(string $message): void
    {
        Plugin::getInstance()->getLog()->note($this->connection, $message);
    }
}
