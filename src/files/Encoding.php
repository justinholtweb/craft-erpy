<?php

namespace justinholtweb\erpy\files;

use Craft;

/**
 * Character sets, the way ERPs in this part of the world actually write them.
 *
 * Converted with iconv rather than mbstring because mbstring does not know Windows-1250 on every
 * PHP build, and that is the one this whole feature exists for.
 */
abstract class Encoding
{
    public const UTF8 = 'UTF-8';

    /** @return array<string,string> value => label, for a settings select */
    public static function options(): array
    {
        return [
            'UTF-8' => 'UTF-8',
            'WINDOWS-1250' => Craft::t('erpy', 'Windows-1250 (Central European)'),
            'ISO-8859-2' => Craft::t('erpy', 'ISO-8859-2 (Latin-2)'),
            'WINDOWS-1251' => Craft::t('erpy', 'Windows-1251 (Cyrillic)'),
            'WINDOWS-1252' => Craft::t('erpy', 'Windows-1252 (Western European)'),
            'ISO-8859-1' => Craft::t('erpy', 'ISO-8859-1 (Latin-1)'),
            'ISO-8859-15' => Craft::t('erpy', 'ISO-8859-15 (Latin-9)'),
            'UTF-16LE' => Craft::t('erpy', 'UTF-16 (little-endian, as Excel saves “Unicode text”)'),
        ];
    }

    public static function normalise(?string $encoding): string
    {
        $encoding = strtoupper(trim((string)$encoding));

        return match ($encoding) {
            '', 'UTF8' => self::UTF8,
            'CP1250' => 'WINDOWS-1250',
            'CP1251' => 'WINDOWS-1251',
            'CP1252' => 'WINDOWS-1252',
            'LATIN2' => 'ISO-8859-2',
            'LATIN1' => 'ISO-8859-1',
            default => $encoding,
        };
    }

    /**
     * Convert one value to UTF-8. Null when the bytes are not valid in the declared encoding —
     * which, for a UTF-8 file, is usually a sign it was actually saved as Windows-1250.
     */
    public static function toUtf8(string $value, string $encoding): ?string
    {
        if ($encoding === self::UTF8) {
            return mb_check_encoding($value, 'UTF-8') ? $value : null;
        }

        $converted = @iconv($encoding, 'UTF-8', $value);

        return $converted === false ? null : $converted;
    }

    /**
     * Convert UTF-8 out to the ERP's encoding. A character the target cannot hold (an emoji in a
     * delivery note) becomes its nearest equivalent or `?`, rather than failing the order.
     */
    public static function fromUtf8(string $value, string $encoding): string
    {
        if ($encoding === self::UTF8) {
            return $value;
        }

        $converted = @iconv('UTF-8', $encoding . '//TRANSLIT', $value);

        if ($converted === false) {
            $converted = @iconv('UTF-8', $encoding . '//IGNORE', $value);
        }

        return $converted === false ? $value : $converted;
    }

    public static function isSupported(string $encoding): bool
    {
        return $encoding === self::UTF8 || @iconv($encoding, 'UTF-8', 'a') !== false;
    }

    public static function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * A UTF-16 file has a NUL in every other byte, which no CSV parser can read; it is converted
     * as a whole into a UTF-8 copy before parsing.
     */
    public static function isWide(string $encoding): bool
    {
        return str_starts_with($encoding, 'UTF-16') || str_starts_with($encoding, 'UTF-32');
    }
}
