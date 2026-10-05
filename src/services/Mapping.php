<?php

namespace justinholtweb\erpy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\db\Table;
use justinholtweb\erpy\models\canonical\ErpDocument;
use justinholtweb\erpy\models\Connection;
use justinholtweb\erpy\models\FieldMap;

/**
 * Field mapping.
 *
 * Every ERP integration eventually hits the same wall: the connector's defaults are 90% right and
 * the merchant needs the other 10% without forking a plugin. So a connector's output is canonical
 * and *then* passed through the merchant's rules, which can read anything in the document —
 * including the untouched ERP payload under `raw.` — and write to any Craft field.
 *
 * The transform vocabulary is deliberately small and non-Turing-complete. A mapping UI that can
 * run arbitrary code is a mapping UI that can take a site down at 3am.
 */
class Mapping extends Component
{
    /** @var array<string,FieldMap>|null */
    private ?array $maps = null;

    public function get(Connection $connection, string $entity, int $direction): FieldMap
    {
        $key = $connection->id . ':' . $entity . ':' . $direction;

        if ($this->maps === null) {
            $this->maps = [];

            foreach ((new Query())->from(Table::MAPS)->all() as $row) {
                $map = new FieldMap($row);
                $this->maps[$map->connectionId . ':' . $map->entity . ':' . $map->direction] = $map;
            }
        }

        return $this->maps[$key] ?? new FieldMap([
            'connectionId' => $connection->id,
            'entity' => $entity,
            'direction' => $direction,
        ]);
    }

    public function save(FieldMap $map): bool
    {
        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'rules' => json_encode($map->rules),
            'options' => json_encode($map->options),
            'dateUpdated' => $now,
        ];

        Craft::$app->getDb()->createCommand()->upsert(Table::MAPS, array_merge([
            'connectionId' => $map->connectionId,
            'entity' => $map->entity,
            'direction' => $map->direction,
            'dateCreated' => $now,
            'uid' => StringHelper::UUID(),
        ], $values), $values)->execute();

        $this->maps = null;

        return true;
    }

    /**
     * Correct the connector's own reading of the ERP, before anything else happens.
     *
     * A rule whose target is a canonical field — `sku`, `unitPrice`, `customerCode` — is not a
     * Craft mapping at all: it is the merchant telling the connector it guessed the wrong ERP
     * field. That matters most on the ERPs whose APIs are configured per customer (AFAS
     * GetConnectors, a published Business Central page, a Priority form), where no connector
     * could know the field names in advance.
     *
     * It also means a connector that gets a field wrong is a mapping fix rather than a bug
     * report, which is the difference between a merchant being blocked for a fortnight and being
     * blocked for an afternoon.
     *
     * @return string[] the canonical fields that were overwritten
     */
    public function overlay(FieldMap $map, ErpDocument $document, array $context = []): array
    {
        $applied = [];

        foreach ($map->rules as $rule) {
            $target = trim((string)($rule['target'] ?? ''));

            if ($target === '' || !$this->isCanonical($document, $target)) {
                continue;
            }

            $value = $this->resolveRule($rule, $document, $context);

            if ($value === null) {
                continue;
            }

            // The document's own types have to be respected: a connector that declared
            // `float $unitPrice` will fatal on a string, and the ERP field being mapped in is
            // almost always a string.
            $document->$target = $this->coerce($document, $target, $value);
            $applied[] = $target;
        }

        return $applied;
    }

    private function isCanonical(ErpDocument $document, string $target): bool
    {
        return property_exists($document, $target) && !in_array($target, ['raw', 'extra'], true);
    }

    /**
     * Cast a mapped value to whatever the canonical property is declared as.
     */
    private function coerce(ErpDocument $document, string $property, mixed $value): mixed
    {
        try {
            $type = (new \ReflectionProperty($document, $property))->getType();
        } catch (\Throwable) {
            return $value;
        }

        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'float' => is_numeric($value) ? (float)$value : ($type->allowsNull() ? null : 0.0),
            'int' => is_numeric($value) ? (int)$value : ($type->allowsNull() ? null : 0),
            'bool' => $this->toBool($value),
            'string' => is_scalar($value) ? (string)$value : ($type->allowsNull() ? null : ''),
            'array' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }

    private function resolveRule(array $rule, ErpDocument $document, array $context): mixed
    {
        $value = $this->resolve((string)($rule['source'] ?? ''), $document, $context);

        foreach ($this->transformList($rule['transform'] ?? '') as $transform) {
            $value = $this->transform($value, $transform);
        }

        if ($value === null || $value === '') {
            $default = $rule['default'] ?? null;

            return ($default === null || $default === '') ? null : $default;
        }

        return $value;
    }

    /**
     * Run a map over a document and return `target => value` for the Craft side.
     *
     * Rules that resolve to nothing are dropped rather than written as null: a mapping that
     * silently blanks a field every time the ERP omits it is worse than no mapping at all. Rules
     * targeting a canonical field are dropped too — `overlay()` has already applied those, and
     * writing them again would look for a Craft field that was never meant to exist.
     */
    public function apply(FieldMap $map, ErpDocument $document, array $context = []): array
    {
        $out = [];

        foreach ($map->rules as $rule) {
            $target = trim((string)($rule['target'] ?? ''));

            if ($target === '' || $this->isCanonical($document, $target)) {
                continue;
            }

            $value = $this->resolve((string)($rule['source'] ?? ''), $document, $context);

            foreach ($this->transformList($rule['transform'] ?? '') as $transform) {
                $value = $this->transform($value, $transform);
            }

            if ($value === null || $value === '') {
                $default = $rule['default'] ?? null;

                if ($default === null || $default === '') {
                    continue;
                }

                $value = $default;
            }

            $out[$target] = $value;
        }

        return $out;
    }

    /**
     * Read a dotted path out of a canonical document, its raw ERP payload, or the surrounding
     * context.
     *
     * `sku` reads the canonical field. `raw.Item_Category_Code` reads the ERP's own payload.
     * `attributes.COLOUR` reads a nested array. `extra.whatever` reads anything the connector
     * could not classify.
     */
    public function resolve(string $path, ErpDocument $document, array $context = []): mixed
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        // A quoted literal is a constant, which is how a merchant pins a value without needing a
        // "static value" rule type.
        if (preg_match('/^([\'"])(.*)\1$/', $path, $matches)) {
            return $matches[2];
        }

        $segments = explode('.', $path);
        $root = array_shift($segments);

        $current = match ($root) {
            'raw' => $document->raw,
            'extra' => $document->extra,
            'context' => $context,
            default => $this->documentValue($document, $root, $context),
        };

        foreach ($segments as $segment) {
            $current = $this->step($current, $segment);

            if ($current === null) {
                return null;
            }
        }

        return $current;
    }

    private function documentValue(ErpDocument $document, string $name, array $context): mixed
    {
        if (property_exists($document, $name)) {
            return $document->$name;
        }

        if (array_key_exists($name, $document->extra)) {
            return $document->extra[$name];
        }

        // Unqualified names fall back to the raw payload, because that is what a merchant reading
        // their ERP's API documentation will type first.
        if (array_key_exists($name, $document->raw)) {
            return $document->raw[$name];
        }

        return $context[$name] ?? null;
    }

    private function step(mixed $current, string $segment): mixed
    {
        if (is_array($current)) {
            // A numeric segment indexes a list; `0` and `first` both mean the same thing, because
            // both are what people try.
            if ($segment === 'first') {
                return reset($current) ?: null;
            }

            if ($segment === 'last') {
                return end($current) ?: null;
            }

            return $current[$segment] ?? null;
        }

        if ($current instanceof ErpDocument) {
            return property_exists($current, $segment)
                ? $current->$segment
                : ($current->raw[$segment] ?? $current->extra[$segment] ?? null);
        }

        if (is_object($current)) {
            // Getters before properties: Craft models expose most of their useful values that way,
            // and reading the property first returns the un-normalised copy.
            $getter = 'get' . ucfirst($segment);

            if (method_exists($current, $getter)) {
                return $current->$getter();
            }

            return $current->$segment ?? null;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function transformList(mixed $transform): array
    {
        if (is_array($transform)) {
            return array_filter(array_map('trim', $transform));
        }

        return array_filter(array_map('trim', explode('|', (string)$transform)));
    }

    public function transform(mixed $value, string $transform): mixed
    {
        [$name, $argument] = array_pad(explode(':', $transform, 2), 2, null);

        return match ($name) {
            'trim' => is_string($value) ? trim($value) : $value,
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'ucfirst' => is_string($value) ? StringHelper::upperCaseFirst($value) : $value,
            'title' => is_string($value) ? StringHelper::toTitleCase($value) : $value,
            'slug' => is_string($value) ? StringHelper::slugify($value) : $value,
            'striptags' => is_string($value) ? strip_tags($value) : $value,
            'int' => (int)$value,
            'float', 'number' => (float)$value,
            'abs' => is_numeric($value) ? abs((float)$value) : $value,
            'round' => is_numeric($value) ? round((float)$value, (int)($argument ?? 2)) : $value,
            'bool' => $this->toBool($value),
            'not' => !$this->toBool($value),
            'prefix' => $value === null || $value === '' ? $value : $argument . $value,
            'suffix' => $value === null || $value === '' ? $value : $value . $argument,
            'truncate' => is_string($value) ? mb_substr($value, 0, max(1, (int)$argument)) : $value,
            'replace' => $this->replace($value, (string)$argument),
            'date' => $this->toDate($value, $argument),
            'join' => is_array($value) ? implode($argument ?? ', ', array_map('strval', $value)) : $value,
            'split' => is_string($value) ? array_map('trim', explode($argument ?: ',', $value)) : $value,
            'first' => is_array($value) ? (reset($value) ?: null) : $value,
            'count' => is_array($value) ? count($value) : ($value === null ? 0 : 1),
            'json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'default' => $value === null || $value === '' ? $argument : $value,
            'nullif' => (string)$value === (string)$argument ? null : $value,
            '' => $value,
            default => $value,
        };
    }

    /**
     * The transforms a merchant can pick from, for the mapping screen's dropdown.
     */
    public function transformOptions(): array
    {
        return [
            'trim' => Craft::t('erpy', 'Trim whitespace'),
            'upper' => Craft::t('erpy', 'UPPERCASE'),
            'lower' => Craft::t('erpy', 'lowercase'),
            'ucfirst' => Craft::t('erpy', 'Capitalise first letter'),
            'title' => Craft::t('erpy', 'Title Case'),
            'slug' => Craft::t('erpy', 'Slug'),
            'striptags' => Craft::t('erpy', 'Strip HTML'),
            'int' => Craft::t('erpy', 'Whole number'),
            'number' => Craft::t('erpy', 'Decimal number'),
            'round:2' => Craft::t('erpy', 'Round to 2 decimals'),
            'abs' => Craft::t('erpy', 'Absolute value'),
            'bool' => Craft::t('erpy', 'Yes/no'),
            'not' => Craft::t('erpy', 'Yes/no, inverted'),
            'prefix:' => Craft::t('erpy', 'Add a prefix…'),
            'suffix:' => Craft::t('erpy', 'Add a suffix…'),
            'truncate:255' => Craft::t('erpy', 'Truncate to 255 characters'),
            'replace:a:b' => Craft::t('erpy', 'Find and replace…'),
            'date:Y-m-d' => Craft::t('erpy', 'Format as a date…'),
            'join:, ' => Craft::t('erpy', 'Join a list…'),
            'split:,' => Craft::t('erpy', 'Split into a list…'),
            'first' => Craft::t('erpy', 'First item of a list'),
            'count' => Craft::t('erpy', 'Count of a list'),
            'default:' => Craft::t('erpy', 'Fall back to…'),
            'nullif:' => Craft::t('erpy', 'Treat this value as empty…'),
        ];
    }

    private function replace(mixed $value, string $argument): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        [$search, $replace] = array_pad(explode(':', $argument, 2), 2, '');

        return str_replace($search, (string)$replace, $value);
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            // ERPs express "no" as an alarming number of things, and `"0"` and `"false"` are only
            // the polite ones. Anything not obviously affirmative is false.
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on', 't'], true);
        }

        return (bool)$value;
    }

    private function toDate(mixed $value, ?string $format): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $format ? $value->format($format) : $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = new DateTime($value);
        } catch (\Throwable) {
            return null;
        }

        return $format ? $date->format($format) : $date;
    }

    /**
     * The canonical field names a merchant can map *to* when pushing, or *from* when pulling —
     * used to populate the source/target autosuggests.
     */
    public function documentFields(string $entity): array
    {
        $class = \justinholtweb\erpy\base\Entity::documentClass($entity);
        /** @var ErpDocument $sample */
        $sample = new $class();
        $fields = [];

        foreach (get_object_vars($sample) as $name => $value) {
            if (in_array($name, ['raw', 'extra'], true)) {
                continue;
            }

            $fields[$name] = $name;
        }

        return $fields;
    }
}
