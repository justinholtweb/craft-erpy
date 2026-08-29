<?php

namespace justinholtweb\erpy\base;

use Craft;

/**
 * Which way a given entity moves for a given connector.
 *
 * Stored as a bitmask so a connector can declare "I can do both" once, and a connection can
 * narrow it to one leg without the connector knowing.
 */
abstract class Direction
{
    /** ERP → Commerce. */
    public const PULL = 1;

    /** Commerce → ERP. */
    public const PUSH = 2;

    public const BOTH = self::PULL | self::PUSH;

    public static function allows(int $mask, int $direction): bool
    {
        return ($mask & $direction) === $direction;
    }

    public static function displayName(int $direction): string
    {
        return match ($direction) {
            self::PULL => Craft::t('erpy', 'ERP → Commerce'),
            self::PUSH => Craft::t('erpy', 'Commerce → ERP'),
            self::BOTH => Craft::t('erpy', 'Both ways'),
            default => (string)$direction,
        };
    }

    public static function label(int $direction): string
    {
        return match ($direction) {
            self::PULL => 'pull',
            self::PUSH => 'push',
            default => 'both',
        };
    }
}
