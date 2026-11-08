<?php

namespace justinholtweb\erpy\files;

use Craft;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A folder on this server — an ERP's export share mounted here, or the inbox the control panel's
 * uploads land in.
 */
class LocalSource extends BaseSource
{
    private string $base;

    /**
     * @param string $directory an absolute path or a Craft alias such as `@storage/erp`
     */
    public function __construct(string $directory, private bool $create = false)
    {
        $directory = trim($directory);

        if ($directory === '') {
            throw FileException::permanent(Craft::t('erpy', 'No folder is set.'));
        }

        $resolved = (string)Craft::getAlias($directory, false) ?: $directory;

        if (!str_starts_with($resolved, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $resolved)) {
            throw FileException::permanent(Craft::t('erpy', 'The folder “{dir}” must be an absolute path or an alias such as @storage/erp.', ['dir' => $directory]));
        }

        if ($this->create && !is_dir($resolved)) {
            @mkdir($resolved, 0775, true);
        }

        $real = realpath($resolved);

        if ($real === false || !is_dir($real)) {
            throw new FileException(Craft::t('erpy', 'The folder {dir} does not exist on this server.', ['dir' => $resolved]));
        }

        $this->base = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function fetch(string $path, ?DateTimeInterface $since = null): ?LocalFile
    {
        $full = $this->locate($path);

        if ($full === null) {
            throw new FileException(Craft::t('erpy', 'There is no {name} in {dir}.', ['name' => self::clean($path), 'dir' => $this->base]));
        }

        if (!is_readable($full)) {
            throw new FileException(Craft::t('erpy', '{name} is there but this server cannot read it.', ['name' => basename($full)]));
        }

        clearstatcache(true, $full);
        $modifiedAt = (new DateTimeImmutable('@' . (int)filemtime($full)))->setTimezone(new DateTimeZone('UTC'));

        if ($since !== null && $modifiedAt < $since) {
            return null;
        }

        return new LocalFile($full, basename($full), $modifiedAt);
    }

    public function exists(string $path): bool
    {
        return is_file($this->inside(self::clean($path)));
    }

    public function write(string $path, string $contents): void
    {
        $target = $this->inside(self::clean($path));
        $dir = dirname($target);

        if (!is_dir($dir) || !is_writable($dir)) {
            throw new FileException(Craft::t('erpy', 'This server cannot write to {dir}.', ['dir' => $dir]));
        }

        $temp = $dir . DIRECTORY_SEPARATOR . basename(self::tempName(basename($target)));

        if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
            throw new FileException(Craft::t('erpy', 'Writing {name} to {dir} failed.', ['name' => basename($target), 'dir' => $dir]));
        }

        // rename() within one folder is atomic: a poller sees no file, then the whole file.
        if (!@rename($temp, $target)) {
            @unlink($temp);

            throw new FileException(Craft::t('erpy', 'Writing {name} to {dir} failed.', ['name' => basename($target), 'dir' => $dir]));
        }
    }

    public function probe(): string
    {
        $count = 0;

        foreach (new \DirectoryIterator($this->base) as $item) {
            if ($item->isFile() && !str_starts_with($item->getFilename(), '.')) {
                $count++;
            }
        }

        return Craft::t('erpy', '{dir} is readable and holds {n, plural, =1{one file} other{# files}}.', ['dir' => $this->base, 'n' => $count]);
    }

    public function describe(): string
    {
        return $this->base;
    }

    public function directory(): string
    {
        return $this->base;
    }

    /**
     * The file a configured name points at — the newest match, for a wildcard.
     */
    private function locate(string $path): ?string
    {
        $path = self::clean($path);

        if (!self::isPattern($path)) {
            $full = $this->inside($path);

            return is_file($full) ? $full : null;
        }

        $relativeDir = dirname($path) === '.' ? '' : dirname($path);
        $dir = $this->inside($relativeDir);
        $candidates = [];

        foreach (is_dir($dir) ? new \DirectoryIterator($dir) : [] as $item) {
            // A symlink in the share could point anywhere; a wildcard only picks real files.
            if ($item->isFile() && !$item->isLink()) {
                $candidates[$item->getFilename()] = $item->getMTime();
            }
        }

        $name = self::newest($candidates, basename($path));

        return $name === null ? null : $this->inside(ltrim($relativeDir . '/' . $name, '/'));
    }

    /**
     * Join a cleaned relative path to the base, and make sure a symlink did not lead out of it.
     */
    private function inside(string $relative): string
    {
        $full = $relative === '' ? $this->base : $this->base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        // The nearest part of the path that exists is what a symlink could redirect; check that,
        // so a missing file below a link that leads out is refused too.
        $existing = $full;

        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $real = realpath($existing);

        if ($real === false || ($real !== $this->base && !str_starts_with($real, $this->base . DIRECTORY_SEPARATOR))) {
            throw FileException::permanent(Craft::t('erpy', '“{path}” leads outside the configured folder.', ['path' => $relative]));
        }

        return $full;
    }
}
