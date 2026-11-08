<?php

namespace justinholtweb\erpy\base;

use Craft;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\erpy\models\Connection;
use Throwable;

/**
 * The export URL's settings and the rules for reading them, shared by every connector that
 * implements `ServesOrders`. A connector adds `OrderExport::fields()` to its `settingsFields()`
 * and gets the same token, URL-token switch, allowed addresses and date format as the built-in
 * file connector, read by the same code in `ExportController`.
 */
abstract class OrderExport
{
    /** Dates an ERP may put in a URL where `new DateTime()` would misread them. */
    private const FALLBACK_FORMATS = [
        // `2026-11-03-00-00`: `new DateTime()` reads the trailing `-00` as a UTC offset.
        '/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}$/' => 'Y-m-d-H-i',
        '/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/' => 'Y-m-d-H-i-s',
    ];

    /**
     * The settings a served export needs, for a connector's `settingsFields()`.
     */
    public static function fields(): array
    {
        return [
            Field::secret('exportToken', Craft::t('erpy', 'Export token'), [
                'instructions' => Craft::t('erpy', 'What the ERP sends to fetch orders: as an `X-Erpy-Token` header, or as the password of a basic-auth login. Use a long random value.'),
            ]),
            Field::boolean('exportTokenInUrl', Craft::t('erpy', 'Also accept the token in the URL'), [
                'instructions' => Craft::t('erpy', 'As `?key=…`, only for an ERP that can fetch nothing but a plain address. A token in a URL ends up in server logs.'),
            ]),
            Field::text('exportAllowedIps', Craft::t('erpy', 'Allowed IP addresses'), [
                'instructions' => Craft::t('erpy', 'Optional. A request from one of these addresses may fetch orders without the token. Separate them with commas; `203.0.113.0/24` covers a range. Behind a proxy or CDN this needs Craft’s `trustedHosts` set to that proxy, or Erpy only sees the proxy’s address.'),
                'placeholder' => '203.0.113.7, 198.51.100.0/24',
            ]),
            Field::text('exportDateFormat', Craft::t('erpy', 'Date format in the URL'), [
                'instructions' => Craft::t('erpy', 'Optional. How the ERP writes `from` and `to`, in [PHP date format](https://www.php.net/manual/en/datetime.format.php), e.g. `Y-m-d-H-i` for `2026-11-03-14-30`. Dates are read in the site’s timezone.'),
                'placeholder' => 'Y-m-d-H-i',
                'suggestEnvVars' => false,
            ]),
            Field::copyable('exportUrl', Craft::t('erpy', 'Export URL'), self::urlTemplate(), [
                'instructions' => Craft::t('erpy', 'Add `?from=2026-11-01&to=2026-11-03` to fetch a date range again, or `?all=1` for every order. Without them, only orders not yet fetched.'),
            ]),
        ];
    }

    public static function urlTemplate(): string
    {
        try {
            return UrlHelper::siteUrl('erpy/export/{handle}/orders');
        } catch (Throwable) {
            return '/erpy/export/{handle}/orders';
        }
    }

    public static function url(Connection $connection): string
    {
        return str_replace('{handle}', (string)$connection->handle, self::urlTemplate());
    }

    /**
     * Whether a fetch can be authorised at all: a token, or at least one allowed address.
     */
    public static function isConfigured(Connection $connection): bool
    {
        return (string)$connection->getSetting('exportToken', '') !== '' || self::allowedIps($connection) !== [];
    }

    /**
     * The allowed addresses and ranges that are well-formed. A typo is dropped rather than
     * matched loosely: an entry that is not an address must never let anybody in.
     *
     * @return string[]
     */
    public static function allowedIps(Connection $connection): array
    {
        $entries = preg_split('/[\s,;]+/', (string)$connection->getSetting('exportAllowedIps', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($entries, static fn(string $entry) => self::parseRange($entry) !== null));
    }

    /**
     * Whether `$ip` is one of the allowed addresses or inside one of the allowed ranges.
     *
     * @param string[] $allowed
     */
    public static function ipAllowed(?string $ip, array $allowed): bool
    {
        $address = $ip !== null ? self::pack($ip) : null;

        if ($address === null) {
            return false;
        }

        foreach ($allowed as $entry) {
            $range = self::parseRange($entry);

            // Different families never match; an IPv4 address is not inside an IPv6 range.
            if ($range === null || strlen($range[0]) !== strlen($address)) {
                continue;
            }

            [$network, $bits] = $range;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;

            if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }

            if ($rest === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;

            if ((ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `from` / `to` value, in the site's timezone. Tries the connection's own format first,
     * then the dashed date-times `new DateTime()` misreads, then anything `new DateTime()` reads.
     * A date-only value read as `to` means the end of that day.
     *
     * @throws \InvalidArgumentException when it is not a date
     */
    public static function parseDate(string $value, string $format = '', bool $endOfDay = false): DateTime
    {
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());
        $formats = [];

        if (trim($format) !== '') {
            $formats[] = trim($format);
        }

        foreach (self::FALLBACK_FORMATS as $pattern => $fallback) {
            if (preg_match($pattern, $value)) {
                $formats[] = $fallback;
            }
        }

        foreach ($formats as $candidate) {
            // `!` zeroes whatever the format leaves out, so a date-only format is midnight.
            $date = DateTime::createFromFormat('!' . $candidate, $value, $timezone);
            $errors = DateTime::getLastErrors();

            // A rolled-over value (`2026-02-31`) is a warning, not an error; refuse it as well.
            if ($date !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                if ($endOfDay && !preg_match('/(?<!\\\\)[aABgGhHisuvU]/', $candidate)) {
                    $date->setTime(23, 59, 59);
                }

                return $date;
            }
        }

        try {
            $date = new DateTime($value, $timezone);
        } catch (Throwable) {
            throw new \InvalidArgumentException("“{$value}” is not a date.");
        }

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date->setTime(23, 59, 59);
        }

        return $date;
    }

    /**
     * @return array{0:string,1:int}|null the packed network address and its prefix length
     */
    private static function parseRange(string $entry): ?array
    {
        [$ip, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);
        $network = self::pack((string)$ip);

        if ($network === null) {
            return null;
        }

        $max = strlen($network) * 8;

        if ($bits === null) {
            return [$network, $max];
        }

        // `0.0.0.0/0` and its kin would switch the token off for the whole internet. A range that
        // wide is a mistake, so it is dropped like any other entry that is not an address.
        if (!ctype_digit($bits) || (int)$bits > $max || (int)$bits < $max / 4) {
            return null;
        }

        return [$network, (int)$bits];
    }

    /**
     * An address as packed bytes, with an IPv4-mapped IPv6 address (`::ffff:203.0.113.7`, what a
     * dual-stack server reports for an IPv4 client) unwrapped to plain IPv4.
     */
    private static function pack(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
