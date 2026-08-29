<?php

namespace justinholtweb\erpy\models;

use craft\base\Model;
use DateTime;
use justinholtweb\erpy\base\Direction;

/**
 * A merchant's field mapping for one connection, entity and direction.
 */
class FieldMap extends Model
{
    public ?int $id = null;
    public ?int $connectionId = null;
    public string $entity = '';
    public int $direction = Direction::PULL;

    /** @var array<int,array{source:string,target:string,transform?:string,default?:string}> */
    public array $rules = [];

    /** Per-entity switches the mapping screen offers — "create missing products", and so on. */
    public array $options = [];

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function __construct($config = [])
    {
        foreach (['rules', 'options'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = json_decode($config[$key], true) ?: [];
            }
        }

        parent::__construct($config);
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}
