<?php

namespace justinholtweb\erpy\files;

use Craft;

/**
 * What every source shares: keeping a relative path relative.
 */
abstract class BaseSource implements FileSource
{
    /**
     * Normalise a configured file name and refuse anything that could leave the base location.
     *
     * The base folder, URL or server is an endpoint only an admin can set. The file names under
     * it are per-entity settings anyone who manages connections can edit, so they must not be a
     * way to read `/etc/passwd` into a run log or write an order over a PHP file.
     */
    public static function clean(string $path, bool $allowEmpty = false, bool $allowQuery = false): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }

            throw FileException::permanent(Craft::t('erpy', 'No file name is set.'));
        }

        if (str_contains($path, "\0") || str_contains($path, '://') || str_starts_with($path, '//')
            || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw FileException::permanent(Craft::t('erpy', '“{path}” must be a name inside the configured folder, not a full path or address.', ['path' => $path]));
        }

        // Every segment of the whole string, query included, and percent-decoded: an SFTP
        // client resolves `a?/../../etc/passwd` as text, and some web servers decode `%2e%2e`.
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '..') {
                throw FileException::permanent(Craft::t('erpy', '“{path}” may not climb out of the configured folder.', ['path' => $path]));
            }
        }

        $query = '';

        if (str_contains($path, '?')) {
            // Only a URL has a query string; on a folder or a server `?` is part of nothing.
            if (!$allowQuery) {
                throw FileException::permanent(Craft::t('erpy', '“{path}” must be a plain file name.', ['path' => $path]));
            }

            $query = substr($path, strpos($path, '?'));
            $path = substr($path, 0, strpos($path, '?'));
        }

        $segments = array_filter(explode('/', $path), static fn(string $segment) => $segment !== '' && $segment !== '.');

        return implode('/', $segments) . $query;
    }

    /** Whether a name is a wildcard (`stock_*.csv`) rather than one file. */
    public static function isPattern(string $path): bool
    {
        return str_contains(basename(str_replace('\\', '/', $path)), '*');
    }

    /**
     * The newest of several candidates, given `name => modified timestamp`.
     */
    protected static function newest(array $candidates, string $pattern): ?string
    {
        $best = null;
        $bestTime = PHP_INT_MIN;

        foreach ($candidates as $name => $time) {
            $name = (string)$name;

            if (str_starts_with($name, '.') || !fnmatch($pattern, $name)) {
                continue;
            }

            // Ties go to the later name, so `stock_20261103.csv` beats `stock_20261102.csv`
            // written in the same second.
            if ($time > $bestTime || ($time === $bestTime && strcmp($name, (string)$best) > 0)) {
                $best = $name;
                $bestTime = $time;
            }
        }

        return $best;
    }

    protected static function tempName(string $path): string
    {
        $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';

        return $dir . '.erpy-' . bin2hex(random_bytes(6)) . '.tmp';
    }
}
