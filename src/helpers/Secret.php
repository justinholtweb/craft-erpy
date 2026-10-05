<?php

namespace justinholtweb\erpy\helpers;

use Craft;

/**
 * Credentials at rest: OAuth tokens and literal (non-`$ENV`) secrets on a connection row.
 *
 * Stored as `enc:` + base64 of Craft's `encryptByKey()`, so a database dump or a leaked backup no
 * longer hands over live ERP refresh tokens. The prefix is what tells an encrypted value from one
 * written before 5.1.1, which still reads — and is encrypted the next time it is saved.
 *
 * Base64 because `encryptByKey()` returns raw binary, and a utf8mb4 `text` column rejects it.
 */
abstract class Secret
{
    public const PREFIX = 'enc:';

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function encrypt(string $plain): string
    {
        return self::PREFIX . base64_encode(Craft::$app->getSecurity()->encryptByKey($plain));
    }

    /**
     * The plain value, or null when it can't be decrypted — the security key changed, which means
     * entering the credential again rather than a fatal error.
     */
    public static function decrypt(string $value): ?string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false) {
            return null;
        }

        try {
            $plain = Craft::$app->getSecurity()->decryptByKey($raw);
        } catch (\Throwable $e) {
            Craft::warning('Erpy could not decrypt a stored credential: ' . $e->getMessage(), 'erpy');

            return null;
        }

        return $plain === false ? null : $plain;
    }

    /** A token bag for the `tokens` column. */
    public static function encodeTokens(array $tokens): string
    {
        return $tokens === [] ? '[]' : self::encrypt(json_encode($tokens));
    }

    /** The `tokens` column back into a bag, whichever form it was written in. */
    public static function decodeTokens(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        $json = self::decrypt($stored);

        return is_string($json) ? (json_decode($json, true) ?: []) : [];
    }

    /** A secret setting as it should be stored: `$ENV` references as they are, literals encrypted. */
    public static function protectSetting(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '$') || self::isEncrypted($value)) {
            return $value;
        }

        return self::encrypt($value);
    }
}
