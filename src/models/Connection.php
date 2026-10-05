<?php

namespace justinholtweb\erpy\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\erpy\base\ConnectorInterface;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\helpers\Secret;
use justinholtweb\erpy\Plugin;
use justinholtweb\erpy\records\ConnectionRecord;

/**
 * One configured link to one ERP company.
 *
 * A site can have several: a live company and a sandbox, or two subsidiaries feeding two Commerce
 * stores. Everything the engine does is scoped to a connection, so nothing about a second one can
 * disturb the first.
 */
class Connection extends Model
{
    public ?int $id = null;

    public string $name = '';

    public string $handle = '';

    /** The connector handle — `business-central`, `netsuite`, `odoo`… */
    public string $connector = '';

    public bool $enabled = false;

    /** The Commerce store this connection feeds. Null means the primary store. */
    public ?int $storeId = null;

    /** Credentials and connector options, keyed by the connector's own field names. */
    public array $settings = [];

    /**
     * Per-entity sync configuration:
     * `['product' => ['enabled' => true, 'direction' => 1, 'interval' => 900, 'filters' => []]]`
     */
    public array $sync = [];

    /** Where OAuth-based connectors keep their tokens. Never rendered, never exported. */
    public array $tokens = [];

    public ?int $sortOrder = null;

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public function __construct($config = [])
    {
        // Records hand these back as JSON strings; the CP hands them back as arrays.
        foreach (['settings', 'sync'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = json_decode($config[$key], true) ?: [];
            }
        }

        // Encrypted since 5.1.1; a row written before then is plain JSON and still reads.
        if (isset($config['tokens']) && is_string($config['tokens'])) {
            $config['tokens'] = Secret::decodeTokens($config['tokens']);
        }

        parent::__construct($config);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'connector'], 'required'],
            [['name', 'handle', 'connector'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => ConnectionRecord::class],
            [['handle'], 'required'],
            [['storeId', 'id'], 'integer'],
            [['enabled'], 'boolean'],
            [['connector'], 'validateConnector'],
            [['settings'], 'validateSettings'],
        ];
    }

    public function validateConnector(string $attribute): void
    {
        if ($this->connector === '') {
            return;
        }

        if (!Plugin::getInstance()->getConnectors()->has($this->connector)) {
            $this->addError($attribute, Craft::t('erpy', 'No connector is installed for “{handle}”. Install its add-on plugin, or pick another.', [
                'handle' => $this->connector,
            ]));
        }
    }

    /**
     * Required credentials are only enforced when the connection is switched on. A half-filled
     * draft has to be saveable, or a merchant cannot walk away mid-setup.
     */
    public function validateSettings(): void
    {
        if (!$this->enabled) {
            return;
        }

        $connector = $this->getConnector();

        if (!$connector) {
            return;
        }

        foreach (Field::requiredNames($connector::settingsFields()) as $name) {
            if (trim((string)($this->settings[$name] ?? '')) === '') {
                $this->addError('settings', Craft::t('erpy', '“{field}” is required before this connection can be enabled.', [
                    'field' => $this->settingsFieldLabel($name),
                ]));
            }
        }
    }

    private function settingsFieldLabel(string $name): string
    {
        $connector = $this->getConnector();

        foreach ($connector ? $connector::settingsFields() : [] as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field['label'] ?? $name;
            }
        }

        return $name;
    }

    /**
     * The connector instance bound to this connection, or null if its add-on is not installed.
     */
    public function getConnector(): ?ConnectorInterface
    {
        return Plugin::getInstance()->getConnectors()->create($this);
    }

    /**
     * A credential, with `$VARIABLE` resolved. Every read of a setting goes through here so an
     * env var works in exactly the same places a literal does.
     */
    public function getSetting(string $name, mixed $default = null): mixed
    {
        $value = $this->settings[$name] ?? $default;

        // A literal secret is stored encrypted (5.1.1); an `$ENV` reference is not, and resolves.
        if (Secret::isEncrypted($value)) {
            return Secret::decrypt($value) ?? '';
        }

        if (is_string($value) && $value !== '') {
            return Craft::parseEnv($value);
        }

        return $value;
    }

    public function setSetting(string $name, mixed $value): void
    {
        $this->settings[$name] = $value;
    }

    /**
     * Whether this connection syncs the given entity, in the given direction, right now. Answers
     * false if the connector cannot do it at all — the connection can only ever narrow.
     */
    public function syncs(string $entity, ?int $direction = null): bool
    {
        if (!($this->sync[$entity]['enabled'] ?? false)) {
            return false;
        }

        $connector = $this->getConnector();

        if (!$connector || !$connector::capabilities()->handles($entity, $direction)) {
            return false;
        }

        if ($direction === null) {
            return true;
        }

        return Direction::allows($this->directionFor($entity), $direction);
    }

    public function directionFor(string $entity): int
    {
        $connector = $this->getConnector();
        $possible = $connector ? $connector::capabilities()->directionFor($entity) : 0;
        $configured = (int)($this->sync[$entity]['direction'] ?? $possible);

        // A connection can turn a leg off. It can never turn one on that the ERP cannot do.
        return $configured & $possible;
    }

    public function intervalFor(string $entity): int
    {
        return max(0, (int)($this->sync[$entity]['interval'] ?? 0));
    }

    public function filtersFor(string $entity): array
    {
        return (array)($this->sync[$entity]['filters'] ?? []);
    }

    /**
     * @return string[] entities this connection actually syncs, in dependency order
     */
    public function activeEntities(): array
    {
        return array_values(array_filter(
            Entity::syncOrder(),
            fn(string $entity) => $this->syncs($entity),
        ));
    }

    public function getStoreId(): int
    {
        if ($this->storeId) {
            return $this->storeId;
        }

        return \craft\commerce\Plugin::getInstance()->getStores()->getPrimaryStore()->id;
    }

    /**
     * The connection minus anything secret — safe to log, export or show in a support ticket.
     */
    public function toSafeArray(): array
    {
        $connector = $this->getConnector();
        $secrets = $connector ? Field::secretNames($connector::settingsFields()) : [];
        $settings = $this->settings;

        foreach ($secrets as $name) {
            if (($settings[$name] ?? '') !== '') {
                $settings[$name] = '••••••••';
            }
        }

        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'connector' => $this->connector,
            'enabled' => $this->enabled,
            'settings' => $settings,
            'sync' => $this->sync,
        ];
    }

    public function __toString(): string
    {
        return $this->name ?: ($this->handle ?: StringHelper::UUID());
    }
}
